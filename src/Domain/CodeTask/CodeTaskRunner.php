<?php

declare(strict_types=1);

namespace Claudriel\Domain\CodeTask;

use Claudriel\Entity\CodeTask;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CodeTaskRunner
{
    private const MAX_DIFF_LINES = 200;

    private const DIFF_TRUNCATE_AT = 150;

    private const TIMEOUT_SECONDS = 600;

    /** @var null|callable(string,string): array{exit_code:int,output:string} */
    private readonly mixed $processRunner;

    public function __construct(
        private readonly EntityRepositoryInterface $codeTaskRepo,
        ?callable $processRunner = null,
    ) {
        $this->processRunner = $processRunner;
    }

    public function run(CodeTask $task, string $repoPath): void
    {
        $task->set('status', 'running');
        $task->set('started_at', date('c'));
        $this->codeTaskRepo->save($task);

        try {
            $this->prepareWorkingBranch($repoPath, (string) $task->get('branch_name'));
            $result = $this->invokeClaudeCode($repoPath, (string) $task->get('prompt'));

            $exitCode = (int) $result['exit_code'];
            $output = (string) $result['output'];

            $task->set('claude_output', mb_substr($output, 0, 50000));

            if ($exitCode !== 0) {
                $task->set('status', 'failed');
                $task->set('error', mb_substr($output, 0, 5000));
                $task->set('completed_at', date('c'));
                $this->codeTaskRepo->save($task);

                return;
            }

            $diff = $this->captureDiff($repoPath);
            if ($diff === '') {
                $task->set('status', 'completed');
                $task->set('summary', 'No changes were needed.');
                $task->set('diff_preview', '');
                $task->set('completed_at', date('c'));
                $this->codeTaskRepo->save($task);

                return;
            }

            $task->set('diff_preview', $this->truncateDiff($diff));
            $task->set('summary', $this->extractSummary($output));

            $this->pushAndCreatePr($repoPath, $task);

            $task->set('status', 'completed');
            $task->set('completed_at', date('c'));
            $this->codeTaskRepo->save($task);
        } catch (\Throwable $e) {
            $task->set('status', 'failed');
            $task->set('error', $e->getMessage());
            $task->set('completed_at', date('c'));
            $this->codeTaskRepo->save($task);
        }
    }

    public function generateBranchName(string $prompt): string
    {
        $slug = strtolower(trim($prompt));
        $slug = (string) preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = (string) preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');

        if (strlen($slug) > 49) {
            $slug = substr($slug, 0, 49);
            $slug = (string) preg_replace('/-[^-]*$/', '', $slug);
        }

        return 'claudriel/'.$slug;
    }

    private function prepareWorkingBranch(string $repoPath, string $branchName): void
    {
        $this->shellExec(sprintf(
            'git -C %s checkout -b %s',
            escapeshellarg($repoPath),
            escapeshellarg($branchName),
        ));
    }

    /**
     * @return array{exit_code: int, output: string}
     */
    private function invokeClaudeCode(string $repoPath, string $prompt): array
    {
        if ($this->processRunner !== null) {
            return ($this->processRunner)($repoPath, $prompt);
        }

        $command = sprintf(
            'cd %s && timeout %d claude --print --output-format stream-json --allowedTools "Edit,Write,Read,Glob,Grep,Bash" --max-turns 30 -p %s 2>&1',
            escapeshellarg($repoPath),
            self::TIMEOUT_SECONDS,
            escapeshellarg($prompt),
        );

        $marker = '__CLAUDRIEL_EXIT__';
        $raw = shell_exec($command.'; printf "\n'.$marker.'%s" "$?"');

        if ($raw === null) {
            return ['exit_code' => 1, 'output' => 'shell_exec returned null'];
        }

        $pos = strrpos($raw, $marker);
        if ($pos === false) {
            return ['exit_code' => 1, 'output' => trim($raw)];
        }

        return [
            'exit_code' => (int) trim(substr($raw, $pos + strlen($marker))),
            'output' => trim(substr($raw, 0, $pos)),
        ];
    }

    private function captureDiff(string $repoPath): string
    {
        $result = $this->shellExecSafe(sprintf(
            'git -C %s diff HEAD~1 2>/dev/null || git -C %s diff --cached 2>/dev/null || echo ""',
            escapeshellarg($repoPath),
            escapeshellarg($repoPath),
        ));

        return trim($result);
    }

    private function truncateDiff(string $diff): string
    {
        $lines = explode("\n", $diff);
        $total = count($lines);

        if ($total <= self::MAX_DIFF_LINES) {
            return $diff;
        }

        $truncated = array_slice($lines, 0, self::DIFF_TRUNCATE_AT);
        $remaining = $total - self::DIFF_TRUNCATE_AT;

        return implode("\n", $truncated)."\n\n... and {$remaining} more lines. See full diff on GitHub.";
    }

    private function extractSummary(string $output): string
    {
        $lines = explode("\n", $output);
        $lastLines = array_slice($lines, -20);

        return mb_substr(implode("\n", $lastLines), 0, 2000);
    }

    private function pushAndCreatePr(string $repoPath, CodeTask $task): void
    {
        $branchName = (string) $task->get('branch_name');
        $prompt = (string) $task->get('prompt');
        $summary = (string) ($task->get('summary') ?? $prompt);

        $this->shellExec(sprintf(
            'git -C %s push origin %s',
            escapeshellarg($repoPath),
            escapeshellarg($branchName),
        ));

        $prTitle = mb_substr($prompt, 0, 70);
        $prBody = "## Summary\n\n".$summary."\n\n---\nCreated by Claudriel Code Task";

        $prOutput = $this->shellExecSafe(sprintf(
            'cd %s && gh pr create --title %s --body %s 2>&1',
            escapeshellarg($repoPath),
            escapeshellarg($prTitle),
            escapeshellarg($prBody),
        ));

        $prUrl = trim($prOutput);
        if (str_starts_with($prUrl, 'https://')) {
            $task->set('pr_url', $prUrl);
        }
    }

    private function shellExec(string $command): void
    {
        $output = shell_exec($command.' 2>&1');
        // Fire and forget for git operations — errors caught by caller
    }

    private function shellExecSafe(string $command): string
    {
        $output = shell_exec($command);

        return is_string($output) ? trim($output) : '';
    }
}
