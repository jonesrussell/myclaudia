<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Middleware\TelescopeRequestMiddleware;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;
use Waaseyaa\Telescope\TelescopeServiceProvider as WaaseyaaTelescopeServiceProvider;

final class TelescopeServiceProvider extends ServiceProvider
{
    private ?WaaseyaaTelescopeServiceProvider $telescope = null;

    public function register(): void
    {
        // Telescope is configured lazily via getTelescope(), which acts as a
        // singleton accessor. Consumers (middleware, commands) obtain the instance
        // through the concrete TelescopeServiceProvider rather than the DI container.
        //
        // TODO(#478): Register WaaseyaaTelescopeServiceProvider in the service
        // resolver once Waaseyaa exposes a generic set() method, so consumers can
        // type-hint the interface instead of depending on this concrete provider.
        //
        // Query recording: waaseyaa/database-legacy has no query event hooks.
        // Event recording: entity events use Symfony EventDispatcher but providers
        // lack a registration point for generic listeners. Wire QueryRecorder and
        // EventRecorder here when those framework hooks land.
        //
        // CacheRecorder: waaseyaa/telescope provides CacheRecorder with
        // recordHit/recordMiss/recordSet/recordForget methods. However,
        // waaseyaa/cache does not emit events on cache operations (it only has
        // invalidation listeners). CacheRecorder wiring is BLOCKED until
        // waaseyaa/cache emits operation events or app-level cache decorators
        // (e.g. CachedDayBriefAssembler) are instrumented to call CacheRecorder
        // directly.
    }

    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        return [
            new TelescopeRequestMiddleware($this->getTelescope()),
        ];
    }

    public function getTelescope(): WaaseyaaTelescopeServiceProvider
    {
        if ($this->telescope === null) {
            $storagePath = $this->getStoragePath();
            if ($storagePath === null) {
                error_log('Telescope: could not create storage/ directory, falling back to in-memory store');
            }

            $store = $storagePath !== null
                ? SqliteTelescopeStore::createFromPath($storagePath)
                : SqliteTelescopeStore::createInMemory();

            $raw = $_ENV['TELESCOPE_ENABLED'] ?? (getenv('TELESCOPE_ENABLED') ?: null) ?? 'true';
            $enabled = $raw !== 'false';

            $this->telescope = new WaaseyaaTelescopeServiceProvider(
                config: [
                    'enabled' => $enabled,
                    'record' => [
                        'queries' => true,
                        'events' => true,
                        'requests' => true,
                        'cache' => true,
                        'slow_query_threshold' => 100.0,
                        'slow_queries_only' => false,
                    ],
                    'ignore_paths' => ['/health', '/api/broadcast/*', '/favicon.ico'],
                ],
                store: $store,
            );
        }

        return $this->telescope;
    }

    private function getStoragePath(): ?string
    {
        $storageDir = dirname(__DIR__, 2).'/storage';
        if (is_dir($storageDir) || mkdir($storageDir, 0o755, true)) {
            return $storageDir.'/telescope.sqlite';
        }

        error_log('Telescope: could not create storage/ directory, falling back to in-memory store');

        return null;
    }
}
