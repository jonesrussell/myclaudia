<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Support\BriefSignal;
use Claudriel\Support\DriftDetector;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Entity\EntityTypeManager;

final class BriefStreamController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    /**
     * GET /stream/brief -- SSE stream that pushes brief updates when signal file changes.
     */
    public function stream(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): StreamedResponse
    {
        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2) . '/storage';
        $signalFile = $storageDir . '/brief-signal.txt';

        return new StreamedResponse(
            function () use ($signalFile): void {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                $this->streamLoop($signalFile);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * The SSE loop. Extracted for testability: all I/O goes through callbacks.
     */
    public function streamLoop(
        string $signalFile,
        ?\Closure $outputCallback = null,
        ?\Closure $flushCallback = null,
        ?\Closure $shouldStop = null,
        ?\Closure $sleepCallback = null,
    ): void {
        $output = $outputCallback ?? static function (string $data): void { echo $data; };
        $flush = $flushCallback ?? static function (): void {
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };
        $shouldStop = $shouldStop ?? static fn (): bool => connection_aborted() === 1;
        $sleep = $sleepCallback ?? static function (): void { usleep(2_000_000); };

        $signal = new BriefSignal($signalFile);
        $lastMtime = 0;
        $lastKeepalive = time();
        $startTime = time();
        $maxDuration = 300; // 5 minutes

        $output("retry: 3000\n\n");
        $flush();

        // Emit initial brief immediately
        $briefJson = $this->assembleBriefJson();
        $output("event: brief-update\ndata: {$briefJson}\n\n");
        $flush();
        $lastMtime = $signal->lastModified();

        while (!$shouldStop()) {
            // Check for signal changes
            if ($signal->hasChangedSince($lastMtime)) {
                $lastMtime = $signal->lastModified();
                $briefJson = $this->assembleBriefJson();
                $output("event: brief-update\ndata: {$briefJson}\n\n");
                $flush();
                ($sleepCallback ?? static function (): void { usleep(200_000); })();
            }

            // Keepalive every 15 seconds
            $now = time();
            if (($now - $lastKeepalive) >= 15) {
                $output(": keepalive\n\n");
                $flush();
                $lastKeepalive = $now;
            }

            // Disconnect after max duration
            if (($now - $startTime) >= $maxDuration) {
                break;
            }

            $sleep();
        }
    }

    private function assembleBriefJson(): string
    {
        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
        $skillStorage = $this->entityTypeManager->getStorage('skill');

        $eventRepo = new StorageRepositoryAdapter($eventStorage);
        $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
        $skillRepo = new StorageRepositoryAdapter($skillStorage);
        $driftDetector = new DriftDetector($commitmentRepo);

        $personRepo = null;
        try {
            $personRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('person'));
        } catch (\Throwable) {
        }

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $personRepo, $skillRepo);
        $brief = $assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        $jsonBrief = $brief;
        $jsonBrief['commitments']['pending'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['pending']);
        $jsonBrief['commitments']['drifting'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['drifting']);
        $jsonBrief['matched_skills'] = array_map(fn ($s) => $s->toArray(), $brief['matched_skills']);

        return json_encode($jsonBrief, JSON_THROW_ON_ERROR);
    }
}
