<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

use Closure;

/**
 * Native PHP agent client that replaces the Docker/Python subprocess.
 *
 * Calls the Anthropic Messages API directly with tool-use support,
 * executing tools in-process via AgentToolInterface implementations.
 */
class NativeAgentClient
{
    private const TOOL_RESULT_MAX_CHARS = 2000;

    private const GMAIL_BODY_MAX_CHARS = 500;

    private const RATE_LIMIT_MAX_RETRIES = 3;

    private const RATE_LIMIT_INITIAL_BACKOFF = 5;

    private const RATE_LIMIT_MAX_BACKOFF = 60;

    private const DEFAULT_TURN_LIMITS = [
        'quick_lookup' => 5,
        'email_compose' => 15,
        'brief_generation' => 10,
        'research' => 40,
        'general' => 25,
        'onboarding' => 30,
    ];

    private const MODEL_DEGRADATION = [
        'claude-opus-4-6' => 'claude-sonnet-4-6',
        'claude-sonnet-4-6' => 'claude-haiku-4-5-20251001',
        'claude-haiku-4-5-20251001' => null,
    ];

    private const MODEL_ESCALATION = [
        'claude-haiku-4-5-20251001' => 'claude-sonnet-4-6',
        'claude-sonnet-4-6' => 'claude-opus-4-6',
        'claude-opus-4-6' => null,
    ];

