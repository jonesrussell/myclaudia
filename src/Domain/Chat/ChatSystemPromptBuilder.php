<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;

final class ChatSystemPromptBuilder
{
    public function __construct(
        private readonly DayBriefAssembler $assembler,
        private readonly string $projectRoot,
    ) {}

    public function build(string $tenantId = 'default'): string
    {
        $parts = [];

        // Personality from CLAUDE.user.md or CLAUDE.md
        $claudeMd = $this->readFile('CLAUDE.user.md') ?? $this->readFile('CLAUDE.md') ?? '';
        if ($claudeMd !== '') {
            $parts[] = "# Personality & Behavior\n\n" . $this->extractPersonality($claudeMd);
        }

        // User context
        $me = $this->readFile('context/me.md');
        if ($me !== null) {
            $parts[] = "# About the User\n\n" . $me;
        }

        // Brief summary
        $brief = $this->assembler->assemble($tenantId, new \DateTimeImmutable('-24 hours'));
        $parts[] = $this->formatBriefContext($brief);

        // Chat instructions
        $parts[] = "# Instructions\n\nYou are Claudia, an AI personal operations assistant. You are responding via the Claudriel web dashboard. Be warm, concise, and proactive. You have access to the user's commitments, events, and personal context shown above. Help them stay on track.";

        return implode("\n\n---\n\n", array_filter($parts));
    }

    private function readFile(string $relativePath): ?string
    {
        $path = $this->projectRoot . '/' . $relativePath;
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return $content !== false ? $content : null;
    }

    /**
     * Extract personality-relevant sections from CLAUDE.md / CLAUDE.user.md.
     *
     * Grabs sections about identity, communication style, and behavior.
     * Falls back to the full file if no sections are matched.
     */
    private function extractPersonality(string $markdown): string
    {
        $sections = [];
        $currentSection = '';
        $currentContent = '';
        $capturing = false;

        $personalityHeadings = [
            'who i am',
            'how i carry myself',
            'communication style',
            'core behaviors',
            'principles',
            'what i don\'t do',
        ];

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^#{1,3}\s+(.+)$/', $line, $matches)) {
                $heading = strtolower(trim($matches[1]));
                $isPersonality = false;
                foreach ($personalityHeadings as $keyword) {
                    if (str_contains($heading, $keyword)) {
                        $isPersonality = true;
                        break;
                    }
                }

                if ($capturing && $currentContent !== '') {
                    $sections[] = $currentContent;
                }

                $capturing = $isPersonality;
                $currentSection = $heading;
                $currentContent = $capturing ? $line . "\n" : '';
            } elseif ($capturing) {
                $currentContent .= $line . "\n";
            }
        }

        if ($capturing && $currentContent !== '') {
            $sections[] = $currentContent;
        }

        if (empty($sections)) {
            // If no personality sections matched, use the first 2000 chars.
            return mb_substr($markdown, 0, 2000);
        }

        return implode("\n", $sections);
    }

    /**
     * Format the brief data into a concise context block for the system prompt.
     *
     * @param array{recent_events: array, pending_commitments: array, drifting_commitments: array, people: array<string,string>} $brief
     */
    private function formatBriefContext(array $brief): string
    {
        $lines = ["# Current Context (last 24h)"];

        $eventCount = count($brief['recent_events']);
        $lines[] = "\nRecent events: {$eventCount}";

        if (!empty($brief['people'])) {
            $names = array_values($brief['people']);
            $lines[] = "People seen: " . implode(', ', array_slice($names, 0, 10));
        }

        $pendingCount = count($brief['pending_commitments']);
        $lines[] = "Pending commitments: {$pendingCount}";

        if ($pendingCount > 0) {
            foreach (array_slice($brief['pending_commitments'], 0, 5) as $c) {
                $title = $c->get('title') ?? '(untitled)';
                $due = $c->get('due_date') ?? 'no due date';
                $lines[] = "  - {$title} (due: {$due})";
            }
        }

        $driftingCount = count($brief['drifting_commitments']);
        if ($driftingCount > 0) {
            $lines[] = "Drifting commitments (no activity 48h+): {$driftingCount}";
        }

        return implode("\n", $lines);
    }
}
