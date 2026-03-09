<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ingestion;

use Claudriel\Entity\Skill;
use Claudriel\Ingestion\SkillFileIngester;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SkillFileIngesterTest extends TestCase
{
    private EntityRepository $repo;
    private SkillFileIngester $ingester;

    protected function setUp(): void
    {
        $this->repo = new EntityRepository(
            new EntityType(id: 'skill', label: 'Skill', class: Skill::class, keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
        $this->ingester = new SkillFileIngester($this->repo);
    }

    public function testParseFrontMatterAndBody(): void
    {
        $content = <<<'MD'
---
name: brainstorming
description: Explore ideas before building
trigger_keywords: design, plan, brainstorm
---
Body content here with **markdown**.
MD;

        $result = $this->ingester->parseFrontMatter($content);

        self::assertNotNull($result);
        self::assertSame('brainstorming', $result[0]['name']);
        self::assertSame('Explore ideas before building', $result[0]['description']);
        self::assertSame('design, plan, brainstorm', $result[0]['trigger_keywords']);
        self::assertStringContainsString('Body content here', $result[1]);
    }

    public function testParseFrontMatterReturnsNullWithoutDelimiters(): void
    {
        $result = $this->ingester->parseFrontMatter('Just plain markdown content.');
        self::assertNull($result);
    }

    public function testIngestDirectory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/myclaudia_skill_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        file_put_contents($tmpDir . '/brainstorming.md', <<<'MD'
---
name: brainstorming
description: Explore ideas
trigger_keywords: design, plan
---
Brainstorm body.
MD);

        file_put_contents($tmpDir . '/debugging.md', <<<'MD'
---
name: debugging
description: Debug issues
trigger_keywords: bug, error, fix
---
Debug body.
MD);

        try {
            $skills = $this->ingester->ingestDirectory($tmpDir);

            self::assertCount(2, $skills);

            $names = array_map(fn ($s) => $s->get('name'), $skills);
            sort($names);
            self::assertSame(['brainstorming', 'debugging'], $names);

            // Verify they are persisted in the repository.
            $all = $this->repo->findBy([]);
            self::assertCount(2, $all);
        } finally {
            array_map('unlink', glob($tmpDir . '/*'));
            rmdir($tmpDir);
        }
    }

    public function testIngestDirectoryThrowsForMissingDir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ingester->ingestDirectory('/nonexistent/path/that/does/not/exist');
    }
}