    /**
     * @param  list<AgentToolInterface>  $tools
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly array $tools = [],
        private readonly string $model = 'claude-sonnet-4-6',
        private readonly ?Closure $onToolResult = null,
    ) {}

    /**
     * Run the agent loop and stream results via callbacks.
     *
     * Mirrors the SubprocessChatClient::stream() signature for compatibility.
     *
     * @param  Closure(string): void  $onToken
     * @param  Closure(string): void  $onDone
     * @param  Closure(string): void  $onError
     * @param  Closure(array): void|null  $onProgress
     * @param  Closure(array): void|null  $onNeedsContinuation
     */
    public function stream(
        string $systemPrompt,
        array $messages,
        string $accountId,
        string $tenantId,
        string $apiBase,
        string $apiToken,
        Closure $onToken,
        Closure $onDone,
        Closure $onError,
        ?Closure $onProgress = null,
        ?string $model = null,
        ?Closure $onNeedsContinuation = null,
    ): void {
        $currentModel = $model ?? $this->model;
        $taskType = $this->classifyTaskType($messages);
        $turnLimit = self::DEFAULT_TURN_LIMITS[$taskType] ?? self::DEFAULT_TURN_LIMITS['general'];

        $toolDefinitions = $this->buildToolDefinitions();
        $toolExecutors = $this->buildToolExecutors();

        $fullResponse = '';
        $turnsConsumed = 0;

        try {
            for ($turn = 0; $turn < $turnLimit; $turn++) {
                $turnsConsumed++;

                if (connection_aborted()) {
                    break;
                }

                $response = $this->callAnthropicApi(
                    $currentModel,
                    $systemPrompt,
                    $messages,
                    $toolDefinitions,
                    $onProgress,
                    $onError,
                );

                if ($response === null) {
                    $onError('Failed to get API response after retries and fallbacks');

                    return;
                }

                $textParts = [];
                $toolCalls = [];

                foreach ($response['content'] ?? [] as $block) {
                    $blockType = $block['type'] ?? '';
                    if ($blockType === 'text') {
                        $textParts[] = $block['text'] ?? '';
                    } elseif ($blockType === 'tool_use') {
                        $toolCalls[] = $block;
                    }
                }

                // Emit text content
                if ($textParts !== []) {
                    $combined = implode('', $textParts);
                    $fullResponse .= $combined;
                    $onToken($combined);
                }

                // No tool calls means we're done
                if ($toolCalls === []) {
                    break;
                }

                // Append assistant message to history.
                // Ensure tool_use input is always an object (PHP json_encode turns [] into JSON array).
                $assistantContent = array_map(static function (array $block): array {
                    if (($block['type'] ?? '') === 'tool_use' && ($block['input'] ?? null) === []) {
                        $block['input'] = new \stdClass;
                    }

                    return $block;
                }, $response['content'] ?? []);
                $messages[] = ['role' => 'assistant', 'content' => $assistantContent];

                // Execute each tool call
                $toolResults = [];
                foreach ($toolCalls as $toolCall) {
                    $toolName = $toolCall['name'] ?? '';
                    $toolInput = $toolCall['input'] ?? [];
                    $toolUseId = $toolCall['id'] ?? '';

                    if ($onProgress !== null) {
                        $onProgress([
                            'phase' => 'tool_call',
                            'tool' => $toolName,
                            'summary' => 'Using '.$toolName,
                            'level' => 'info',
                        ]);
                    }

                    $executor = $toolExecutors[$toolName] ?? null;
                    if ($executor === null) {
                        $result = ['error' => "Unknown tool: {$toolName}"];
                    } else {
                        try {
                            $result = $executor($toolInput);
                        } catch (\Throwable $e) {
                            $result = ['error' => $e->getMessage()];
                        }
                    }

                    if ($this->onToolResult !== null) {
                        try {
                            ($this->onToolResult)($toolName, $result, $tenantId);
                        } catch (\Throwable) {
                            // Best-effort telemetry should never fail the chat response.
                        }
                    }

                    if ($onProgress !== null) {
                        $onProgress([
                            'phase' => 'tool_result',
                            'tool' => $toolName,
                            'summary' => 'Received result from '.$toolName,
                            'level' => 'info',
                        ]);
                    }

                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $toolUseId,
                        'content' => $this->truncateToolResult($toolName, $result),
                    ];
                }

                // Check if approaching limit (toolCalls is guaranteed non-empty here)
                if ($turnsConsumed >= $turnLimit - 1) {
                    if ($onNeedsContinuation !== null) {
                        $onNeedsContinuation([
                            'turns_consumed' => $turnsConsumed,
                            'task_type' => $taskType,
                            'message' => 'I need more turns to complete this task. Continue?',
                        ]);
                    }

                    break;
                }

                // Append tool results for next turn
                $messages[] = ['role' => 'user', 'content' => $toolResults];
            }

            $onDone($fullResponse);
        } catch (\Throwable $e) {
            error_log('[NativeAgentClient] '.$e->getMessage());
            $onError($e->getMessage());
        }
    }

    /**
     * Call the Anthropic Messages API with retry and model fallback logic.
     *
     * @return array<string, mixed>|null
     */
    private function callAnthropicApi(
        string &$currentModel,
        string $systemPrompt,
        array $messages,
        array $toolDefinitions,
        ?Closure $onProgress,
        Closure $onError,
    ): ?array {
        $cachedSystem = [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]];

        // Add cache_control to last tool definition for prompt caching
        $cachedTools = $toolDefinitions;
        if ($cachedTools !== []) {
            $lastIdx = count($cachedTools) - 1;
            $cachedTools[$lastIdx]['cache_control'] = ['type' => 'ephemeral'];
        }

        $payload = [
            'model' => $currentModel,
            'max_tokens' => 4096,
            'system' => $cachedSystem,
            'messages' => $messages,
        ];

        if ($cachedTools !== []) {
            $payload['tools'] = $cachedTools;
        }

        for ($attempt = 0; $attempt <= self::RATE_LIMIT_MAX_RETRIES; $attempt++) {
            $responseBody = $this->httpPost(
                'https://api.anthropic.com/v1/messages',
                $payload,
                [
                    'x-api-key: '.$this->apiKey,
                    'anthropic-version: 2023-06-01',
                    'content-type: application/json',
                    'anthropic-beta: prompt-caching-2024-07-31',
                ],
            );

            if ($responseBody === null) {
                // Non-rate-limit error: try escalating model
                $fallback = self::MODEL_ESCALATION[$currentModel] ?? null;
                if ($fallback !== null) {
                    if ($onProgress !== null) {
                        $onProgress([
                            'phase' => 'fallback',
                            'summary' => "API error on {$currentModel}, escalating to {$fallback}",
                            'level' => 'warning',
                        ]);
                    }
                    $currentModel = $fallback;
                    $payload['model'] = $currentModel;

                    continue;
                }

                return null;
            }

            $decoded = json_decode($responseBody, true);
            if (! is_array($decoded)) {
                return null;
            }

            // Check for rate limit error in response
            $errorType = $decoded['error']['type'] ?? null;
            if ($errorType === 'rate_limit_error') {
                if ($attempt >= self::RATE_LIMIT_MAX_RETRIES) {
                    // Try degrading model
                    $fallback = self::MODEL_DEGRADATION[$currentModel] ?? null;
                    if ($fallback !== null) {
                        if ($onProgress !== null) {
                            $onProgress([
                                'phase' => 'fallback',
                                'summary' => "Rate limit exhausted on {$currentModel}, falling back to {$fallback}",
                                'level' => 'warning',
                            ]);
                        }
                        $currentModel = $fallback;
                        $payload['model'] = $currentModel;
                        $attempt = 0; // Reset attempts for new model

                        continue;
                    }

                    return null;
                }

                $wait = min(self::RATE_LIMIT_INITIAL_BACKOFF * (2 ** $attempt), self::RATE_LIMIT_MAX_BACKOFF);
                if ($onProgress !== null) {
                    $onProgress([
                        'phase' => 'rate_limit',
                        'summary' => "Rate limited on {$currentModel}, retrying in {$wait}s...",
                        'level' => 'warning',
                    ]);
                }
                sleep((int) $wait);

                continue;
            }

            // Check for other API errors
            if (isset($decoded['error'])) {
                $errorMsg = $decoded['error']['message'] ?? $decoded['error']['type'] ?? 'unknown';
                error_log("[NativeAgentClient] API error on {$currentModel}: {$errorMsg}");
                $fallback = self::MODEL_ESCALATION[$currentModel] ?? null;
                if ($fallback !== null) {
                    if ($onProgress !== null) {
                        $onProgress([
                            'phase' => 'fallback',
                            'summary' => "API error on {$currentModel}, escalating to {$fallback}: {$errorMsg}",
                            'level' => 'warning',
                        ]);
                    }
                    $currentModel = $fallback;
                    $payload['model'] = $currentModel;

                    continue;
                }

                return null;
            }

            return $decoded;
        }

        return null;
    }

    protected function httpPost(string $url, array $payload, array $headers): ?string
    {
        $headerString = implode("\r\n", $headers)."\r\n";

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerString,
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            /** @phpstan-ignore isset.variable */
            $status = isset($http_response_header)
                ? ($http_response_header[0] ?? 'unknown')
                : 'network error';
            error_log("[NativeAgentClient] HTTP request failed: {$status}");

            return null;
        }

        return $response;
    }

    /**
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    private function buildToolDefinitions(): array
    {
        return array_map(
            static fn (AgentToolInterface $tool) => $tool->definition(),
            $this->tools,
        );
    }

    /**
     * @return array<string, Closure(array): array>
     */
    private function buildToolExecutors(): array
    {
        $executors = [];
        foreach ($this->tools as $tool) {
            $name = $tool->definition()['name'];
            $executors[$name] = static fn (array $args): array => $tool->execute($args);
        }

        return $executors;
    }

    private function classifyTaskType(array $messages): string
    {
        $firstMsg = '';
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $content = $msg['content'] ?? '';
                if (is_string($content)) {
                    $firstMsg = mb_strtolower($content);
                }

                break;
            }
        }

        $keywords = [
            'email_compose' => ['send', 'email', 'reply', 'compose', 'draft'],
            'brief_generation' => ['brief', 'summary', 'morning', 'digest'],
            'quick_lookup' => ['check', 'what time', 'calendar', 'schedule', 'who is'],
            'research' => ['research', 'find out', 'look into', 'analyze'],
        ];

        foreach ($keywords as $type => $words) {
            foreach ($words as $word) {
                if (str_contains($firstMsg, $word)) {
                    return $type;
                }
            }
        }

        return 'general';
    }

    private function truncateToolResult(string $toolName, array $result): string
    {
        $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE) ?: '{}';

        if ($toolName === 'gmail_read') {
            $truncated = $result;
            $body = $truncated['body'] ?? null;
            if (is_string($body) && mb_strlen($body) > self::GMAIL_BODY_MAX_CHARS) {
                $truncated['body'] = mb_substr($body, 0, self::GMAIL_BODY_MAX_CHARS).' [truncated]';
            }

            return json_encode($truncated, JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        if (mb_strlen($resultJson) > self::TOOL_RESULT_MAX_CHARS) {
            return mb_substr($resultJson, 0, self::TOOL_RESULT_MAX_CHARS).' [truncated]';
        }

        return $resultJson;
    }
}
