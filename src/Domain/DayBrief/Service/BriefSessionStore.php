<?php

declare(strict_types=1);

namespace MyClaudia\Domain\DayBrief\Service;

final class BriefSessionStore
{
    public function __construct(private readonly string $storageFile) {}

    public function getLastBriefAt(): ?\DateTimeImmutable
    {
        if (!file_exists($this->storageFile)) {
            return null;
        }
        $contents = trim((string) file_get_contents($this->storageFile));
        if ($contents === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $contents);
        return $dt !== false ? $dt : null;
    }

    public function recordBriefAt(\DateTimeImmutable $at): void
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->storageFile, $at->format(\DateTimeInterface::ATOM));
    }
}
