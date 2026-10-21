<?php

namespace App\Console\Commands;

use App\Domain\Retrieval\Embeddings\EmbeddingFailed;
use App\Domain\Retrieval\Embeddings\EmbeddingProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:check-embeddings {--runs=5 : How many timed single-query calls to make}')]
#[Description('Check the embedding provider (S2): reachable, dimensions, speed, and whether related texts score closer than unrelated ones')]
class CheckEmbeddings extends Command
{
    private const SAMPLES = [
        'query' => 'Experience making background jobs faster and more reliable',
        'related' => 'Cut the nightly invoice run from 4 hours to 20 minutes by moving it to queued, idempotent jobs.',
        'unrelated' => 'Speaks fluent German and enjoys hiking in the Alps.',
    ];

    /**
     * Execute the console command.
     */
    public function handle(EmbeddingProvider $provider): int
    {
        $spec = $provider->spec();
        $this->components->info("Provider: {$spec['provider']} · model {$spec['model']} · {$spec['dimensions']} dimensions");

        try {
            $started = hrtime(true);
            [$query, $related, $unrelated] = $provider->embedDocuments(array_values(self::SAMPLES));
            $batchMs = (hrtime(true) - $started) / 1e6;

            $timings = [];

            foreach (range(1, max(1, (int) $this->option('runs'))) as $_) {
                $started = hrtime(true);
                $provider->embedQuery(self::SAMPLES['query']);
                $timings[] = (hrtime(true) - $started) / 1e6;
            }
        } catch (EmbeddingFailed $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        sort($timings);
        $relatedScore = self::cosine($query, $related);
        $unrelatedScore = self::cosine($query, $unrelated);

        $this->table(['Check', 'Result'], [
            ['Dimensions returned', (string) count($query)],
            ['Batch of 3 (first call, includes model load)', sprintf('%.0f ms', $batchMs)],
            ['Single query, median of '.count($timings), sprintf('%.0f ms', $timings[intdiv(count($timings), 2)])],
            ['Similarity: query ↔ related', sprintf('%.3f', $relatedScore)],
            ['Similarity: query ↔ unrelated', sprintf('%.3f', $unrelatedScore)],
        ]);

        if ($relatedScore <= $unrelatedScore) {
            $this->components->warn('The related text did not score higher than the unrelated one. Is this a real embedding model?');

            return self::FAILURE;
        }

        $this->components->info('Embeddings look usable.');

        return self::SUCCESS;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value ** 2;
            $normB += $b[$i] ** 2;
        }

        return $dot / max(1e-12, sqrt($normA) * sqrt($normB));
    }
}
