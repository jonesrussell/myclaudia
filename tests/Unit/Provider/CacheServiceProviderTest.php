<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Cache\CacheTagsInvalidator;
use Waaseyaa\Cache\Listener\EntityCacheInvalidator;
use Waaseyaa\Cache\TagAwareCacheInterface;

final class CacheServiceProviderTest extends TestCase
{
    public function test_provides_cache_factory_with_brief_bin(): void
    {
        $config = new CacheConfiguration(
            defaultBackend: MemoryBackend::class,
        );
        $config->setFactoryForBin('brief', fn () => new MemoryBackend);
        $config->setFactoryForBin('entities', fn () => new MemoryBackend);

        $factory = new CacheFactory($config);
        $briefBin = $factory->get('brief');

        $this->assertInstanceOf(TagAwareCacheInterface::class, $briefBin);
    }

    public function test_cache_set_and_get(): void
    {
        $config = new CacheConfiguration(
            defaultBackend: MemoryBackend::class,
        );
        $config->setFactoryForBin('brief', fn () => new MemoryBackend);

        $factory = new CacheFactory($config);
        $bin = $factory->get('brief');

        $bin->set('test_key', ['hello' => 'world'], tags: ['tag_a']);
        $item = $bin->get('test_key');

        $this->assertNotFalse($item);
        $this->assertSame(['hello' => 'world'], $item->data);
    }

    public function test_tag_invalidation_clears_tagged_items(): void
    {
        $briefBackend = new MemoryBackend;
        $config = new CacheConfiguration(
            defaultBackend: MemoryBackend::class,
        );
        $config->setFactoryForBin('brief', fn () => $briefBackend);

        $factory = new CacheFactory($config);
        $bin = $factory->get('brief');

        $invalidator = new CacheTagsInvalidator;
        $invalidator->registerBin('brief', $bin);

        $bin->set('item1', 'data1', tags: ['entity:commitment']);
        $bin->set('item2', 'data2', tags: ['entity:person']);

        $invalidator->invalidateTags(['entity:commitment']);

        $result1 = $bin->get('item1');
        $result2 = $bin->get('item2');

        // After tag invalidation, item1 should be invalid (false or CacheItem with valid=false)
        if ($result1 === false) {
            $this->assertFalse($result1);
        } else {
            $this->assertFalse($result1->valid);
        }

        // item2 should still be valid
        $this->assertNotFalse($result2);
        if ($result2 !== false) {
            $this->assertSame('data2', $result2->data);
        }
    }

    public function test_entity_cache_invalidator_creates_correctly(): void
    {
        $invalidator = new CacheTagsInvalidator;
        $entityInvalidator = new EntityCacheInvalidator($invalidator);

        $this->assertInstanceOf(EntityCacheInvalidator::class, $entityInvalidator);
    }
}
