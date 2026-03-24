<?php

declare(strict_types=1);

namespace Claudriel\Domain\Pipeline;

use Claudriel\Entity\PipelineConfig;

final class NorthCloudLeadFetcher
{
    /**
     * Fetch leads from the north-cloud API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(PipelineConfig $config): array
    {
        $sourceUrl = (string) ($config->get('source_url') ?? '');
        if ($sourceUrl === '') {
            return [];
        }

        $url = rtrim($sourceUrl, '/').'/api/leads';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            return [];
        }

        /** @phpstan-ignore isset.variable, booleanAnd.alwaysTrue, function.alreadyNarrowedType */
        $statusCode = isset($http_response_header) && is_array($http_response_header)
            ? $this->extractStatusCode($http_response_header)
            : 0;

        if ($statusCode < 200 || $statusCode >= 300) {
            return [];
        }

        $data = json_decode($response, true);
        if (! is_array($data)) {
            return [];
        }

        // north-cloud returns { items: [...] } or a flat array
        return isset($data['items']) && is_array($data['items']) ? $data['items'] : $data;
    }

    /**
     * @param  list<string>  $headers
     */
    private function extractStatusCode(array $headers): int
    {
        $first = $headers[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $first, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }
}
