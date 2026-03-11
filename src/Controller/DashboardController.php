<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Support\DriftDetector;
use Twig\Environment;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;
use Waaseyaa\User\Middleware\CsrfMiddleware;

final class DashboardController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2).'/storage';
        $sessionStore = new BriefSessionStore($storageDir.'/brief-session.txt');

        // Always show last 24h for Day Brief. The session cursor is preserved
        // for future "new items" indicators but doesn't gate the main display.
        $since = new \DateTimeImmutable('-24 hours');

        // Assemble brief data
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

        $workspaceRepo = null;
        try {
            $workspaceRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('workspace'));
        } catch (\Throwable) {
        }

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $personRepo, $skillRepo, $workspaceRepo);
        $brief = $assembler->assemble('default', $since);

        $sessionStore->recordBriefAt(new \DateTimeImmutable);

        // Load chat sessions
        $chatSessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $sessionIds = $chatSessionStorage->getQuery()->execute();
        $allSessions = $chatSessionStorage->loadMultiple($sessionIds);
        usort($allSessions, fn ($a, $b) => ($b->get('created_at') ?? '') <=> ($a->get('created_at') ?? ''));
        $sessions = array_slice($allSessions, 0, 10);

        $twigSessions = array_map(fn ($s) => [
            'uuid' => $s->get('uuid'),
            'title' => $s->get('title') ?? 'New Chat',
            'created_at' => $s->get('created_at'),
        ], $sessions);

        $apiKey = getenv('ANTHROPIC_API_KEY');
        $apiConfigured = is_string($apiKey) && $apiKey !== '';
        $model = $_ENV['CLAUDE_MODEL'] ?? getenv('CLAUDE_MODEL') ?: 'claude-sonnet-4-6';

        $twigCommitments = array_map(fn ($c) => [
            'title' => $c->get('title'),
            'confidence' => $c->get('confidence') ?? 1.0,
            'due_date' => $c->get('due_date'),
        ], $brief['commitments']['pending']);

        $twigDrifting = array_map(fn ($c) => [
            'title' => $c->get('title'),
            'updated_at' => $c->get('updated_at'),
        ], $brief['commitments']['drifting']);

        // Twig rendering
        if ($this->twig !== null) {
            $html = $this->twig->render('dashboard.twig', array_merge($brief, [
                'pending_commitments' => $twigCommitments,
                'drifting_commitments' => $twigDrifting,
                'sessions' => $twigSessions,
                'api_configured' => $apiConfigured,
                'csrf_token' => CsrfMiddleware::token(),
                'model' => $model,
                'workspaces' => $brief['workspaces'] ?? [],
            ]));

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        // JSON fallback
        $jsonBrief = $brief;
        $jsonBrief['commitments']['pending'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['pending']);
        $jsonBrief['commitments']['drifting'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['drifting']);
        $jsonBrief['matched_skills'] = array_map(fn ($s) => $s->toArray(), $brief['matched_skills']);

        return new SsrResponse(
            content: json_encode([
                'brief' => $jsonBrief,
                'sessions' => $twigSessions,
                'api_configured' => $apiConfigured,
                'workspaces' => $brief['workspaces'] ?? [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
