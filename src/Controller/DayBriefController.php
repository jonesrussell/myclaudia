<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Support\DriftDetector;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

/**
 * Web controller for the daily brief JSON endpoint.
 *
 * The HttpKernel instantiates app controllers as new $class($entityTypeManager, $twig)
 * and expects SsrResponse with public content/statusCode/headers properties.
 */
final class DayBriefController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $storageDir   = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2) . '/storage';
        $sessionStore = new BriefSessionStore($storageDir . '/brief-session.txt');

        $since = new \DateTimeImmutable('-24 hours');

        $assembler = $this->buildAssembler();
        $brief = $assembler->assemble('default', $since);

        $wantsJson = false;
        if ($httpRequest !== null) {
            $accept = $httpRequest->headers->get('Accept', '');
            $wantsJson = $httpRequest->getRequestFormat('') === 'json'
                || str_contains($accept, 'application/json')
                || str_contains($accept, 'application/vnd.api+json');
        }

        if (!$wantsJson) {
            $sessionStore->recordBriefAt(new \DateTimeImmutable());
        }

        if ($this->twig !== null && !$wantsJson) {
            $html = $this->twig->render('day-brief.html.twig', $brief);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        $jsonBrief = $brief;
        $jsonBrief['commitments']['pending'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['pending']);
        $jsonBrief['commitments']['drifting'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['drifting']);
        $jsonBrief['matched_skills'] = array_map(fn ($s) => $s->toArray(), $brief['matched_skills']);

        return new SsrResponse(
            content: json_encode($jsonBrief, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function buildAssembler(): DayBriefAssembler
    {
        $eventRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('mc_event'));
        $commitmentRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('commitment'));

        $personRepo = null;
        try {
            $personRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('person'));
        } catch (\Throwable) {
            // person entity type may not be registered in tests
        }

        return new DayBriefAssembler(
            $eventRepo,
            $commitmentRepo,
            new DriftDetector($commitmentRepo),
            $personRepo,
        );
    }
}
