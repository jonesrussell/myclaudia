<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class AiVectorServiceProvider extends ServiceProvider
{
    private ?EmbeddingStorageInterface $storage = null;

    private ?EmbeddingProviderInterface $embeddingProvider = null;

    public function register(): void {}

    public function boot(): void
    {
        $dispatcher = $this->resolve(EventDispatcherInterface::class);
        if (! $dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $storage = $this->getStorage();
        $provider = $this->getEmbeddingProvider();

        $embeddingListener = new EntityEmbeddingListener(
            storage: $storage,
            embeddingProvider: $provider,
        );

        $dispatcher->addListener(
            EntityEvents::POST_SAVE->value,
            [$embeddingListener, 'onPostSave'],
        );

        $cleanupListener = new EntityEmbeddingCleanupListener($storage);

        $dispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            [$cleanupListener, 'onPostDelete'],
        );
    }

    public function getStorage(): EmbeddingStorageInterface
    {
        if ($this->storage === null) {
            $this->storage = new SqliteEmbeddingStorage($this->createPdo());
        }

        return $this->storage;
    }

    public function getEmbeddingProvider(): ?EmbeddingProviderInterface
    {
        if ($this->embeddingProvider === null) {
            $this->embeddingProvider = EmbeddingProviderFactory::fromConfig([
                'ai' => [
                    'embedding_provider' => getenv('EMBEDDING_PROVIDER') ?: '',
                    'openai_api_key' => getenv('OPENAI_API_KEY') ?: '',
                    'openai_embedding_model' => getenv('OPENAI_EMBEDDING_MODEL') ?: 'text-embedding-3-small',
                    'ollama_endpoint' => getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434/api/embeddings',
                    'ollama_model' => getenv('OLLAMA_MODEL') ?: 'nomic-embed-text',
                ],
            ]);
        }

        return $this->embeddingProvider;
    }

    private function createPdo(): \PDO
    {
        $storageDir = dirname(__DIR__, 2).'/storage';
        if (! is_dir($storageDir) && ! mkdir($storageDir, 0o755, true)) {
            error_log('AiVector: could not create storage/ directory, falling back to in-memory');

            return new \PDO('sqlite::memory:');
        }

        $pdo = new \PDO('sqlite:'.$storageDir.'/embeddings.sqlite');
        $pdo->exec('PRAGMA journal_mode=WAL');

        return $pdo;
    }
}
