<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class TemporalAgentLifecycle
{
    public const EVALUATED = 'evaluated';

    public const EMITTED = 'emitted';

    public const SUPPRESSED = 'suppressed';

    public const DISMISSED = 'dismissed';

    public const SNOOZED = 'snoozed';

    public const EXPIRED = 'expired';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::EVALUATED,
            self::EMITTED,
            self::SUPPRESSED,
            self::DISMISSED,
            self::SNOOZED,
            self::EXPIRED,
        ];
    }

    public static function assertValid(string $state): void
    {
        if (! in_array($state, self::all(), true)) {
            throw new \InvalidArgumentException(sprintf('Invalid temporal agent lifecycle state "%s".', $state));
        }
    }
}
