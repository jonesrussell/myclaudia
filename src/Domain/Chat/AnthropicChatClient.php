<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

final class AnthropicChatClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-sonnet-4-20250514',
        private readonly int $maxTokens = 4096,
    ) {}

    /**
     * Send a message to the Anthropic Messages API and return the full response.
     *
     * @param string $systemPrompt
     * @param array<array{role: string, content: string}> $messages
     * @return string The assistant's response text.
     *
     * @throws \RuntimeException On API errors.
     */
    public function complete(string $systemPrompt, array $messages): string
    {
        $payload = json_encode([
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $systemPrompt,
            'messages' => $messages,
            'stream' => false,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Anthropic API cURL error: {$curlError}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Anthropic API error: HTTP {$httpCode} — {$response}");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Anthropic API returned invalid JSON');
        }

        return $data['content'][0]['text'] ?? '';
    }

    /**
     * Stream a response from the Anthropic Messages API, calling callbacks for each token.
     *
     * @param string $systemPrompt
     * @param array<array{role: string, content: string}> $messages
     * @param \Closure(string): void $onToken Called with each text delta.
     * @param \Closure(string): void $onDone Called with the full assembled response.
     * @param \Closure(string): void $onError Called with error message on failure.
     */
    public function stream(
        string $systemPrompt,
        array $messages,
        \Closure $onToken,
        \Closure $onDone,
        \Closure $onError,
    ): void {
        $payload = json_encode([
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $systemPrompt,
            'messages' => $messages,
            'stream' => true,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        if ($ch === false) {
            $onError('Failed to initialize cURL');
            return;
        }

        $fullResponse = '';
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, &$fullResponse, $onToken, $onError): int {
                $buffer .= $data;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);

                    if ($line === '' || str_starts_with($line, 'event:')) {
                        continue;
                    }

                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $json = substr($line, 6);
                    if ($json === '[DONE]') {
                        continue;
                    }

                    $event = json_decode($json, true);
                    if (!is_array($event)) {
                        continue;
                    }

                    $type = $event['type'] ?? '';

                    if ($type === 'content_block_delta') {
                        $text = $event['delta']['text'] ?? '';
                        if ($text !== '') {
                            $fullResponse .= $text;
                            $onToken($text);
                        }
                    } elseif ($type === 'error') {
                        $msg = $event['error']['message'] ?? 'Unknown streaming error';
                        $onError($msg);
                    }
                }

                return strlen($data);
            },
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            $onError("cURL error: {$curlError}");
            return;
        }

        if ($httpCode !== 200 && $fullResponse === '') {
            $onError("Anthropic API error: HTTP {$httpCode}");
            return;
        }

        $onDone($fullResponse);
    }
}
