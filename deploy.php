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

// ---------------------------------------------------------------------------
// Project
// ---------------------------------------------------------------------------

set('application', 'claudriel');
set('keep_releases', 5);
set('allow_anonymous_stats', false);

// ---------------------------------------------------------------------------
// Shared filesystem
// ---------------------------------------------------------------------------

set('shared_files', ['.env', 'waaseyaa.sqlite']);
set('shared_dirs', ['context', 'storage']);

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

desc('Reload Caddy to pick up config changes');
task('caddy:reload', function (): void {
    run('sudo systemctl reload caddy || true');
});

desc('Reload PHP-FPM to pick up new release');
task('php-fpm:reload', function (): void {
    run('sudo systemctl reload php8.4-fpm');
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
    'deploy:shared',
    'deploy:copy_caddyfile',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
    'caddy:reload',
    'php-fpm:reload',
]);

after('deploy:failed', 'deploy:unlock');
