<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\SkillsCommand;
use Claudriel\Entity\Skill;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SkillsCommandTest extends TestCase
{
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new EntityRepository(
            new EntityType(id: 'skill', label: 'Skill', class: Skill::class, keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
    }

    public function testNoSkillsOutputsMessage(): void
    {
        $tester = new CommandTester(new SkillsCommand($this->repo));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No skills found', $tester->getDisplay());
    }

    public function testListsAllSkills(): void
    {
        $skill = new Skill([
            'name' => 'brainstorming',
            'description' => 'Explore ideas',
            'trigger_keywords' => 'design, plan',
        ]);
        $this->repo->save($skill);

        $tester = new CommandTester(new SkillsCommand($this->repo));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('brainstorming', $display);
        self::assertStringContainsString('Explore ideas', $display);
        self::assertStringContainsString('design, plan', $display);
    }

    public function testFilterByKeyword(): void
    {
        $skill1 = new Skill([
            'name' => 'brainstorming',
            'description' => 'Explore ideas',
            'trigger_keywords' => 'design, plan',
        ]);
        $skill2 = new Skill([
            'name' => 'debugging',
            'description' => 'Fix bugs',
            'trigger_keywords' => 'bug, error',
        ]);
        $this->repo->save($skill1);
        $this->repo->save($skill2);

        $tester = new CommandTester(new SkillsCommand($this->repo));
        $tester->execute(['--keyword' => 'bug']);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('debugging', $display);
        self::assertStringNotContainsString('brainstorming', $display);
    }

    public function testKeywordFilterNoMatch(): void
    {
        $skill = new Skill([
            'name' => 'brainstorming',
            'description' => 'Explore ideas',
            'trigger_keywords' => 'design, plan',
        ]);
        $this->repo->save($skill);

        $tester = new CommandTester(new SkillsCommand($this->repo));
        $tester->execute(['--keyword' => 'nonexistent']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No skills found', $tester->getDisplay());
    }
}
