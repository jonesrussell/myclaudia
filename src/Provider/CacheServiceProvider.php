<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Cache\CacheTagsInvalidator;
use Waaseyaa\Cache\Listener\EntityCacheInvalidator;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class CacheServiceProvider extends ServiceProvider
{
    private ?CacheFactory $cacheFactory = null;

    private ?CacheTagsInvalidator $cacheTagsInvalidator = null;

    private ?EntityCacheInvalidator $entityCacheInvalidator = null;

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

    public function wireInvalidator(EventDispatcherInterface $dispatcher): void
    {
        $invalidator = $this->getEntityCacheInvalidator();

        $dispatcher->addListener(
            EntityEvents::POST_SAVE->value,
            [$invalidator, 'onPostSave'],
        );

        $dispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            [$invalidator, 'onPostDelete'],
        );
    }

    public function getEntityCacheInvalidator(): EntityCacheInvalidator
    {
        if ($this->entityCacheInvalidator === null) {
            $this->entityCacheInvalidator = new EntityCacheInvalidator($this->getCacheTagsInvalidator());
        }

        return $this->entityCacheInvalidator;
    }

    private function createPdo(): \PDO
    {
        $storageDir = dirname(__DIR__, 2).'/storage';
        if (! is_dir($storageDir) && ! mkdir($storageDir, 0o755, true)) {
            error_log('Cache: could not create storage/ directory, falling back to in-memory');

            return new \PDO('sqlite::memory:');
        }

        $pdo = new \PDO('sqlite:'.$storageDir.'/cache.sqlite');
        $pdo->exec('PRAGMA journal_mode=WAL');

        return $pdo;
    }
}
