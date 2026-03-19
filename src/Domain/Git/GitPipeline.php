<?php

declare(strict_types=1);

namespace Claudriel\Domain\Git;

final class GitPipeline
{
    public function __construct(
        private readonly GitOperator $gitOperator,
    ) {}

    /**
     * Stage, commit, and push in one operation.
     *
     * @return array{commit_hash: string, pushed: bool}
     */
    public function commitAndPush(string $repoPath, string $message, ?string $branch = null): array
    {
        $commitHash = $this->gitOperator->commit($repoPath, $message);

        $pushed = false;
        if ($branch !== null) {
            $this->gitOperator->push($repoPath, $branch);
            $pushed = true;
        }

        return [
            'commit_hash' => $commitHash,
            'pushed' => $pushed,
        ];
    }

    /**
     * Generate a diff between two refs, or show the working-tree diff.
     *
     * When both $fromRef and $toRef are null, delegates to GitOperator::diff()
     * which shows the diff against HEAD.
     */
    public function generateDiff(string $repoPath, ?string $fromRef = null, ?string $toRef = null): string
    {
        if ($fromRef === null && $toRef === null) {
            return $this->gitOperator->diff($repoPath);
        }

        $from = $fromRef ?? 'HEAD';
        $to = $toRef ?? 'HEAD';

        return $this->gitOperator->diffRefs($repoPath, $from, $to);
    }
}
