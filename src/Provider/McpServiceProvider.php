<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Support\Mcp\McpServiceAccount;
use Claudriel\Support\Mcp\McpToolExecutorAdapter;
use Claudriel\Support\Mcp\McpToolRegistryAdapter;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\Bridge\ToolExecutorInterface;
use Waaseyaa\Mcp\Bridge\ToolRegistryInterface;
use Waaseyaa\Mcp\McpController;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpRouteProvider;
use Waaseyaa\Mcp\McpServerCard;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Wires the waaseyaa/mcp package into Claudriel.
 *
 * Registers McpEndpoint as a singleton with BearerTokenAuth,
 * entity tools, traversal tools, editorial tools, and discovery tools
 * backed by McpController.
 */
final class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(McpServiceAccount::class, fn () => new McpServiceAccount);

        $this->singleton(McpAuthInterface::class, function () {
            $token = $_ENV['MCP_BEARER_TOKEN'] ?? getenv('MCP_BEARER_TOKEN') ?: '';
            if ($token === '') {
                // Fallback to CLAUDRIEL_API_KEY for backward compat
                $token = $_ENV['CLAUDRIEL_API_KEY'] ?? getenv('CLAUDRIEL_API_KEY') ?: '';
            }

            $account = $this->resolve(McpServiceAccount::class);
            \assert($account instanceof McpServiceAccount);

            if ($token === '' || $token === 'change-me-to-a-random-string') {
                return new BearerTokenAuth([]);
            }

            return new BearerTokenAuth([$token => $account]);
        });

        $this->singleton(McpController::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManagerInterface::class);
            \assert($entityTypeManager instanceof EntityTypeManagerInterface);

            $serializer = new ResourceSerializer($entityTypeManager);

            $account = $this->resolve(McpServiceAccount::class);
            \assert($account instanceof McpServiceAccount);

            $embeddingStorage = $this->resolveEmbeddingStorage();

            $embeddingProvider = EmbeddingProviderFactory::fromConfig([
                'ai' => [
                    'embedding_provider' => $_ENV['EMBEDDING_PROVIDER'] ?? getenv('EMBEDDING_PROVIDER') ?: '',
                    'openai_api_key' => $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '',
                    'ollama_endpoint' => $_ENV['OLLAMA_ENDPOINT'] ?? getenv('OLLAMA_ENDPOINT') ?: '',
                ],
            ]);

            $relationshipTraversal = $this->resolveRelationshipTraversal($entityTypeManager);

            return new McpController(
                entityTypeManager: $entityTypeManager,
                serializer: $serializer,
                accessHandler: new EntityAccessHandler([]),
                account: $account,
                embeddingStorage: $embeddingStorage,
                embeddingProvider: $embeddingProvider,
                relationshipTraversal: $relationshipTraversal,
            );
        });

        $this->singleton(ToolRegistryInterface::class, function () {
            $controller = $this->resolve(McpController::class);
            \assert($controller instanceof McpController);

            return new McpToolRegistryAdapter($controller);
        });

        $this->singleton(ToolExecutorInterface::class, function () {
            $controller = $this->resolve(McpController::class);
            \assert($controller instanceof McpController);

            return new McpToolExecutorAdapter($controller);
        });

        $this->singleton(McpEndpoint::class, function () {
            $auth = $this->resolve(McpAuthInterface::class);
            \assert($auth instanceof McpAuthInterface);

            $registry = $this->resolve(ToolRegistryInterface::class);
            \assert($registry instanceof ToolRegistryInterface);

            $executor = $this->resolve(ToolExecutorInterface::class);
            \assert($executor instanceof ToolExecutorInterface);

            return new McpEndpoint(
                auth: $auth,
                registry: $registry,
                executor: $executor,
            );
        });

        $this->singleton(McpServerCard::class, fn () => new McpServerCard(
            name: 'Claudriel',
            version: '1.0.0',
            endpoint: '/mcp',
        ));
    }

    public function routes(WaaseyaaRouter $router): void
    {
        $provider = new McpRouteProvider;
        $provider->registerRoutes($router);
    }

    private function resolveEmbeddingStorage(): EmbeddingStorageInterface
    {
        $existing = $this->resolve(EmbeddingStorageInterface::class);
        if ($existing instanceof EmbeddingStorageInterface) {
            return $existing;
        }

        $storageDir = \dirname(__DIR__, 2).'/storage';
        if (! \is_dir($storageDir) && ! mkdir($storageDir, 0o755, true)) {
            error_log('MCP: could not create storage/ directory');
        }

        $pdo = new \PDO('sqlite:'.$storageDir.'/embeddings.sqlite');
        $pdo->exec('PRAGMA journal_mode=WAL');

        return new SqliteEmbeddingStorage($pdo);
    }

    private function resolveRelationshipTraversal(
        EntityTypeManagerInterface $entityTypeManager,
    ): ?RelationshipTraversalService {
        $database = $this->resolve(DatabaseInterface::class);
        if (! $database instanceof DatabaseInterface) {
            return null;
        }

        return new RelationshipTraversalService(
            entityTypeManager: $entityTypeManager,
            database: $database,
        );
    }
}
