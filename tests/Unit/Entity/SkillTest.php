<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\Skill;
use PHPUnit\Framework\TestCase;

final class SkillTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $skill = new Skill(['name' => 'brainstorming']);
        self::assertSame('skill', $skill->getEntityTypeId());
    }

    public function testUuidAutoGeneration(): void
    {
        $skill = new Skill(['name' => 'brainstorming']);
        self::assertNotEmpty($skill->uuid());
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $skill->uuid(),
        );
    }

    public function testGetSetFields(): void
    {
        $skill = new Skill([
            'name' => 'brainstorming',
            'description' => 'Explore ideas before building',
            'trigger_keywords' => 'design, plan, brainstorm',
            'body' => 'Body content here',
            'source_path' => '/skills/brainstorming.md',
        ]);

        self::assertSame('brainstorming', $skill->get('name'));
        self::assertSame('Explore ideas before building', $skill->get('description'));
        self::assertSame('design, plan, brainstorm', $skill->get('trigger_keywords'));
        self::assertSame('Body content here', $skill->get('body'));
        self::assertSame('/skills/brainstorming.md', $skill->get('source_path'));

        $skill->set('description', 'Updated description');
        self::assertSame('Updated description', $skill->get('description'));
    }
}
