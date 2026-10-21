<?php

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Domain\Retrieval\Embedding;
use App\Domain\Retrieval\EmbeddingModel;
use App\Domain\Retrieval\Embeddings\EmbeddingFailed;
use App\Domain\Retrieval\Embeddings\EmbeddingProvider;
use App\Domain\Retrieval\Embeddings\OllamaEmbeddingProvider;
use App\Domain\Retrieval\HybridSearch;
use App\Domain\Retrieval\RetrievalQuery;
use App\Domain\Retrieval\RetrievalUnit;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function unitOf(User $candidate, mixed $source): ?RetrievalUnit
{
    return inWorkspaceOf($candidate, fn () => RetrievalUnit::query()
        ->where('source_type', $source->getMorphClass())
        ->where('source_id', $source->getKey())
        ->first());
}

/**
 * @return list<string> search texts of the hits, best first
 */
function searchFor(User $candidate, string $text, int $limit = 8): array
{
    return inWorkspaceOf($candidate, fn () => array_map(
        fn ($hit) => $hit->unit->search_text,
        app(HybridSearch::class)->search(new RetrievalQuery($text, profileOf($candidate)->id, $limit))->hits,
    ));
}

describe('index', function () {
    test('a bullet point is indexed with its job as context, and re-indexed when reworded', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create([
            'title' => 'Senior Backend Developer', 'employer_name' => 'Northwind Payments', 'start_date' => '2021-03-01', 'end_date' => null,
        ]);
        $claim = claimOf($candidate, 'Cut the invoice run from 4 hours to 20 minutes', $employment);

        $unit = unitOf($candidate, $claim);
        expect($unit?->unit_type)->toBe('claim')
            ->and($unit?->search_text)->toBe('Senior Backend Developer at Northwind Payments (2021–present): Cut the invoice run from 4 hours to 20 minutes')
            ->and($unit?->status)->toBe('verified')
            ->and($unit?->is_active)->toBeTrue()
            ->and($unit?->parent_type)->toBe('employment')
            ->and($unit?->specificity_score)->toBeGreaterThan(0.5);

        $this->actingAs($candidate)->put(route('knowledge.claims.update', $claim), [
            'kind' => 'achievement',
            'statement' => 'Cut the nightly invoice run from 4 hours to 20 minutes',
            'lock_version' => 0,
        ]);

        $updated = unitOf($candidate, $claim);
        expect($updated?->search_text)->toEndWith(': Cut the nightly invoice run from 4 hours to 20 minutes')
            ->and($updated?->content_hash)->not->toBe($unit?->content_hash);
    });

    test('renaming a job re-indexes its bullet points and projects', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        $project = Project::factory()->forProfile(profileOf($candidate))->verified($candidate)->create(['employment_id' => $employment->id, 'name' => 'Billing API']);
        $claim = claimOf($candidate, 'Designed the webhook retries', $project);

        $employment->forceFill(['employer_name' => 'Renamed Ltd'])->save();

        expect(unitOf($candidate, $project)?->search_text)->toContain('at Renamed Ltd')
            ->and(unitOf($candidate, $claim)?->search_text)->toContain('at Renamed Ltd');
    });

    test('archived and rejected bullet points and draft answers are not searchable', function () {
        $candidate = candidateWithTwoFactor();
        $claim = claimOf($candidate, 'Led a team of four');
        $draft = AnswerLibraryEntry::factory()->forProfile(profileOf($candidate))->answering('One month.')->create();
        $approved = AnswerLibraryEntry::factory()->forProfile(profileOf($candidate))->approved($candidate)->answering('Two weeks.')->create(['question' => 'Notice period?']);

        $claim->forceFill(['status' => ClaimStatus::Archived])->save();

        expect(unitOf($candidate, $claim)?->is_active)->toBeFalse()
            ->and(unitOf($candidate, $draft)?->is_active)->toBeFalse()
            ->and(unitOf($candidate, $approved)?->search_text)->toBe("Question: Notice period?\nAnswer: Two weeks.")
            ->and(unitOf($candidate, $approved)?->is_active)->toBeTrue();
    });
});

describe('embeddings', function () {
    test('active units are embedded once; unchanged text is not embedded again, changed text is', function () {
        $candidate = candidateWithTwoFactor();
        $claim = claimOf($candidate, 'Built the billing API');
        $unit = unitOf($candidate, $claim);

        $embedding = inWorkspaceOf($candidate, fn () => Embedding::query()->where('retrieval_unit_id', $unit->id)->sole());
        expect($embedding->content_hash)->toBe($unit->content_hash)
            ->and(EmbeddingModel::query()->sole()->only(['provider', 'is_active_for_write', 'is_active_for_read']))
            ->toBe(['provider' => 'fake', 'is_active_for_write' => true, 'is_active_for_read' => true]);

        $claim->forceFill(['lock_version' => 5])->save();
        expect(inWorkspaceOf($candidate, fn () => Embedding::query()->whereKey($embedding->id)->value('created_at')))->toEqual($embedding->created_at);

        $this->actingAs($candidate)->put(route('knowledge.claims.update', $claim), ['kind' => 'achievement', 'statement' => 'Built the payments API', 'lock_version' => 5]);
        expect(inWorkspaceOf($candidate, fn () => Embedding::query()->where('retrieval_unit_id', $unit->id)->value('content_hash')))
            ->toBe(unitOf($candidate, $claim)->content_hash);
    });

    test('the Ollama provider posts a batch and checks the vectors it gets back', function () {
        Http::fake(['127.0.0.1:11434/api/embed' => Http::response(['embeddings' => [[0.6, 0.8], [1.0, 0.0]]])]);
        $provider = new OllamaEmbeddingProvider('http://127.0.0.1:11434', 'bge-m3', 2, 5);

        expect($provider->embedDocuments(['first', 'second']))->toBe([[0.6, 0.8], [1.0, 0.0]]);
        Http::assertSent(fn (Request $request) => $request['model'] === 'bge-m3' && $request['input'] === ['first', 'second']);
    });

    test('vectors of the wrong size are refused', function () {
        Http::fake(['127.0.0.1:11434/api/embed' => Http::response(['embeddings' => [[0.6, 0.8]]])]);

        expect(fn () => (new OllamaEmbeddingProvider('http://127.0.0.1:11434', 'bge-m3', 1024, 5))->embedQuery('x'))
            ->toThrow(EmbeddingFailed::class, 'Expected 1024-dimensional vectors');
    });

    test('an unreachable Ollama gives an actionable error', function () {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        expect(fn () => (new OllamaEmbeddingProvider('http://127.0.0.1:11434', 'bge-m3', 1024, 5))->embedQuery('x'))
            ->toThrow(EmbeddingFailed::class, 'Ollama is not reachable');
    });
});

