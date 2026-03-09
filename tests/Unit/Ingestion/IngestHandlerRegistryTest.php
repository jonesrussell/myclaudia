<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ingestion;

use Claudriel\Ingestion\IngestHandlerInterface;
use Claudriel\Ingestion\IngestHandlerRegistry;
use PHPUnit\Framework\TestCase;

final class IngestHandlerRegistryTest extends TestCase
{
    public function testFallsBackToDefaultHandlerForUnknownType(): void
    {
        $fallback = $this->createMockHandler(true, ['status' => 'fallback']);
        $registry = new IngestHandlerRegistry($fallback);

        $result = $registry->handle([
            'source'  => 'test',
            'type'    => 'unknown.type',
            'payload' => [],
        ]);

        self::assertSame('fallback', $result['status']);
    }

    public function testRoutesToMatchingHandler(): void
    {
        $fallback = $this->createMockHandler(true, ['status' => 'fallback']);
        $registry = new IngestHandlerRegistry($fallback);

        $specific = new class implements IngestHandlerInterface {
            public function supports(string $type): bool
            {
                return $type === 'test.event';
            }

            public function handle(array $data): array
            {
                return ['status' => 'specific', 'type' => $data['type']];
            }
        };

        $registry->addHandler($specific);

        $result = $registry->handle([
            'source'  => 'test',
            'type'    => 'test.event',
            'payload' => [],
        ]);

        self::assertSame('specific', $result['status']);
        self::assertSame('test.event', $result['type']);
    }

    private function createMockHandler(bool $supports, array $result): IngestHandlerInterface
    {
        return new class($supports, $result) implements IngestHandlerInterface {
            public function __construct(
                private readonly bool $doesSupport,
                private readonly array $result,
            ) {}

            public function supports(string $type): bool
            {
                return $this->doesSupport;
            }

            public function handle(array $data): array
            {
                return $this->result;
            }
        };
    }
}
