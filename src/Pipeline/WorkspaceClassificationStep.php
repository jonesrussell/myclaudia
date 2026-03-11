<?php

declare(strict_types=1);

namespace Claudriel\Pipeline;

use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\AI\Pipeline\PipelineStepInterface;
use Waaseyaa\AI\Pipeline\StepResult;

final class WorkspaceClassificationStep implements PipelineStepInterface
{
    public function __construct(private readonly object $aiClient) {}

    public function process(array $input, PipelineContext $context): StepResult
    {
        $workspaces = $input['workspaces'] ?? [];

        if (empty($workspaces)) {
            return StepResult::success(['workspace_id' => null]);
        }

        $workspaceList = implode(', ', array_map(
            fn(array $w) => "{$w['name']}: {$w['description']}",
            $workspaces
        ));

        $source = $input['source'] ?? 'unknown';
        $type = $input['type'] ?? 'unknown';
        $subject = $input['subject'] ?? '';
        $body = substr($input['body'] ?? '', 0, 500);

        $prompt = <<<PROMPT
        Given these workspaces: [{$workspaceList}]
        Which workspace does this event belong to?
        Event source: {$source}, type: {$type}
        Subject: {$subject}
        Body (first 500 chars): {$body}
        Reply with the workspace name or "none".
        PROMPT;

        $raw = trim($this->aiClient->complete($prompt));

        if ($raw === '') {
            return StepResult::failure('AI client returned empty response for workspace classification.');
        }

        if (strtolower($raw) === 'none') {
            return StepResult::success(['workspace_id' => null]);
        }

        foreach ($workspaces as $workspace) {
            if (strcasecmp($workspace['name'], $raw) === 0) {
                return StepResult::success(['workspace_id' => $workspace['uuid']]);
            }
        }

        return StepResult::success(['workspace_id' => null]);
    }

    public function describe(): string
    {
        return 'Classify an event into a workspace using AI.';
    }
}
