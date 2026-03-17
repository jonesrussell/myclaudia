<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

use Closure;

final class SubprocessChatClient
{
    /**
     * @param  list<string>  $command  Command to execute (e.g., ['python', 'agent/main.py'] or ['docker', 'run', '--rm', '-i', 'image', 'python', '/srv/agent/main.py'])
     */
    public function __construct(
        private readonly array $command,
        private readonly int $timeoutSeconds = 120,
    ) {}

    /**
     * Run the Python agent subprocess and stream results via callbacks.
     *
     * @param  Closure(string): void  $onToken
     * @param  Closure(string): void  $onDone
     * @param  Closure(string): void  $onError
     * @param  Closure(array): void|null  $onProgress
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
    ): void {
        $request = json_encode([
            'messages' => $messages,
            'system' => $systemPrompt,
            'account_id' => $accountId,
            'tenant_id' => $tenantId,
            'api_base' => $apiBase,
            'api_token' => $apiToken,
            'model' => $model ?? ($_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-6'),
        ], JSON_THROW_ON_ERROR);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open(
            $this->command,
            $descriptors,
            $pipes,
        );

        if (! is_resource($process)) {
            $onError('Failed to start agent subprocess');

            return;
        }

        // Write request to stdin and close
        fwrite($pipes[0], $request);
        fclose($pipes[0]);

        // Read stdout line by line with timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $fullResponse = '';
        $startTime = time();
        $receivedDone = false;

        while (! $receivedDone) {
            if (time() - $startTime > $this->timeoutSeconds) {
                proc_terminate($process);
                $onError('Agent subprocess timed out');

                break;
            }

            if (connection_aborted()) {
                proc_terminate($process);

                break;
            }

            // Block until stdout has data or 10ms elapses
            $read = [$pipes[1]];
            $write = $except = [];
            if (stream_select($read, $write, $except, 0, 10_000) === 0) {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }

                continue;
            }

            if (feof($pipes[1])) {
                break;
            }

            $line = fgets($pipes[1]);
            if ($line === false) {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }

                continue;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }

            $eventType = $event['event'] ?? '';

            match ($eventType) {
                'message' => (function () use ($event, $onToken, &$fullResponse) {
                    $content = $event['content'] ?? '';
                    $fullResponse .= $content;
                    $onToken($content);
                })(),
                'tool_call' => $onProgress !== null ? $onProgress([
                    'phase' => 'tool_call',
                    'tool' => $event['tool'] ?? '',
                    'summary' => 'Using '.($event['tool'] ?? 'tool'),
                    'level' => 'info',
                ]) : null,
                'tool_result' => $onProgress !== null ? $onProgress([
                    'phase' => 'tool_result',
                    'tool' => $event['tool'] ?? '',
                    'summary' => 'Received result from '.($event['tool'] ?? 'tool'),
                    'level' => 'info',
                ]) : null,
                'error' => $onError($event['message'] ?? 'Unknown agent error'),
                'done' => $receivedDone = true,
                default => null,
            };
        }

        // Capture stderr
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($stderr !== '' && $stderr !== false) {
            error_log('[Agent stderr] '.$stderr);
        }

        if ($exitCode !== 0 && ! $receivedDone) {
            $onError('Agent subprocess exited with code '.$exitCode);

            return;
        }

        if ($receivedDone) {
            $onDone($fullResponse);
        }
    }
}
