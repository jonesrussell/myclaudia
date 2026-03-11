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

    public function build(string $tenantId = 'default', bool $hasToolAccess = false, ?string $activeWorkspace = null): string
    {
        $parts = [];

        // Personality from CLAUDE.user.md or CLAUDE.md
        $claudeMd = $this->readFile('CLAUDE.user.md') ?? $this->readFile('CLAUDE.md') ?? '';
        if ($claudeMd !== '') {
            $parts[] = "# Personality & Behavior\n\n".$this->extractPersonality($claudeMd);
        }

        // User context
        $me = $this->readFile('context/me.md');
        if ($me !== null) {
            $parts[] = "# About the User\n\n".$me;
        }

        // Brief summary
        $brief = $this->assembler->assemble($tenantId, new \DateTimeImmutable('-24 hours'));
        $parts[] = $this->formatBriefContext($brief);

        // Active workspace context
        if ($activeWorkspace !== null) {
            $parts[] = "## Active Workspace: {$activeWorkspace}\nYou are currently operating within the {$activeWorkspace} workspace.\nThis workspace includes events, commitments, and people related to the {$activeWorkspace} project.";
        }

        // Chat instructions
        $parts[] = $this->buildInstructions($hasToolAccess);

        return implode("\n\n---\n\n", array_filter($parts));
    }

    private function readFile(string $relativePath): ?string
    {
        $path = $this->projectRoot.'/'.$relativePath;
        if (! is_file($path)) {
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
                $currentContent = $capturing ? $line."\n" : '';
            } elseif ($capturing) {
                $currentContent .= $line."\n";
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
     * @param  array{recent_events: array, pending_commitments: array, drifting_commitments: array, people: array<string,string>}  $brief
     */
    private function buildInstructions(bool $hasToolAccess): string
    {
        $base = <<<'INSTRUCTIONS'
# Instructions

You are Claudriel, an AI personal operations assistant. You are responding via the Claudriel web dashboard. Be warm, concise, and proactive. You have access to the user's commitments, events, and personal context shown above. Help them stay on track.
INSTRUCTIONS;

        if (! $hasToolAccess) {
            $base .= <<<'NO_TOOLS'


## Capabilities

You can see the user's current context (commitments, events, people) shown above, and you can have a helpful conversation based on that context. You do NOT have access to Gmail, Calendar, or any external data sources in this mode. If the user asks you to check email or calendar, let them know that external data access requires the sidecar service to be running.
NO_TOOLS;

            return $base;
        }

        $ingestUrl = getenv('CLAUDRIEL_INGEST_URL') ?: 'http://caddy/api/ingest';
        $apiKey = $_ENV['CLAUDRIEL_API_KEY'] ?? getenv('CLAUDRIEL_API_KEY') ?: '';

        $base .= <<<TOOLS

## Data Ingestion

When the user asks you to check emails, calendar events, or any external data source, you MUST:

1. Fetch the data using your Gmail/Calendar MCP tools
2. For EACH relevant item found, ingest it into the Claudriel system using curl:

```bash
curl -s -X POST "{$ingestUrl}" \\
  -H "Authorization: Bearer {$apiKey}" \\
  -H "Content-Type: application/json" \\
  -d '{"source":"gmail","type":"message.received","payload":{"subject":"...","from_email":"...","from_name":"...","body":"..."}}'
```

### Ingestion payload formats:

**Email events** (source: "gmail", type: "message.received"):
```json
{"source":"gmail","type":"message.received","payload":{"subject":"<subject>","from_email":"<email>","from_name":"<name>","body":"<snippet or summary>"}}
```

**Calendar events** (source: "google-calendar", type: "calendar.event"):
```json
{"source":"google-calendar","type":"calendar.event","payload":{"subject":"<event title>","from_name":"<organizer>","from_email":"<organizer email>","body":"<description or location>"}}
```

**Commitment detection** (source: "claude-sidecar", type: "commitment.detected"):
```json
{"source":"claude-sidecar","type":"commitment.detected","payload":{"title":"<what was committed to>","confidence":0.8,"due_date":"2026-03-15","person_email":"<who>","person_name":"<who>"}}
```

Always ingest data silently (don't show curl output to the user), then summarize what you found in a friendly way. After ingesting, the Day Brief on the dashboard will update automatically.
TOOLS;

        return $base;
    }

    private function formatBriefContext(array $brief): string
    {
        $lines = ['# Current Context (last 24h)'];

        if (! empty($brief['schedule'])) {
            $count = count($brief['schedule']);
            $lines[] = "\nSchedule ({$count}):";
            foreach (array_slice($brief['schedule'], 0, 5) as $item) {
                $lines[] = "  - {$item['title']} ({$item['start_time']})";
            }
        }

        if (! empty($brief['job_hunt'])) {
            $count = count($brief['job_hunt']);
            $lines[] = "\nJob Hunt ({$count}):";
            foreach (array_slice($brief['job_hunt'], 0, 5) as $item) {
                $lines[] = "  - {$item['title']} — {$item['source_name']}";
            }
        }

        if (! empty($brief['people'])) {
            $count = count($brief['people']);
            $names = array_map(fn ($p) => $p['person_name'], array_slice($brief['people'], 0, 10));
            $lines[] = "\nPeople ({$count}): ".implode(', ', $names);
        }

        $pending = $brief['commitments']['pending'] ?? [];
        $pendingCount = count($pending);
        $lines[] = "\nPending commitments: {$pendingCount}";
        if ($pendingCount > 0) {
            foreach (array_slice($pending, 0, 5) as $c) {
                $title = $c->get('title') ?? '(untitled)';
                $due = $c->get('due_date') ?? 'no due date';
                $lines[] = "  - {$title} (due: {$due})";
            }
        }

        $driftingCount = $brief['counts']['drifting'] ?? 0;
        if ($driftingCount > 0) {
            $lines[] = "Drifting commitments (no activity 48h+): {$driftingCount}";
        }

        return implode("\n", $lines);
    }
}
