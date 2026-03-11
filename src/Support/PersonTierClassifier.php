<?php

declare(strict_types=1);

namespace Claudriel\Support;

final class PersonTierClassifier
{
    private static ?AutomatedSenderDetector $detector = null;

    public static function classify(string $email, ?string $name = null): string
    {
        if (self::$detector === null) {
            self::$detector = new AutomatedSenderDetector;
        }

        if (self::$detector->isAutomated($email, $name ?? '')) {
            return 'automated';
        }

        return 'contact';
    }
}
