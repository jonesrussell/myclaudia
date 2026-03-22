<?php

declare(strict_types=1);

namespace Claudriel\Domain\Git;

use Claudriel\Entity\Artifact;

final class GitRepositoryManager
{
    /** @var callable(string): array{exit_code:int,output:string} */
    private readonly mixed $runner;

    private readonly string $workspaceRoot;

    public function __construct(
        ?string $workspaceRoot = null,
        ?callable $runner = null,
    ) {
        $this->workspaceRoot = $workspaceRoot ?? self::defaultWorkspaceRoot();
        $this->runner = $runner ?? $this->defaultRunner(...);
    }

    private static function defaultWorkspaceRoot(): string
    {
        $env = $_ENV['CLAUDRIEL_WORKSPACE_ROOT'] ?? getenv('CLAUDRIEL_WORKSPACE_ROOT') ?: '';
        if ($env !== '') {
            return $env;
        }

        return dirname(__DIR__, 3).'/workspaces';
    }

    public function clone(string $repoUrl, string $path, string $branch = 'main'): void
    {
        $parent = dirname($path);
        if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new \RuntimeException(sprintf('Failed to create directory: %s', $parent));
        }

        $this->run(sprintf(
            'git clone --branch %s --single-branch %s %s',
            escapeshellarg($branch),
            escapeshellarg($repoUrl),
            escapeshellarg($path),
        ));
    }

    public function pull(string $path): void
    {
        $this->assertGitRepository($path);

        $this->run(sprintf(
            'git -C %s pull --ff-only',
            escapeshellarg($path),
        ));
    }

    public function getLatestCommit(string $path): string
    {
        return trim($this->run(sprintf(
            'git -C %s rev-parse HEAD',
            escapeshellarg($path),
        )));
    }

    public function ensureLocalCopy(Artifact $artifact): void
    {
        if ($artifact->get('type') !== 'repo') {
            throw new \InvalidArgumentException('Artifact type must be repo.');
        }

        $workspaceUuid = trim((string) $artifact->get('workspace_uuid'));
        $repoUrl = trim((string) $artifact->get('repo_url'));
        $branch = trim((string) ($artifact->get('branch') ?: 'main'));

        if ($workspaceUuid === '') {
            throw new \InvalidArgumentException('Artifact workspace_uuid is required.');
        }
        if ($repoUrl === '') {
            throw new \InvalidArgumentException('Artifact repo_url is required.');
        }

        $localPath = trim((string) $artifact->get('local_path'));
        if ($localPath === '') {
            $localPath = $this->buildWorkspaceRepoPath($workspaceUuid);
            $artifact->set('local_path', $localPath);
        }

        if (is_dir($localPath.'/.git')) {
            $this->pull($localPath);
        } else {
            $this->clone($repoUrl, $localPath, $branch);
        }

        $artifact->set('branch', $branch);
        $artifact->set('last_commit', $this->getLatestCommit($localPath));
    }

    public function buildWorkspaceRepoPath(string $workspaceUuid): string
    {
        return rtrim($this->workspaceRoot, '/').'/'.$workspaceUuid.'/repo';
    }

    private function assertGitRepository(string $path): void
    {
        if (! is_dir($path.'/.git')) {
            throw new \RuntimeException(sprintf('Git repository not found at %s', $path));
        }
    }

    private function run(string $command): string
    {
        $result = ($this->runner)($command);
        $exitCode = (int) ($result['exit_code'] ?? 1);
        $output = (string) ($result['output'] ?? '');

        if ($exitCode !== 0) {
            throw new \RuntimeException(trim($output) !== '' ? trim($output) : sprintf('Command failed: %s', $command));
        }

        return $output;
    }

    /**
     * @return array{exit_code:int,output:string}
     */
    private function defaultRunner(string $command): array
    {
        $marker = '__CLAUDRIEL_GIT_EXIT_CODE__';
        $output = shell_exec($command.' 2>&1; printf "\n'.$marker.'%s" "$?"');

        if ($output === null) {
            return ['exit_code' => 1, 'output' => 'shell_exec returned null'];
        }

        $pos = strrpos($output, $marker);
        if ($pos === false) {
            return ['exit_code' => 1, 'output' => trim($output)];
        }

        $commandOutput = substr($output, 0, $pos);
        $exitCode = (int) trim(substr($output, $pos + strlen($marker)));

        return [
            'exit_code' => $exitCode,
            'output' => trim($commandOutput),
        ];
    }
}
