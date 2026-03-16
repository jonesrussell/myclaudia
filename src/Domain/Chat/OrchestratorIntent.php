<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

final readonly class OrchestratorIntent
{
    public function __construct(
        public string $action,
        public array $params = [],
    ) {}
}
