<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

final class IssueIntentDetector
{
    private const PATTERNS = [
        '/^(?:run|work\s+on|start)\s+issue\s+#?(\d+)$/i' => 'run_issue',
        '/^(?:show|status\s+of)\s+run\s+([\w-]+)$/i' => 'show_run',
        '/^(?:list\s+runs|show\s+all\s+runs|active\s+runs)$/i' => 'list_runs',
        '/^(?:diff\s+for\s+run|show\s+diff)\s+([\w-]+)$/i' => 'show_diff',
        '/^pause\s+run\s+([\w-]+)$/i' => 'pause_run',
        '/^resume\s+run\s+([\w-]+)$/i' => 'resume_run',
        '/^abort\s+run\s+([\w-]+)$/i' => 'abort_run',
    ];

    public static function detect(string $message): ?OrchestratorIntent
    {
        $message = trim($message);
        foreach (self::PATTERNS as $pattern => $action) {
            if (preg_match($pattern, $message, $matches)) {
                $params = match ($action) {
                    'run_issue' => ['issueNumber' => (int) $matches[1]],
                    'list_runs' => [],
                    default => ['runId' => $matches[1]],
                };
                return new OrchestratorIntent($action, $params);
            }
        }
        return null;
    }
}
