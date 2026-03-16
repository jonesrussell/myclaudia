<?php

declare(strict_types=1);

namespace Claudriel\Domain;

use Claudriel\Entity\IssueRun;
use Claudriel\Entity\Workspace;

final class IssueInstructionBuilder
{
    public function build(IssueRun $run, Workspace $workspace): string
    {
        $sections = [];
        $uuid = $run->get('uuid') ?? 'unknown';
        $sections[] = "## Issue Run: {$uuid}";
        $sections[] = "## Issue #{$run->get('issue_number')}: {$run->get('issue_title')}";
        $sections[] = $run->get('issue_body') ?? '';
        $milestone = $run->get('milestone_title');
        if ($milestone !== null && $milestone !== '') {
            $sections[] = "**Milestone:** {$milestone}";
        }
        $sections[] = implode("\n", [
            '## Guardrails',
            '- Follow the coding standards in CLAUDE.md',
            '- Write tests for new functionality',
            '- Use small, focused commits',
            '- Do not modify files outside the scope of this issue',
        ]);
        $lastOutput = $run->get('last_agent_output');
        if ($lastOutput !== null && $lastOutput !== '') {
            $sections[] = "## Previous progress\n\n{$lastOutput}";
        }
        return implode("\n\n", array_filter($sections));
    }
}