describe('search', function () {
    test('hybrid search finds bullet points by meaning-ish and by keyword', function () {
        $candidate = candidateWithTwoFactor();
        claimOf($candidate, 'Cut the nightly invoice run from 4 hours to 20 minutes with queued jobs');
        claimOf($candidate, 'Speaks fluent German');
        claimOf($candidate, 'Mentored two junior developers');

        expect(searchFor($candidate, 'queued background jobs')[0])->toContain('invoice run')
            ->and(searchFor($candidate, 'German language'))->toHaveCount(1);
    });

    test('search never returns another workspace\'s or another profile\'s units', function () {
        $candidate = candidateWithTwoFactor();
        $other = candidateWithTwoFactor();
        claimOf($candidate, 'Built the Kafka event pipeline');
        claimOf($other, 'Built the Kafka event pipeline at a different company');

        $texts = searchFor($candidate, 'Kafka event pipeline');
        expect($texts)->toBe(['Built the Kafka event pipeline']);

        $foreignProfileQuery = fn () => app(HybridSearch::class)->search(new RetrievalQuery('Kafka', profileOf($other)->id))->hits;
        expect(inWorkspaceOf($candidate, $foreignProfileQuery))->toBe([]);
    });

    test('a job summary gives way to its own bullet point', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create([
            'title' => 'Platform Engineer', 'employer_name' => 'Contoso', 'summary' => 'Ran the Kubernetes platform.',
        ]);
        claimOf($candidate, 'Migrated 40 services to Kubernetes', $employment);

        $texts = searchFor($candidate, 'Kubernetes');

        expect($texts)->toHaveCount(1)
            ->and($texts[0])->toEndWith(': Migrated 40 services to Kubernetes');
    });

    test('of two near-identical statements the verified one wins and the duplicate is dropped', function () {
        $candidate = candidateWithTwoFactor();
        // Same words in another order: a different statement, but the same (word-hash) vector.
        $proposed = Claim::factory()->forProfile(profileOf($candidate))->stating('The Terraform modules wrote')->create();
        $verified = claimOf($candidate, 'Wrote the Terraform modules');

        $hits = inWorkspaceOf($candidate, fn () => app(HybridSearch::class)->search(new RetrievalQuery('Terraform modules', profileOf($candidate)->id))->hits);

        expect($hits)->toHaveCount(1)
            ->and($hits[0]->unit->source_id)->toBe($verified->id)
            ->and($hits[0]->unit->source_id)->not->toBe($proposed->id);
    });

    test('when the embedding provider fails, keyword search still answers', function () {
        $candidate = candidateWithTwoFactor();
        claimOf($candidate, 'Wrote the Terraform modules');

        app()->instance(EmbeddingProvider::class, new class implements EmbeddingProvider
        {
            public function embedDocuments(array $texts): array
            {
                throw new EmbeddingFailed('down');
            }

            public function embedQuery(string $text): array
            {
                throw new EmbeddingFailed('down');
            }

            public function spec(): array
            {
                return ['provider' => 'fake', 'model' => 'word-hash', 'dimensions' => 1024];
            }
        });

        $result = inWorkspaceOf($candidate, fn () => app(HybridSearch::class)->search(new RetrievalQuery('Terraform', profileOf($candidate)->id)));

        expect($result->semantic)->toBeFalse()
            ->and($result->semanticUnavailableReason)->toBe('down')
            ->and($result->hits)->toHaveCount(1);
    });
});

test('the rebuild command indexes and embeds every workspace', function () {
    $candidate = candidateWithTwoFactor();
    $claim = claimOf($candidate, 'Built the billing API');
    inWorkspaceOf($candidate, fn () => RetrievalUnit::query()->delete());

    $this->artisan('app:rebuild-retrieval-index', ['--embed' => true])->assertSuccessful();

    $unit = unitOf($candidate, $claim);
    expect($unit)->not->toBeNull()
        ->and(inWorkspaceOf($candidate, fn () => Embedding::query()->where('retrieval_unit_id', $unit->id)->exists()))->toBeTrue();
});

test('the embeddings check reports a usable model', function () {
    Http::fake(['*/api/embed' => fn (Request $request) => Http::response([
        'embeddings' => count($request['input']) === 3 ? [[1.0, 0.0], [0.9, 0.1], [0.0, 1.0]] : [[1.0, 0.0]],
    ])]);
    config(['embeddings.provider' => 'ollama', 'embeddings.ollama.dimensions' => 2]);
    app()->forgetInstance(EmbeddingProvider::class);

    $this->artisan('app:check-embeddings', ['--runs' => 1])
        ->expectsOutputToContain('Embeddings look usable.')
        ->assertSuccessful();
});
