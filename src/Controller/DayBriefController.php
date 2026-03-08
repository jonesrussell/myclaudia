<?php

declare(strict_types=1);

namespace MyClaudia\Controller;

use MyClaudia\DayBrief\DayBriefAssembler;
use Symfony\Component\HttpFoundation\Response;

final class DayBriefController
{
    public function __construct(
        private readonly DayBriefAssembler $assembler,
    ) {}

    public function show(): Response
    {
        $brief = $this->assembler->assemble(
            tenantId: 'default',
            since: new \DateTimeImmutable('-24 hours'),
        );

        return new Response(
            json_encode($brief, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }
}
