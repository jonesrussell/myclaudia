<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;

final class SpecialistExecuteTool implements AgentToolInterface
{
    /** @var \Closure(string, array): (string|false) */
    private \Closure $httpPost;

    /**
     * @param  \Closure(string, array): (string|false)|null  $httpPost
     */
    public function __construct(
        private readonly string $baseUrl,
        ?\Closure $httpPost = null,
    ) {
        $this->httpPost = $httpPost ?? static function (string $url, array $body): string|false {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => json_encode($body, JSON_THROW_ON_ERROR),
                    'timeout' => 120,
                    'ignore_errors' => true,
                ],
            ]);

            return @file_get_contents($url, false, $context);
        };
    }

    public function definition(): array
    {
        return [
            'name' => 'execute_specialist',
            'description' => 'Execute a specialist agent by slug with a task description. Returns the agent\'s summary result.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'The specialist agent slug (from list_specialists)',
                    ],
                    'task' => [
                        'type' => 'string',
                        'description' => 'The task description for the specialist to execute',
                    ],
                    'context' => [
                        'type' => 'object',
                        'description' => 'Optional context object to pass to the specialist',
                    ],
                ],
                'required' => ['slug', 'task'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $slug = trim((string) ($args['slug'] ?? ''));
        if ($slug === '') {
            return ['error' => 'Specialist slug is required'];
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return ['error' => 'Invalid specialist slug format'];
        }

        $task = trim((string) ($args['task'] ?? ''));
        if ($task === '') {
            return ['error' => 'Task description is required'];
        }

        $url = rtrim($this->baseUrl, '/').'/v1/agents/'.urlencode($slug).'/execute';

        $body = ['task' => $task];
        if (isset($args['context']) && is_array($args['context'])) {
            $body['context'] = $args['context'];
        }

        $response = ($this->httpPost)($url, $body);
        if ($response === false) {
            return ['error' => 'Specialist service unavailable'];
        }

        return $this->parseSseResponse($response, $slug);
    }

    private function parseSseResponse(string $response, string $slug): array
    {
        $blocks = preg_split('/\n\n+/', trim($response));
        if ($blocks === false || $blocks === []) {
            return ['error' => 'No summary received from specialist'];
        }

        // Walk backwards to find the last summary or error event
        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            $block = $blocks[$i];
            $eventType = null;
            $dataLines = [];

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $eventType = trim(substr($line, 7));
                } elseif (str_starts_with($line, 'data: ')) {
                    $dataLines[] = substr($line, 6);
                }
            }

            $dataLine = $dataLines !== [] ? implode("\n", $dataLines) : null;

            if ($eventType === 'error' && $dataLine !== null) {
                $parsed = json_decode($dataLine, true);
                $message = is_array($parsed) ? ($parsed['message'] ?? 'Unknown error') : $dataLine;

                return ['error' => "Specialist error: {$message}"];
            }

            if ($eventType === 'summary' && $dataLine !== null) {
                $parsed = json_decode($dataLine, true);
                if (! is_array($parsed)) {
                    return ['error' => 'No summary received from specialist'];
                }

                return [
                    'agent' => $slug,
                    'result' => $parsed['summary'] ?? $parsed,
                    'metadata' => $parsed['metadata'] ?? [],
                ];
            }
        }

        return ['error' => 'No summary received from specialist'];
    }
}
