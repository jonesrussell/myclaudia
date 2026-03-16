<?php

declare(strict_types=1);

namespace Claudriel\Service;

final class GitOperator
{
    /** @var callable(string): array{exit_code:int,output:string} */
    private readonly mixed $commandRunner;

    /** @var callable(string,string): array{exit_code:int,output:string} */
    private readonly mixed $stdinRunner;

    public function __construct(
        ?callable $commandRunner = null,
        ?callable $stdinRunner = null,
    ) {
        $this->commandRunner = $commandRunner ?? $this->defaultCommandRunner(...);
        $this->stdinRunner = $stdinRunner ?? $this->defaultStdinRunner(...);
    }

    public function diff(string $repoPath): string
    {
        $this->assertRepoPath($repoPath);

        return $this->run(sprintf(
            'git -C %s diff HEAD',
            escapeshellarg($repoPath),
        ));
    }

    public function getStatus(string $repoPath): string
    {
        $this->assertRepoPath($repoPath);

        return $this->run(sprintf(
            'git -C %s status --porcelain',
            escapeshellarg($repoPath),
        ));
    }

    public function applyPatch(string $repoPath, string $patch): void
    {
        $this->assertRepoPath($repoPath);

        if ($patch === '') {
            return;
        }

        $this->runWithInput(sprintf(
            'git -C %s apply --whitespace=nowarn -',
            escapeshellarg($repoPath),
        ), $patch);
    }

    public function commit(string $repoPath, string $message): string
    {
        $this->assertRepoPath($repoPath);

        $this->run(sprintf(
            'git -C %s add -A',
            escapeshellarg($repoPath),
        ));

        $status = $this->getStatus($repoPath);
        if (trim($status) === '') {
            return $this->getHeadCommit($repoPath);
        }

        $this->run(sprintf(
            'git -C %s commit -m %s',
            escapeshellarg($repoPath),
            escapeshellarg($message),
        ));

        return $this->getHeadCommit($repoPath);
    }

    public function push(string $repoPath, string $branch): void
    {
        $this->assertRepoPath($repoPath);

        $this->run(sprintf(
            'git -C %s push origin %s',
            escapeshellarg($repoPath),
            escapeshellarg($branch),
        ));
    }

    private function getHeadCommit(string $repoPath): string
    {
        return trim($this->run(sprintf(
            'git -C %s rev-parse HEAD',
            escapeshellarg($repoPath),
        )));
    }

    private function assertRepoPath(string $repoPath): void
    {
        if ($repoPath === '' || ! is_dir($repoPath)) {
            throw new \RuntimeException(sprintf('Repository path not found: %s', $repoPath));
        }
    }

    private function run(string $command): string
    {
        $result = ($this->commandRunner)($command);
        $exitCode = (int) ($result['exit_code'] ?? 1);
        $output = (string) ($result['output'] ?? '');

        if ($exitCode !== 0) {
            throw new \RuntimeException(trim($output) !== '' ? trim($output) : sprintf('Command failed: %s', $command));
        }

        return $output;
    }

    private function runWithInput(string $command, string $input): string
    {
        $result = ($this->stdinRunner)($command, $input);
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
    private function defaultCommandRunner(string $command): array
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

        return [
            'exit_code' => (int) trim(substr($output, $pos + strlen($marker))),
            'output' => substr($output, 0, $pos),
        ];
    }

    /**
     * @return array{exit_code:int,output:string}
     */
    private function defaultStdinRunner(string $command, string $input): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (! is_resource($process)) {
            return ['exit_code' => 1, 'output' => 'Failed to start process'];
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'output' => trim($stdout."\n".$stderr),
        ];
    }
}
