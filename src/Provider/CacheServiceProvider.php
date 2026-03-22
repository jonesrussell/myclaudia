<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Cache\CacheTagsInvalidator;
use Waaseyaa\Cache\Listener\EntityCacheInvalidator;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class CacheServiceProvider extends ServiceProvider
{
    private ?CacheFactory $cacheFactory = null;

    private ?CacheTagsInvalidator $cacheTagsInvalidator = null;

    public function register(): void
    {
        // EntityCacheInvalidator wiring requires EventDispatcher access.
        // Wire in ClaudrielServiceProvider where dispatcher is available.
    }

    public function getCacheFactory(): CacheFactory
    {
        if ($this->cacheFactory === null) {
            $pdo = $this->createPdo();
            $config = new CacheConfiguration(
                defaultBackend: DatabaseBackend::class,
            );
            $config->setFactoryForBin('brief', fn () => new DatabaseBackend($pdo, 'cache_brief'));
            $config->setFactoryForBin('entities', fn () => new DatabaseBackend($pdo, 'cache_entities'));

            $this->cacheFactory = new CacheFactory($config);
        }

        return $this->cacheFactory;
    }

    public function getCacheTagsInvalidator(): CacheTagsInvalidator
    {
        if ($this->cacheTagsInvalidator === null) {
            $factory = $this->getCacheFactory();
            $this->cacheTagsInvalidator = new CacheTagsInvalidator;
            $this->cacheTagsInvalidator->registerBin('brief', $factory->get('brief'));
            $this->cacheTagsInvalidator->registerBin('entities', $factory->get('entities'));
        }

        return $this->cacheTagsInvalidator;
    }

    public function getEntityCacheInvalidator(): EntityCacheInvalidator
    {
        return new EntityCacheInvalidator($this->getCacheTagsInvalidator());
    }

    private function createPdo(): \PDO
    {
        $storageDir = dirname(__DIR__, 2).'/storage';
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0o755, true);
        }

        return new \PDO('sqlite:'.$storageDir.'/cache.sqlite');
    }
}
