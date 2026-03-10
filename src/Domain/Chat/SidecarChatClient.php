<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

use Closure;

class SidecarChatClient
{
    public function __construct(
        private string $sidecarUrl,
        private string $sidecarKey,
    ) {}

    /**
     * Stream a chat response from the sidecar service.
     *
     * Matches AnthropicChatClient::stream() signature for drop-in use.
     */
    public function stream(
        string $systemPrompt,
        array $messages,
        Closure $onToken,
        Closure $onDone,
        Closure $onError,
        ?string $sessionId = null,
    ): void {
        $payload = json_encode([
            'session_id' => $sessionId ?? 'default',
            'system_prompt' => $systemPrompt,
            'messages' => $messages,
        ]);

        error_log("[Sidecar] Starting curl to {$this->sidecarUrl}/chat, session=$sessionId");

        $ch = curl_init($this->sidecarUrl . '/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->sidecarKey,
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_WRITEFUNCTION => function ($curlHandle, $data) use ($onToken, $onDone, $onError) {
                error_log("[Sidecar] WRITEFUNCTION received " . strlen($data) . " bytes");
                $this->handleSseChunk($data, $onToken, $onDone, $onError);
                return strlen($data);
            },
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        error_log("[Sidecar] curl_exec done: result=" . var_export($result, true) . ", http=$httpCode, error=$curlError");

        if ($result === false || $httpCode >= 400) {
            $onError($curlError ?: "Sidecar returned HTTP $httpCode");
        }
    }

    public function isAvailable(): bool
    {
        $ch = curl_init($this->sidecarUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    private string $sseBuffer = '';
    private ?string $currentEventType = null;

    private function handleSseChunk(
        string $data,
        Closure $onToken,
        Closure $onDone,
        Closure $onError,
    ): void {
        $this->sseBuffer .= $data;

        while (($pos = strpos($this->sseBuffer, "\n")) !== false) {
            $line = substr($this->sseBuffer, 0, $pos);
            $this->sseBuffer = substr($this->sseBuffer, $pos + 1);
            $line = trim($line);

            if ($line === '') {
                $this->currentEventType = null;
                continue;
            }

            if (str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'event: ')) {
                $this->currentEventType = substr($line, 7);
                continue;
            }

            if (str_starts_with($line, 'data: ') && $this->currentEventType !== null) {
                $payload = json_decode(substr($line, 6), true);
                if ($payload === null) {
                    continue;
                }

                match ($this->currentEventType) {
                    'chat-token' => $onToken($payload['token'] ?? ''),
                    'chat-done' => $onDone($payload['full_response'] ?? ''),
                    'chat-error' => $onError($payload['error'] ?? 'Unknown error'),
                    default => null,
                };
            }
        }
    }
}
