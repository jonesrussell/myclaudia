<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Entity\Integration;
use Claudriel\Ingestion\EventHandler;
use Claudriel\Ingestion\GitHubNotificationNormalizer;
use Claudriel\Support\GitHubTokenManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:github:sync', description: 'Fetch GitHub notifications and ingest as events')]
final class GitHubSyncCommand extends Command
{
    public function __construct(
        private readonly GitHubTokenManagerInterface $tokenManager,
        private readonly EventHandler $eventHandler,
        private readonly GitHubNotificationNormalizer $normalizer,
        private readonly EntityRepositoryInterface $integrationRepo,
        private readonly string $defaultTenantId,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $accounts = $this->findAccountsWithGitHub();
        if ($accounts === []) {
            $output->writeln('<comment>No active GitHub integrations found, skipping sync</comment>');

            return Command::SUCCESS;
        }

        $totalCreated = 0;
        foreach ($accounts as $accountId) {
            try {
                $token = $this->tokenManager->getValidAccessToken($accountId);
            } catch (\RuntimeException $e) {
                $output->writeln("<comment>Skipping account {$accountId}: {$e->getMessage()}</comment>");

                continue;
            }

            $notifications = $this->fetchNotifications($token, $accountId);
            if ($notifications === null) {
                $output->writeln("<comment>GitHub API error for account {$accountId}, will retry next cycle</comment>");

                continue;
            }

            foreach ($notifications as $raw) {
                $envelope = $this->normalizer->normalize($raw, $this->defaultTenantId);
                $this->eventHandler->handle($envelope);
                $totalCreated++;
            }
        }

        $output->writeln("<info>GitHub sync: {$totalCreated} events processed across ".count($accounts).' account(s)</info>');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function findAccountsWithGitHub(): array
    {
        $integrations = $this->integrationRepo->findBy([
            'provider' => 'github',
            'status' => 'active',
        ]);

        $accountIds = [];
        foreach ($integrations as $integration) {
            assert($integration instanceof Integration);
            $accountId = (string) $integration->get('account_id');
            if ($accountId !== '' && ! in_array($accountId, $accountIds, true)) {
                $accountIds[] = $accountId;
            }
        }

        return $accountIds;
    }

    private function fetchNotifications(string $token, string $accountId): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$token}\r\nUser-Agent: Claudriel\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('https://api.github.com/notifications', false, $context);
        if ($response === false) {
            return null;
        }

        /** @phpstan-ignore isset.variable */
        $statusCode = $this->parseHttpStatusCode($http_response_header ?? []);
        if ($statusCode === 401) {
            $this->tokenManager->markRevoked($accountId);

            return null;
        }
        if ($statusCode === 403) {
            return null;
        }

        return json_decode($response, true) ?: [];
    }

    /**
     * @param list<string> $headers
     */
    private function parseHttpStatusCode(array $headers): int
    {
        $httpCode = 0;

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $header, $m)) {
                $httpCode = (int) $m[1];
            }
        }

        return $httpCode;
    }
}
