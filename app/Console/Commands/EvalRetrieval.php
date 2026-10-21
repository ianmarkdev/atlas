<?php

namespace App\Console\Commands;

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Domain\Retrieval\Embeddings\EmbeddingFailed;
use App\Domain\Retrieval\HybridSearch;
use App\Domain\Retrieval\RetrievalIndexer;
use App\Domain\Retrieval\RetrievalQuery;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\EmbedRetrievalUnits;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('app:eval-retrieval
    {--min=0.85 : Fail when mean Recall@8 is below this}
    {--k=8 : Results per query}
    {--fixture= : Fixture file (default tests/Eval/fixtures/retrieval.php)}')]
#[Description('Measure retrieval quality (Recall@k, MRR) and cross-workspace leakage on a fixture library, inside a rolled-back transaction')]
class EvalRetrieval extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RetrievalIndexer $indexer, HybridSearch $search, TenantContext $tenant): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->components->error('The retrieval eval creates throwaway data and only runs in the local or testing environment.');

            return self::FAILURE;
        }

        /** @var array{candidates: array<string, array<string, mixed>>, queries: list<array{q: string, relevant: list<string>}>} $fixture */
        $fixture = require $this->option('fixture') ?: base_path('tests/Eval/fixtures/retrieval.php');
        $k = max(1, (int) $this->option('k'));

        DB::beginTransaction();

        try {
            [$profiles, $sources] = $this->seed($fixture['candidates']);

            foreach ($profiles as $profile) {
                $tenant->run($profile->workspace, function () use ($indexer, $profile): void {
                    $indexer->rebuild();
                    // Run inline: a dispatched job waits for a commit, and this transaction is rolled back.
                    app()->call([new EmbedRetrievalUnits($profile->workspace_id), 'handle']);
                });
            }

            $alex = $profiles['alex'];
            $rows = [];
            $recalls = [];
            $reciprocalRanks = [];
            $leaks = 0;
            $semantic = true;
            $reason = null;

            foreach ($fixture['queries'] as $query) {
                $result = $tenant->run($alex->workspace, fn () => $search->search(new RetrievalQuery($query['q'], $alex->id, $k)));
                $semantic = $semantic && $result->semantic;
                $reason ??= $result->semanticUnavailableReason;
                $relevant = array_map(fn (string $key): string => $sources[$key] ?? throw new RuntimeException("Unknown fixture key {$key}"), $query['relevant']);
                $found = [];
                $firstRank = null;

                foreach ($result->hits as $rank => $hit) {
                    $ref = "{$hit->unit->source_type}:{$hit->unit->source_id}";

                    if ($hit->unit->workspace_id !== $alex->workspace_id || ! in_array($ref, $sources, true) || str_starts_with((string) array_search($ref, $sources, true), 'sam.')) {
                        $leaks++;
                    }

                    if (in_array($ref, $relevant, true)) {
                        $found[] = $ref;
                        $firstRank ??= $rank + 1;
                    }
                }

                $recall = count(array_unique($found)) / max(1, count($relevant));
                $recalls[] = $recall;
                $reciprocalRanks[] = $firstRank === null ? 0.0 : 1 / $firstRank;
                $rows[] = [mb_strimwidth($query['q'], 0, 60, '…'), sprintf('%.2f', $recall), $firstRank ?? '—', count($result->hits)];
            }
        } catch (EmbeddingFailed $exception) {
            DB::rollBack();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        DB::rollBack();

        $this->table(['Query', "Recall@{$k}", 'First relevant at', 'Hits'], $rows);

        $meanRecall = array_sum($recalls) / max(1, count($recalls));
        $mrr = array_sum($reciprocalRanks) / max(1, count($reciprocalRanks));
        $this->components->twoColumnDetail('Mode', $semantic ? 'hybrid (keyword + embeddings)' : 'keyword only — '.($reason ?? 'embeddings unavailable'));
        $this->components->twoColumnDetail("Mean Recall@{$k}", sprintf('%.3f (target %.2f)', $meanRecall, (float) $this->option('min')));
        $this->components->twoColumnDetail('MRR', sprintf('%.3f', $mrr));
        $this->components->twoColumnDetail('Leaked results', (string) $leaks);

        if ($leaks > 0) {
            $this->components->error('Results from another workspace were returned.');

            return self::FAILURE;
        }

        if ($meanRecall < (float) $this->option('min')) {
            $this->components->warn('Recall is below the target.');

            return self::FAILURE;
        }

        $this->components->info('Retrieval meets the target.');

        return self::SUCCESS;
    }

    /**
     * Create the fixture's candidates and libraries (one workspace each).
     *
     * @param  array<string, array<string, mixed>>  $candidates
     * @return array{array<string, CandidateProfile>, array<string, string>} profiles by key, and `candidate.item` => `type:id`
     */
    private function seed(array $candidates): array
    {
        $profiles = [];
        $sources = [];
        $ref = fn (Model $model): string => "{$model->getMorphClass()}:{$model->getKey()}";

        foreach ($candidates as $key => $library) {
            $user = User::factory()->candidate()->create(['name' => ucfirst($key).' Eval', 'email' => "eval-{$key}-".uniqid().'@example.test']);
            $profile = CandidateProfile::withoutGlobalScopes()->where('user_id', $user->id)->sole();
            $profiles[$key] = $profile;

            $claim = function (string $statement, Employment|Project|null $subject) use ($profile, $user): Claim {
                $factory = Claim::factory()->forProfile($profile)->stating($statement)->verified($user);

                return ($subject === null ? $factory : $factory->about($subject))->create();
            };

            foreach ($library['jobs'] as $jobKey => $job) {
                $employment = Employment::factory()->forProfile($profile)->verified($user)->create([
                    'employer_name' => $job['employer'], 'title' => $job['title'], 'location' => $job['location'],
                    'start_date' => $job['start'], 'end_date' => $job['end'], 'summary' => $job['summary'],
                ]);
                $sources["{$key}.{$jobKey}"] = $ref($employment);

                foreach ($job['claims'] as $claimKey => $statement) {
                    $sources["{$key}.{$claimKey}"] = $ref($claim($statement, $employment));
                }

                foreach ($job['projects'] as $projectKey => $item) {
                    $project = Project::factory()->forProfile($profile)->verified($user)->create([
                        'employment_id' => $employment->id, 'name' => $item['name'], 'role' => $item['role'], 'summary' => $item['summary'],
                    ]);
                    $sources["{$key}.{$projectKey}"] = $ref($project);

                    foreach ($item['claims'] as $claimKey => $statement) {
                        $sources["{$key}.{$claimKey}"] = $ref($claim($statement, $project));
                    }
                }
            }

            foreach ($library['general'] as $claimKey => $statement) {
                $sources["{$key}.{$claimKey}"] = $ref($claim($statement, null));
            }

            foreach ($library['answers'] as $answerKey => $answer) {
                $entry = AnswerLibraryEntry::factory()->forProfile($profile)->approved($user)->answering($answer['a'])->create(['question' => $answer['q']]);
                $sources["{$key}.{$answerKey}"] = $ref($entry);
            }
        }

        return [$profiles, $sources];
    }
}
