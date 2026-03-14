<?php

/**
 * PHP Deployer configuration for claudriel.northcloud.one
 *
 * Deployment strategy: artifact upload
 * Composer uses path repositories (../waaseyaa/packages/*), so vendor is
 * pre-built in CI and uploaded — the server never runs composer directly.
 *
 * Usage:
 *   dep deploy production           # Full deploy
 *   dep rollback production          # Roll back to previous release
 *   dep deploy:unlock production     # Unlock if deploy was interrupted
 */

namespace Deployer;

require 'recipe/common.php';
require_once __DIR__.'/vendor/autoload.php';

use Claudriel\Support\PublicAccountDeployValidationScript;

// ---------------------------------------------------------------------------
// Project
// ---------------------------------------------------------------------------

set('application', 'claudriel');
set('keep_releases', 5);
set('allow_anonymous_stats', false);
set('repository', 'git@github.com:jonesrussell/claudriel.git');
set('deploy_validation_base_url', 'https://claudriel.northcloud.one');

// ---------------------------------------------------------------------------
// Shared filesystem
// ---------------------------------------------------------------------------

set('shared_files', ['.env', 'waaseyaa.sqlite']);
set('shared_dirs', ['storage', 'logs']);
set('writable_dirs', ['storage', 'logs', 'cache']);

// ---------------------------------------------------------------------------
// Hosts
// ---------------------------------------------------------------------------

host('production')
    ->setHostname('claudriel.northcloud.one')
    ->set('remote_user', 'deployer')
    ->set('deploy_path', '/home/deployer/claudriel')
    ->set('labels', ['stage' => 'production']);

// ---------------------------------------------------------------------------
// Tasks
// ---------------------------------------------------------------------------

desc('Upload pre-built release artifact from CI');
task('deploy:upload', function (): void {
    upload('.build/', '{{release_path}}/', [
        'options' => ['--recursive', '--compress'],
    ]);
});

desc('Copy Caddyfile to deploy root');
task('deploy:copy_caddyfile', function (): void {
    run('cp {{release_path}}/Caddyfile {{deploy_path}}/Caddyfile');
});

desc('Ensure sidecar directory exists');
task('deploy:sidecar_dir', function (): void {
    run('mkdir -p {{deploy_path}}/sidecar');
});

desc('Ensure shared runtime directories exist');
task('deploy:runtime_dirs', function (): void {
    run('mkdir -p {{deploy_path}}/shared/storage');
    run('mkdir -p {{deploy_path}}/shared/logs');
    run('touch {{deploy_path}}/shared/waaseyaa.sqlite');
    run('mkdir -p {{release_path}}/cache');
});

desc('Reload Caddy to pick up config changes');
task('caddy:reload', function (): void {
    run('sudo systemctl reload caddy || true');
});

desc('Reload PHP-FPM to pick up new release');
task('php-fpm:reload', function (): void {
    run('sudo systemctl reload php8.4-fpm');
});

desc('Deploy sidecar container');
task('sidecar:deploy', function (): void {
    run('mkdir -p {{deploy_path}}/sidecar');
    run('cp {{release_path}}/docker-compose.sidecar.yml {{deploy_path}}/sidecar/');
    run('rm -rf {{deploy_path}}/sidecar/docker-context');
    run('cp -r {{release_path}}/docker/sidecar {{deploy_path}}/sidecar/docker-context');
    run('grep -E "^(CLAUDRIEL_|ANTHROPIC_|CLAUDE_|SIDECAR_|GITHUB_)" {{deploy_path}}/shared/.env > {{deploy_path}}/sidecar/.env || true');
    run('cd {{deploy_path}}/sidecar && docker compose -f docker-compose.sidecar.yml --env-file .env up -d --build');
});

desc('Validate sidecar health and app smoke probes');
task('deploy:validate', function (): void {
    $baseUrl = rtrim((string) get('deploy_validation_base_url'), '/');
    $briefJsonFile = '{{deploy_path}}/shared/logs/deploy-validation-brief.json';
    $sidecarHealthFile = '{{deploy_path}}/shared/logs/deploy-validation-sidecar-health.json';

    writeln('Validating deployed sidecar health');
    run(<<<BASH
for attempt in 1 2 3 4 5 6 7 8 9 10; do
  if curl --silent --show-error --fail http://127.0.0.1:8100/health > {$sidecarHealthFile}; then
    exit 0
  fi
  sleep 2
done
echo 'Sidecar health endpoint did not become healthy in time' >&2
exit 1
BASH);
    run("grep -q '\"status\":\"ok\"' {$sidecarHealthFile}");

    try {
        run("for attempt in 1 2 3 4 5; do curl --silent --show-error --fail {$baseUrl}/brief > /dev/null && exit 0; sleep 1; done; echo 'Public Caddy endpoint did not become healthy in time' >&2; exit 1");

        writeln('Running public brief JSON smoke probe');
        run("curl --silent --show-error --fail --header 'Accept: application/json' {$baseUrl}/brief > {$briefJsonFile}");
        run("grep -q '\"counts\"' {$briefJsonFile}");

        writeln('Running public signup and login probes');
        run((new PublicAccountDeployValidationScript)->build($baseUrl));

        writeln('Running public chat SSE smoke probe');
        run(strtr(<<<'BASH'
chat_send_file=$(mktemp)
chat_stream_file=$(mktemp)
trap 'rm -f "$chat_send_file" "$chat_stream_file"' EXIT

curl --silent --show-error --fail \
  --header 'Content-Type: application/json' \
  --data '{"message":"delete workspace deploy-validation-smoke"}' \
  __BASE_URL__/api/chat/send > "$chat_send_file"

message_id=$(php -r '$payload = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); if (!isset($payload["message_id"])) { fwrite(STDERR, "Missing message_id\n"); exit(1);} echo $payload["message_id"];' "$chat_send_file")

curl --silent --show-error --fail --max-time 20 \
  "__BASE_URL__/stream/chat/${message_id}" > "$chat_stream_file"

grep -q 'event: chat-done' "$chat_stream_file"
grep -q 'Could not find "deploy-validation-smoke"' "$chat_stream_file"
BASH, ['__BASE_URL__' => $baseUrl]));

        writeln('Deploy validation passed');
    } catch (\Throwable $exception) {
        writeln('Deploy validation failed; captured validation artifacts remain under {{deploy_path}}/shared/logs');
        throw $exception;
    }
});

// ---------------------------------------------------------------------------
// Deploy flow
// ---------------------------------------------------------------------------

desc('Deploy Claudriel to production');
task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:sidecar_dir',
    'deploy:lock',
    'deploy:release',
    'deploy:upload',
    'deploy:runtime_dirs',
    'deploy:shared',
    'deploy:writable',
    'deploy:copy_caddyfile',
    'deploy:symlink',
    'sidecar:deploy',
    'caddy:reload',
    'php-fpm:reload',
    'deploy:validate',
    'deploy:unlock',
    'deploy:cleanup',
]);

after('deploy:failed', 'deploy:unlock');
