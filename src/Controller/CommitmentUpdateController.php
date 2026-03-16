<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

/**
 * PATCH /commitments/{uuid} controller.
 */
final class CommitmentUpdateController
{
    private const VALID_STATUSES = ['pending', 'active', 'done', 'ignored'];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function update(array $params, array $query, mixed $account, ?Request $httpRequest = null): SsrResponse
    {
        $uuid = $params['uuid'] ?? '';

        $storage = $this->entityTypeManager->getStorage('commitment');
        $ids = $storage->getQuery()->condition('uuid', $uuid)->execute();
        $commitment = ! empty($ids) ? $storage->load(reset($ids)) : null;

        if (! $commitment instanceof ContentEntityInterface) {
            return new SsrResponse(
                content: json_encode(['error' => 'Not found.']),
                statusCode: 404,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $raw = $httpRequest?->getContent() ?? '';
        $body = json_decode($raw, true) ?? [];
        $status = $body['status'] ?? null;

        if (! is_string($status) || ! in_array($status, self::VALID_STATUSES, true)) {
            return new SsrResponse(
                content: json_encode(['error' => sprintf('Invalid status. Use: %s', implode(', ', self::VALID_STATUSES))]),
                statusCode: 422,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $commitment->set('status', $status);
        $storage->save($commitment);

        return new SsrResponse(
            content: json_encode(['uuid' => $uuid, 'status' => $status]),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
