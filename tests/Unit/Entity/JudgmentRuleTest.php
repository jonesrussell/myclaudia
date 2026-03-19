<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\JudgmentRule;
use PHPUnit\Framework\TestCase;

final class JudgmentRuleTest extends TestCase
{
    public function test_entity_type_id(): void
    {
        $rule = new JudgmentRule([
            'rule_text' => 'Always CC assistant on client emails',
            'context' => 'When sending emails to clients',
            'tenant_id' => 'tenant-1',
        ]);
        self::assertSame('judgment_rule', $rule->getEntityTypeId());
    }

    public function test_default_status(): void
    {
        $rule = new JudgmentRule(['rule_text' => 'Test rule']);
        self::assertSame('active', $rule->get('status'));
    }

    public function test_default_source(): void
    {
        $rule = new JudgmentRule(['rule_text' => 'Test rule']);
        self::assertSame('user_created', $rule->get('source'));
    }

    public function test_default_confidence(): void
    {
        $rule = new JudgmentRule(['rule_text' => 'Test rule']);
        self::assertSame(1.0, $rule->get('confidence'));
    }

    public function test_default_application_count(): void
    {
        $rule = new JudgmentRule(['rule_text' => 'Test rule']);
        self::assertSame(0, $rule->get('application_count'));
    }

    public function test_rule_text_stored(): void
    {
        $rule = new JudgmentRule(['rule_text' => 'Never schedule before 10am']);
        self::assertSame('Never schedule before 10am', $rule->get('rule_text'));
    }

    public function test_context_stored(): void
    {
        $rule = new JudgmentRule([
            'rule_text' => 'Use formal tone',
            'context' => 'When emailing enterprise clients',
        ]);
        self::assertSame('When emailing enterprise clients', $rule->get('context'));
    }
}
