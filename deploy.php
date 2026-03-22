<?php

/**
 * PHP Deployer configuration for Claudriel.
 *
 * Deployment strategy: artifact upload
 * Composer uses path repositories (../waaseyaa/packages/*), so vendor is
 * pre-built in CI and uploaded — the server never runs composer directly.
 *
 * Environments:
 *   staging    → claudriel.northcloud.one  (/home/deployer/claudriel)
 *   production → claudriel.ai              (/home/deployer/claudriel-prod)
 *
 * Usage:
 *   dep deploy staging               # Deploy to staging
 *   dep deploy production             # Deploy to production
 *   dep rollback production           # Roll back to previous release
 *   dep deploy:unlock production      # Unlock if deploy was interrupted
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

host('staging')
    ->setHostname('claudriel.northcloud.one')
    ->set('remote_user', 'deployer')
    ->set('deploy_path', '/home/deployer/claudriel')
    ->set('deploy_validation_base_url', 'https://claudriel.northcloud.one')
    ->set('labels', ['stage' => 'staging']);

host('production')
    ->setHostname('147.182.150.145')
    ->set('remote_user', 'deployer')
    ->set('deploy_path', '/home/deployer/claudriel-prod')
    ->set('deploy_validation_base_url', 'https://claudriel.ai')
    ->set('labels', ['stage' => 'production']);

// ---------------------------------------------------------------------------
// Tasks
// ---------------------------------------------------------------------------

desc('Upload pre-built release artifact from CI');
task('deploy:upload', function (): void {
    upload('build/', '{{release_path}}/', [
        'options' => ['--recursive', '--compress'],
    ]);
});

desc('Copy Caddyfile to deploy root with environment substitution');
task('deploy:copy_caddyfile', function (): void {
    $baseUrl = get('deploy_validation_base_url');
    $domain = (string) parse_url($baseUrl, PHP_URL_HOST);
    $deployPath = get('deploy_path');

    run("sed -e 's|__CADDY_DOMAIN__|{$domain}|g' -e 's|__DEPLOY_PATH__|{$deployPath}|g' {{release_path}}/Caddyfile > {{deploy_path}}/Caddyfile");
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

desc('Clear stale framework caches from shared storage');
task('deploy:clear_cache', function (): void {
    // The cache file may be owned by the web server user. If rm fails,
    // write invalid content so PHP's require returns non-array and the
    // framework recompiles the manifest on next request.
    run('rm -f {{deploy_path}}/shared/storage/framework/packages.php 2>/dev/null || echo "INVALIDATED" > {{deploy_path}}/shared/storage/framework/packages.php 2>/dev/null || true');
});

desc('Build agent Docker image');
task('agent:setup', function (): void {
    run('cd {{release_path}}/agent && docker build -t claudriel-agent .');
});

desc('Validate app smoke probes');
task('deploy:validate', function (): void {
    $baseUrl = rtrim((string) get('deploy_validation_base_url'), '/');
    $briefJsonFile = '{{deploy_path}}/shared/logs/deploy-validation-brief.json';

    // Build curl flags: always use timeouts; allow TLS bypass on cert errors
    $curlBase = '--silent --show-error --connect-timeout 10 --max-time 30';

    try {
        // Health check: try secure first, fall back to --insecure on TLS errors (exit 35/60)
        run("for attempt in 1 2 3 4 5; do curl {$curlBase} --fail {$baseUrl}/brief > /dev/null 2>&1 && exit 0; rc=\$?; if [ \$rc -eq 35 ] || [ \$rc -eq 60 ]; then echo 'TLS verification failed, retrying with --insecure' >&2; curl {$curlBase} --insecure --fail {$baseUrl}/brief > /dev/null 2>&1 && exit 0; fi; sleep 1; done; echo 'Public Caddy endpoint did not become healthy in time' >&2; exit 1");

        // Detect whether --insecure is needed for remaining probes
        $secureFailed = false;

        try {
            run("curl {$curlBase} --fail {$baseUrl}/brief > /dev/null 2>&1");
        } catch (\Throwable) {
            $secureFailed = true;
            writeln('<comment>Warning: TLS verification failed; using --insecure for remaining probes</comment>');
        }

        $curlFlags = $secureFailed ? "{$curlBase} --insecure" : $curlBase;

        writeln('Running public brief JSON smoke probe');
        run("curl {$curlFlags} --fail --header 'Accept: application/json' {$baseUrl}/brief > {$briefJsonFile}");
        run("grep -q '\"counts\"' {$briefJsonFile}");

        writeln('Running public signup and login probes');
        run((new PublicAccountDeployValidationScript)->build($baseUrl, $secureFailed));

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
    'deploy:lock',
    'deploy:release',
    'deploy:upload',
    'deploy:runtime_dirs',
    'deploy:shared',
    'deploy:writable',
    'deploy:copy_caddyfile',
    'deploy:symlink',
    'deploy:clear_cache',
    'agent:setup',
    'caddy:reload',
    'php-fpm:reload',
    'deploy:validate',
    'deploy:unlock',
    'deploy:cleanup',
]);

after('deploy:failed', 'deploy:unlock');
