<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class TemporalAgentRegistry
{
    /** @var list<TemporalAgentInterface> */
    private array $agents;

    /**
     * @param  list<TemporalAgentInterface>  $agents
     */
    public function __construct(array $agents)
    {
        $this->agents = [];
        $seen = [];

        foreach ($agents as $agent) {
            $name = $agent->name();
            if ($name === '') {
                throw new \InvalidArgumentException('Temporal agents must expose a non-empty name.');
            }

            if (isset($seen[$name])) {
                throw new \InvalidArgumentException(sprintf('Temporal agent "%s" is registered more than once.', $name));
            }

            $seen[$name] = true;
            $this->agents[] = $agent;
        }
    }

    /**
     * @return list<TemporalAgentInterface>
     */
    public function all(): array
    {
        return $this->agents;
    }
}
