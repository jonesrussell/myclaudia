<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class TemporalAgentAction
{
    /**
     * @param  array<string, scalar|array<array-key, scalar>|null>  $payload
     */
    public function __construct(
        private readonly string $type,
        private readonly string $label,
        private readonly array $payload = [],
    ) {
        if ($this->type === '' || $this->label === '') {
            throw new \InvalidArgumentException('Temporal agent actions require both a type and label.');
        }
    }

    /**
     * @return array{
     *   type: string,
     *   label: string,
     *   payload: array<string, scalar|array<array-key, scalar>|null>
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'payload' => $this->payload,
        ];
    }
}
