<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain;

use Claudriel\Domain\IssueInstructionBuilder;
use Claudriel\Domain\IssueOrchestrator;
use Claudriel\Entity\IssueRun;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GitHub\GitHubClient;

#[CoversClass(IssueOrchestrator::class)]
final class IssueOrchestratorTest extends TestCase
{
    #[Test]
    public function createRunFetchesIssueAndCreatesWorkspace(): void
    {
        $orchestrator = $this->buildOrchestrator();

        $run = $orchestrator->createRun(42);

        $this->assertInstanceOf(IssueRun::class, $run);
        $this->assertSame(42, $run->get('issue_number'));
        $this->assertSame('Test issue', $run->get('issue_title'));
        $this->assertSame('Issue body', $run->get('issue_body'));
        $this->assertSame('v1.0', $run->get('milestone_title'));
        $this->assertSame('issue-42', $run->get('branch_name'));
        $this->assertSame('pending', $run->get('status'));
    }

    #[Test]
    public function createRunSetsWorkspaceId(): void
    {
        $orchestrator = $this->buildOrchestrator();

        $run = $orchestrator->createRun(42);

        $this->assertNotNull($run->get('workspace_id'));
    }

    #[Test]
    public function createRunAppendsCreatedEvent(): void
    {
        $orchestrator = $this->buildOrchestrator();

        $run = $orchestrator->createRun(42);

        $events = json_decode($run->get('event_log'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $events);
        $this->assertSame('created', $events[0]['type']);
        $this->assertSame(42, $events[0]['issue']);
    }

    #[Test]
    public function createRunReusesExistingWorkspace(): void
    {
        $orchestrator = $this->buildOrchestrator();

        $run1 = $orchestrator->createRun(42);
        $run2 = $orchestrator->createRun(42);

        $this->assertSame($run1->get('workspace_id'), $run2->get('workspace_id'));
    }

    #[Test]
    public function pauseRunSetsStatusAndAppendsEvent(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run = $orchestrator->createRun(42);
        $run->set('status', 'running');

        $orchestrator->pauseRun($run);

        $this->assertSame('paused', $run->get('status'));
        $events = json_decode($run->get('event_log'), true, 512, JSON_THROW_ON_ERROR);
        $lastEvent = end($events);
        $this->assertSame('status_change', $lastEvent['type']);
        $this->assertSame('running', $lastEvent['from']);
        $this->assertSame('paused', $lastEvent['to']);
    }

    #[Test]
    public function abortRunSetsFailedStatus(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run = $orchestrator->createRun(42);
        $run->set('status', 'running');

        $orchestrator->abortRun($run);

        $this->assertSame('failed', $run->get('status'));
    }

    #[Test]
    public function invalidStatusTransitionThrows(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run = $orchestrator->createRun(42);

        $this->expectException(\InvalidArgumentException::class);
        $orchestrator->completeRun($run);
    }

    #[Test]
    public function invalidTransitionFromCompletedThrows(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run = $orchestrator->createRun(42);
        $run->set('status', 'completed');

        $this->expectException(\InvalidArgumentException::class);
        $orchestrator->pauseRun($run);
    }

    #[Test]
    public function listRunsReturnsAllRuns(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $orchestrator->createRun(1);
        $orchestrator->createRun(2);

        $runs = $orchestrator->listRuns();
        $this->assertCount(2, $runs);
    }

    #[Test]
    public function listRunsFiltersByStatus(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run1 = $orchestrator->createRun(1);
        $orchestrator->createRun(2);

        // Transition run1 to running
        $run1->set('status', 'running');
        $this->entityTypeManager->getStorage('issue_run')->save($run1);

        $pending = $orchestrator->listRuns('pending');
        $this->assertCount(1, $pending);

        $running = $orchestrator->listRuns('running');
        $this->assertCount(1, $running);
    }

    #[Test]
    public function summarizeRunReturnsString(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run = $orchestrator->createRun(42);

        $summary = $orchestrator->summarizeRun($run);

        $this->assertStringContainsString('#42', $summary);
        $this->assertStringContainsString('Test issue', $summary);
        $this->assertStringContainsString('pending', $summary);
    }

    private EntityTypeManager $entityTypeManager;

    private function buildOrchestrator(): IssueOrchestrator
    {
        $gitHubClient = new class('fake', 'owner', 'repo') extends GitHubClient {
            protected function request(string $method, string $path, ?array $body = null): array
            {
                // Extract issue number from path
                if (preg_match('/issues\/(\d+)$/', $path, $matches)) {
                    $number = (int) $matches[1];
                } else {
                    $number = 1;
                }

                return [
                    'number' => $number,
                    'title' => 'Test issue',
                    'body' => 'Issue body',
                    'state' => 'open',
                    'milestone' => ['title' => 'v1.0'],
                    'labels' => [],
                    'assignees' => [],
                ];
            }
        };

        $db = \Waaseyaa\Database\PdoDatabase::createSqlite(':memory:');
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($db, $dispatcher) {
                (new \Waaseyaa\EntityStorage\SqlSchemaHandler($definition, $db))->ensureTable();

                return new \Waaseyaa\EntityStorage\SqlEntityStorage($definition, $db, $dispatcher);
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'issue_run',
            label: 'Issue Run',
            class: IssueRun::class,
            keys: ['id' => 'irid', 'uuid' => 'uuid', 'label' => 'issue_title'],
            fieldDefinitions: [
                'issue_number' => ['type' => 'integer', 'label' => 'Issue Number'],
                'issue_title' => ['type' => 'string', 'label' => 'Issue Title'],
                'issue_body' => ['type' => 'text_long', 'label' => 'Issue Body'],
                'milestone_title' => ['type' => 'string', 'label' => 'Milestone'],
                'workspace_id' => ['type' => 'integer', 'label' => 'Workspace ID'],
                'status' => ['type' => 'string', 'label' => 'Status'],
                'branch_name' => ['type' => 'string', 'label' => 'Branch Name'],
                'pr_url' => ['type' => 'string', 'label' => 'PR URL'],
                'last_agent_output' => ['type' => 'text_long', 'label' => 'Last Agent Output'],
                'event_log' => ['type' => 'text_long', 'label' => 'Event Log'],
            ],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'name' => ['type' => 'string', 'label' => 'Name'],
                'repo_path' => ['type' => 'string', 'label' => 'Repo Path'],
                'branch' => ['type' => 'string', 'label' => 'Branch'],
                'description' => ['type' => 'string', 'label' => 'Description'],
                'tenant_id' => ['type' => 'string', 'label' => 'Tenant ID'],
            ],
        ));

        return new IssueOrchestrator(
            entityTypeManager: $this->entityTypeManager,
            gitHubClient: $gitHubClient,
            pipeline: null,
            instructionBuilder: new IssueInstructionBuilder(),
            gitOperator: null,
        );
    }
}
