<?php

namespace App\Domain\Retrieval\Embeddings;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * A local Ollama server (D11): `POST /api/embed` with a batch of inputs. Ollama returns
 * L2-normalised vectors.
 */
class OllamaEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private string $url,
        private string $model,
        private int $dimensions,
        private int $timeout,
    ) {}

    public function embedDocuments(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        try {
            $response = Http::baseUrl($this->url)
                ->timeout($this->timeout)
                ->retry(2, 500, throw: true)
                ->acceptJson()
                ->post('/api/embed', ['model' => $this->model, 'input' => $texts])
                ->throw();
        } catch (ConnectionException $exception) {
            throw new EmbeddingFailed("Ollama is not reachable at {$this->url}. Is it running? (ollama serve)", previous: $exception);
        } catch (RequestException $exception) {
            throw new EmbeddingFailed("Ollama could not embed with \"{$this->model}\" (HTTP {$exception->response->status()}). Has the model been pulled? (ollama pull {$this->model})", previous: $exception);
        }

        $vectors = $response->json('embeddings');

        if (! is_array($vectors) || count($vectors) !== count($texts)) {
            throw new EmbeddingFailed('Ollama returned '.(is_array($vectors) ? count($vectors) : 'no').' vectors for '.count($texts).' texts.');
        }

        return array_map(fn (mixed $vector): array => $this->checked($vector), array_values($vectors));
    }

    public function embedQuery(string $text): array
    {
        return $this->embedDocuments([$text])[0];
    }

    public function spec(): array
    {
        return ['provider' => 'ollama', 'model' => $this->model, 'dimensions' => $this->dimensions];
    }

    /**
     * @return list<float>
     */
    private function checked(mixed $vector): array
    {
        if (! is_array($vector) || count($vector) !== $this->dimensions) {
            throw new EmbeddingFailed("Expected {$this->dimensions}-dimensional vectors from \"{$this->model}\", got ".(is_array($vector) ? count($vector) : 'none').'. Check OLLAMA_EMBEDDING_DIMENSIONS.');
        }

        return array_map(fn (mixed $value): float => (float) $value, array_values($vector));
    }
}
