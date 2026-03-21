<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\DayBrief;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Support\DriftDetector;
use Claudriel\Support\FollowUpMonitor;
use Claudriel\Temporal\AtomicTimeService;
use Claudriel\Temporal\Clock\MonotonicClockInterface;
use Claudriel\Temporal\Clock\WallClockInterface;
use Claudriel\Temporal\RequestTimeSnapshotStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class DayBriefAssemblerGitHubTest extends TestCase
{
    private EntityRepository $eventRepo;

    private EntityRepository $commitmentRepo;

    private DayBriefAssembler $assembler;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher;

        $this->eventRepo = new EntityRepository(
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
        $this->commitmentRepo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $this->assembler = new DayBriefAssembler(
            $this->eventRepo,
            $this->commitmentRepo,
            new DriftDetector($this->commitmentRepo),
            null,
            null,
            null,
            null,
            null,
            new AtomicTimeService(
                wallClock: new class implements WallClockInterface
                {
                    public function now(): \DateTimeImmutable
                    {
                        return new \DateTimeImmutable('today 08:00:00', new \DateTimeZone('UTC'));
                    }
                },
                monotonicClock: new class implements MonotonicClockInterface
                {
                    public function now(): int
                    {
                        return 1000;
                    }
                },
                snapshotStore: new RequestTimeSnapshotStore,
                defaultTimezone: 'UTC',
            ),
            null,
            new FollowUpMonitor($this->eventRepo),
        );
    }

    public function test_brief_includes_github_section(): void
    {
        $this->seedGitHubEvent('github_mention', 1);

        $result = $this->assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        self::assertArrayHasKey('github', $result);
        self::assertNotEmpty($result['github']['mentions']);
        self::assertSame('jonesrussell/claudriel', $result['github']['mentions'][0]['repo']);
        self::assertSame('Test issue', $result['github']['mentions'][0]['title']);
        self::assertSame('octocat', $result['github']['mentions'][0]['from']);
        self::assertSame('Issue', $result['github']['mentions'][0]['subject_type']);
    }

    public function test_brief_counts_include_github(): void
    {
        $this->seedGitHubEvent('github_mention', 1);
        $this->seedGitHubEvent('github_review_request', 2);

        $result = $this->assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        self::assertArrayHasKey('github', $result['counts']);
        self::assertSame(2, $result['counts']['github']);
    }

    public function test_brief_without_github_events_omits_section(): void
    {
        $result = $this->assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        self::assertArrayNotHasKey('github', $result);
        self::assertArrayNotHasKey('github', $result['counts']);
    }

    public function test_github_events_not_in_notifications_bucket(): void
    {
        $this->seedGitHubEvent('github_mention', 1);

        $result = $this->assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        self::assertEmpty($result['notifications']);
    }

    public function test_github_review_request_captured(): void
    {
        $this->seedGitHubEvent('github_review_request', 1);

        $result = $this->assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        self::assertNotEmpty($result['github']['review_requests']);
        self::assertSame('jonesrussell/claudriel', $result['github']['review_requests'][0]['repo']);
        self::assertSame('octocat', $result['github']['review_requests'][0]['from']);
    }

    public function test_github_ci_failure_captured(): void
    {
        $this->seedGitHubEvent('github_ci', 1);

        $result = $this->assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        self::assertNotEmpty($result['github']['ci_failures']);
        self::assertSame('jonesrussell/claudriel', $result['github']['ci_failures'][0]['repo']);
    }

    public function test_github_activity_captured(): void
    {
        $this->seedGitHubEvent('github_activity', 1);

        $result = $this->assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        self::assertNotEmpty($result['github']['activity']);
        self::assertSame('jonesrussell/claudriel', $result['github']['activity'][0]['repo']);
        self::assertArrayHasKey('type', $result['github']['activity'][0]);
    }

    public function test_github_assignment_goes_to_activity(): void
    {
        $this->seedGitHubEvent('github_assignment', 1);

        $result = $this->assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        self::assertNotEmpty($result['github']['activity']);
    }

    public function test_github_activity_capped_at_ten(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->seedGitHubEvent('github_activity', $i);
        }

        $result = $this->assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        self::assertCount(10, $result['github']['activity']);
        // Count reflects the pre-cap total
        self::assertSame(15, $result['counts']['github']);
    }

    private function seedGitHubEvent(string $category, int $eid): void
    {
        $this->eventRepo->save(new McEvent([
            'eid' => $eid,
            'source' => 'github',
            'type' => 'mention',
            'category' => $category,
            'payload' => json_encode([
                'repo' => 'jonesrussell/claudriel',
                'title' => 'Test issue',
                'from_name' => 'octocat',
                'github_username' => 'octocat',
                'subject_type' => 'Issue',
            ]),
            'occurred' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
            'tenant_id' => 'default',
        ]));
    }
}
