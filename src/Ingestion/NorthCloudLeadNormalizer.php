<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

final class NorthCloudLeadNormalizer
{
    /**
     * Normalize a raw north-cloud lead hit into ingestion data format.
     *
     * @param  array<string, mixed>  $hit  Raw lead data from north-cloud API
     * @return array{source: string, type: string, payload: array<string, mixed>, timestamp: string, tenant_id: string, trace_id: string|null}
     */
    public function normalize(array $hit, string $tenantId, string $workspaceUuid): array
    {
        return [
            'source' => 'northcloud',
            'type' => 'lead.imported',
            'payload' => [
                'external_id' => (string) ($hit['id'] ?? $hit['slug'] ?? ''),
                'name' => (string) ($hit['title'] ?? $hit['name'] ?? ''),
                'description' => (string) ($hit['description'] ?? ''),
                'contact_name' => (string) ($hit['contact_name'] ?? $hit['contact'] ?? ''),
                'contact_email' => (string) ($hit['contact_email'] ?? ''),
                'source_url' => (string) ($hit['url'] ?? $hit['source_url'] ?? ''),
                'closing_date' => (string) ($hit['closing_date'] ?? ''),
                'value' => (string) ($hit['budget'] ?? $hit['value'] ?? ''),
                'sector' => (string) ($hit['sector'] ?? $hit['category'] ?? ''),
                'workspace_uuid' => $workspaceUuid,
            ],
            'timestamp' => (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            'tenant_id' => $tenantId,
            'trace_id' => null,
        ];
    }
}
