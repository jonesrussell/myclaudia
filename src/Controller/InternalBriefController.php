<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalBriefController
{
    public function __construct(
        private readonly DayBriefAssembler $assembler,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId,
    ) {}

    public function generate(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $since = new \DateTimeImmutable('-24 hours');

        $sinceParam = $query['since'] ?? null;
        if ($sinceParam === null || $sinceParam === '') {
            $body = $this->getRequestBody($httpRequest);
            $sinceParam = (is_array($body) && isset($body['since']) && is_string($body['since']))
                ? $body['since']
                : null;
        }

        if ($sinceParam !== null && $sinceParam !== '') {
            try {
                $since = new \DateTimeImmutable($sinceParam);
            } catch (\Throwable) {
                return $this->jsonError('Invalid since date format', 400);
            }
        }

        $brief = $this->assembler->assemble($this->tenantId, $since);

        return $this->jsonResponse($brief);
    }

    private function authenticate(mixed $httpRequest): ?string
    {
        $auth = '';
        if ($httpRequest instanceof Request) {
            $auth = $httpRequest->headers->get('Authorization', '');
        }

        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return $this->apiTokenGenerator->validate(substr($auth, 7));
    }

    private function getRequestBody(mixed $httpRequest): ?array
    {
        if (! $httpRequest instanceof Request) {
            return null;
        }
        $content = $httpRequest->getContent();
        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private function jsonResponse(array $data): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function jsonError(string $message, int $statusCode): SsrResponse
    {
        return new SsrResponse(
            content: json_encode(['error' => $message], JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
