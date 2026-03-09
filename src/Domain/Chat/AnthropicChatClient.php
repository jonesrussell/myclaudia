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
}
