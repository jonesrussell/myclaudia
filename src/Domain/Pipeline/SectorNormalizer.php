<?php

declare(strict_types=1);

namespace Claudriel\Domain\Pipeline;

final class SectorNormalizer
{
    public const array CANONICAL_SECTORS = [
        'IT', 'Networks', 'Security', 'Cloud', 'Telecom', 'Software', 'Infrastructure', 'Other',
    ];

    public static function normalize(string $raw): string
    {
        // Lowercase comparison against canonical sectors
        $lower = strtolower(trim($raw));
        foreach (self::CANONICAL_SECTORS as $sector) {
            if (strtolower($sector) === $lower) {
                return $sector;
            }
        }
        // Check for common variations
        $mapping = [
            'information technology' => 'IT',
            'networking' => 'Networks',
            'network' => 'Networks',
            'cybersecurity' => 'Security',
            'infosec' => 'Security',
            'cloud computing' => 'Cloud',
            'telecommunications' => 'Telecom',
            'telecom' => 'Telecom',
            'dev' => 'Software',
            'development' => 'Software',
            'infra' => 'Infrastructure',
        ];

        return $mapping[$lower] ?? 'Other';
    }
}
