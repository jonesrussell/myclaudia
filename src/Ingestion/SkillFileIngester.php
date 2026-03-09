<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

use Claudriel\Entity\Skill;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class SkillFileIngester
{
    public function __construct(private readonly EntityRepositoryInterface $skillRepo) {}

    /**
     * Scan a directory for .md skill files and ingest them into the repository.
     *
     * @return list<Skill> The ingested skills.
     */
    public function ingestDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException(sprintf('Directory not found: %s', $directory));
        }

        $files = glob($directory . '/*.md');
        if ($files === false) {
            return [];
        }

        $skills = [];
        foreach ($files as $file) {
            $skill = $this->ingestFile($file);
            if ($skill !== null) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /**
     * Parse a single skill file and save it to the repository.
     */
    public function ingestFile(string $filePath): ?Skill
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $parsed = $this->parseFrontMatter($content);
        if ($parsed === null) {
            return null;
        }

        [$frontMatter, $body] = $parsed;

        if (!isset($frontMatter['name'])) {
            return null;
        }

        // Check if a skill with this source_path already exists; update it if so.
        $existing = $this->findBySourcePath($filePath);
        $skill = $existing ?? new Skill();

        // Use the UUID as the storage ID when no explicit sid is set.
        // This ensures each skill gets a unique key in all storage drivers.
        if ($skill->id() === null || $skill->id() === '') {
            $skill->set('sid', $skill->uuid());
        }

        $skill->set('name', $frontMatter['name']);
        $skill->set('description', $frontMatter['description'] ?? '');
        $skill->set('trigger_keywords', $frontMatter['trigger_keywords'] ?? '');
        $skill->set('body', trim($body));
        $skill->set('source_path', $filePath);

        $this->skillRepo->save($skill);

        return $skill;
    }

    /**
     * Parse YAML-like front matter from a markdown file.
     *
     * @return array{0: array<string, string>, 1: string}|null
     */
    public function parseFrontMatter(string $content): ?array
    {
        if (!preg_match('/\A---\s*\n(.*?)\n---\s*\n?(.*)\z/s', $content, $matches)) {
            return null;
        }

        $frontMatter = [];
        $lines = explode("\n", $matches[1]);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            $colonPos = strpos($line, ':');
            $key = trim(substr($line, 0, $colonPos));
            $value = trim(substr($line, $colonPos + 1));
            $frontMatter[$key] = $value;
        }

        return [$frontMatter, $matches[2]];
    }

    private function findBySourcePath(string $filePath): ?Skill
    {
        $all = $this->skillRepo->findBy([]);
        foreach ($all as $skill) {
            if ($skill->get('source_path') === $filePath) {
                /** @var Skill $skill */
                return $skill;
            }
        }

        return null;
    }
}
