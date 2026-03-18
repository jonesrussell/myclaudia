<?php

declare(strict_types=1);

namespace Claudriel\Domain;

use Claudriel\AI\CodexExecutionPipeline;
use Claudriel\Domain\Git\GitOperator;
use Claudriel\Entity\IssueRun;
use Claudriel\Entity\Workspace;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GitHub\GitHubClient;

final class IssueOrchestrator
{
    private const VALID_TRANSITIONS = [
        'pending' => ['running'],
        'running' => ['paused', 'failed', 'completed'],
        'paused' => ['running', 'failed'],
        'failed' => ['pending'],
        'completed' => [],
    ];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly GitHubClient $gitHubClient,
        private readonly ?CodexExecutionPipeline $pipeline,
        private readonly IssueInstructionBuilder $instructionBuilder,
        private readonly ?GitOperator $gitOperator,
    ) {}

    public function createRun(int $issueNumber): IssueRun
    {
        $issue = $this->gitHubClient->getIssue($issueNumber);

        $workspace = $this->findOrCreateWorkspace($issueNumber);

        $run = new IssueRun([
            'issue_number' => $issue->number,
            'issue_title' => $issue->title,
            'issue_body' => $issue->body,
            'milestone_title' => $issue->milestone,
            'workspace_id' => $workspace->id(),
            'branch_name' => 'issue-'.$issueNumber,
        ]);

        $this->appendEvent($run, ['type' => 'created', 'issue' => $issueNumber]);

        $run->enforceIsNew();
        $this->entityTypeManager->getStorage('issue_run')->save($run);

        return $run;
    }

    public function startRun(IssueRun $run): void
    {
        $this->transitionStatus($run, 'running');

        if ($this->pipeline !== null) {
            $workspace = $this->loadWorkspace($run);
            $instruction = $this->instructionBuilder->build($run, $workspace);
            $this->pipeline->execute($workspace, $instruction);
        }
    }

    public function pauseRun(IssueRun $run): void
    {
        $this->transitionStatus($run, 'paused');
    }

    public function resumeRun(IssueRun $run): void
    {
        $this->transitionStatus($run, 'running');

        if ($this->pipeline !== null) {
            $workspace = $this->loadWorkspace($run);
            $instruction = $this->instructionBuilder->build($run, $workspace);
            $this->pipeline->execute($workspace, $instruction);
        }
    }

    public function abortRun(IssueRun $run): void
    {
        $this->transitionStatus($run, 'failed');
        $this->appendEvent($run, ['type' => 'aborted']);
        $this->saveRun($run);
    }

    public function completeRun(IssueRun $run): void
    {
        $this->transitionStatus($run, 'completed');

        $diff = $this->getWorkspaceDiff($run);
        if ($diff !== '') {
            $pr = $this->gitHubClient->createPullRequest(
                title: "feat(#{$run->get('issue_number')}): {$run->get('issue_title')}",
                head: $run->get('branch_name'),
                base: 'main',
                body: "Resolves #{$run->get('issue_number')}\n\nAutomated by Claudriel Issue Orchestrator.",
            );
            $run->set('pr_url', $pr->url);
            $this->appendEvent($run, ['type' => 'pr_created', 'url' => $pr->url]);
        }

        $this->saveRun($run);
    }

    public function getRun(string $uuid): ?IssueRun
    {
        $storage = $this->entityTypeManager->getStorage('issue_run');
        $ids = $storage->getQuery()->condition('uuid', $uuid)->execute();

        if ($ids === []) {
            return null;
        }

        $entities = $storage->loadMultiple($ids);
        $first = reset($entities);

        return $first instanceof IssueRun ? $first : null;
    }

    public function getRunByIssue(int $issueNumber): ?IssueRun
    {
        $storage = $this->entityTypeManager->getStorage('issue_run');
        $ids = $storage->getQuery()->condition('issue_number', $issueNumber)->execute();
        $entities = $storage->loadMultiple($ids);

        foreach (array_reverse($entities) as $entity) {
            if ($entity instanceof IssueRun && in_array($entity->get('status'), ['pending', 'running', 'paused'], true)) {
                return $entity;
            }
        }

        return null;
    }

    /** @return IssueRun[] */
    public function listRuns(?string $status = null): array
    {
        $storage = $this->entityTypeManager->getStorage('issue_run');

        if ($status !== null) {
            $ids = $storage->getQuery()->condition('status', $status)->execute();
        } else {
            $ids = $storage->getQuery()->execute();
        }

        if ($ids === []) {
            return [];
        }

        /** @var IssueRun[] $runs */
        $runs = array_values($storage->loadMultiple($ids));

        return $runs;
    }

    public function getWorkspaceDiff(IssueRun $run): string
    {
        if ($this->gitOperator === null) {
            return '';
        }

        $workspace = $this->loadWorkspace($run);
        $repoPath = $workspace->get('repo_path');

        if ($repoPath === null || ! is_dir($repoPath)) {
            return '';
        }

        return $this->gitOperator->diff($repoPath);
    }

    public function summarizeRun(IssueRun $run): string
    {
        $lines = [];
        $lines[] = "**Issue #{$run->get('issue_number')}:** {$run->get('issue_title')}";
        $lines[] = "**Status:** {$run->get('status')}";
        $lines[] = "**Branch:** {$run->get('branch_name')}";

        $prUrl = $run->get('pr_url');
        if ($prUrl !== null && $prUrl !== '') {
            $lines[] = "**PR:** {$prUrl}";
        }

        $lastOutput = $run->get('last_agent_output');
        if ($lastOutput !== null && $lastOutput !== '') {
            $lines[] = "**Last output:** {$lastOutput}";
        }

        $events = json_decode($run->get('event_log') ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        $lines[] = '**Events:** '.count($events);

        return implode("\n", $lines);
    }

    private function transitionStatus(IssueRun $run, string $newStatus): void
    {
        $currentStatus = $run->get('status');
        $allowed = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Cannot transition from '{$currentStatus}' to '{$newStatus}'"
            );
        }

        $this->appendEvent($run, [
            'type' => 'status_change',
            'from' => $currentStatus,
            'to' => $newStatus,
        ]);

        $run->set('status', $newStatus);
        $this->saveRun($run);
    }

    private function appendEvent(IssueRun $run, array $event): void
    {
        $event['time'] = gmdate('Y-m-d\TH:i:s\Z');
        $events = json_decode($run->get('event_log') ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        $events[] = $event;
        $run->set('event_log', json_encode($events, JSON_THROW_ON_ERROR));
    }

    private function saveRun(IssueRun $run): void
    {
        $this->entityTypeManager->getStorage('issue_run')->save($run);
    }

    private function findOrCreateWorkspace(int $issueNumber): Workspace
    {
        $branchName = 'issue-'.$issueNumber;
        $storage = $this->entityTypeManager->getStorage('workspace');
        $ids = $storage->getQuery()->condition('branch', $branchName)->execute();

        if ($ids !== []) {
            $entities = $storage->loadMultiple($ids);
            $first = reset($entities);
            if ($first instanceof Workspace) {
                return $first;
            }
        }

        $workspace = new Workspace([
            'name' => "Issue #{$issueNumber}",
            'branch' => $branchName,
        ]);
        $workspace->enforceIsNew();
        $storage->save($workspace);

        return $workspace;
    }

    private function loadWorkspace(IssueRun $run): Workspace
    {
        $storage = $this->entityTypeManager->getStorage('workspace');
        $entity = $storage->load($run->get('workspace_id'));

        if (! $entity instanceof Workspace) {
            throw new \RuntimeException("Workspace not found for run {$run->get('uuid')}");
        }

        return $entity;
    }
}
