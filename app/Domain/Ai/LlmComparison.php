<?php

namespace App\Domain\Ai;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Runs comparison samples through several providers via AiGateway (so every rule applies: the
 * external-data guard, budget, schema validation) and stores each output with its latency,
 * validation and grounding results for blind human scoring. Only the synthetic samples given
 * are sent; real applications are never used.
 */
class LlmComparison
{
    public function __construct(private AiGateway $gateway, private ClaimGroundingValidator $grounding) {}

    /**
     * @param  array<string, array{title: string, task: string, prompt: string, prompt_version?: int, variables: array<string, string>, evidence: array<string, array{status: string, text: string}>}>  $samples
     * @param  list<string>  $providers
     * @param  (callable(string $sampleKey, string $provider, LlmComparisonResult $result): void)|null  $progress
     * @return string the batch id
     */
    public function run(array $samples, array $providers, ?User $actor = null, ?callable $progress = null): string
    {
        $batch = (string) Str::ulid();

        foreach ($samples as $key => $sample) {
            // Shuffle per sample so "A" is not always the same provider.
            $order = $providers;
            shuffle($order);

            foreach ($order as $i => $provider) {
                $result = $this->runOne($batch, $key, $sample, $provider, range('A', 'Z')[$i] ?? 'Z', $actor);

                if ($progress !== null) {
                    $progress($key, $provider, $result);
                }
            }
        }

        return $batch;
    }

    /**
     * Render evidence for the prompt as tagged items.
     *
     * @param  array<string, array{status: string, text: string}>  $evidence
     */
    public static function evidenceBlock(array $evidence): string
    {
        $lines = [];

        foreach ($evidence as $id => $item) {
            $lines[] = sprintf('<item id="%s" status="%s">%s</item>', $id, $item['status'], htmlspecialchars($item['text'], ENT_NOQUOTES));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{title: string, task: string, prompt: string, prompt_version?: int, variables: array<string, string>, evidence: array<string, array{status: string, text: string}>}  $sample
     */
    private function runOne(string $batch, string $key, array $sample, string $provider, string $label, ?User $actor): LlmComparisonResult
    {
        $result = new LlmComparisonResult;
        $result->forceFill([
            'batch' => $batch,
            'sample_key' => $key,
            'task' => $sample['task'],
            'provider' => $provider,
            'blind_label' => $label,
            'validation_errors' => [],
            'grounding_flags' => [],
        ]);

        $variables = [...$sample['variables'], 'evidence' => self::evidenceBlock($sample['evidence'])];

        try {
            $generation = $this->gateway->run($sample['task'], $sample['prompt'], $variables, $actor, purpose: 'comparison', provider: $provider, promptVersion: $sample['prompt_version'] ?? null);

            $result->forceFill([
                'status' => 'succeeded',
                'model' => $generation->run->model,
                'output' => $generation->data,
                'grounding_flags' => $this->groundingOf($generation->data ?? [], $sample['evidence']),
                'latency_ms' => $generation->run->latency_ms,
                'input_tokens' => $generation->run->input_tokens,
                'output_tokens' => $generation->run->output_tokens,
                'generation_run_id' => $generation->run->id,
            ]);
        } catch (InvalidModelOutput $exception) {
            $result->forceFill(['status' => 'invalid_output', 'validation_errors' => $exception->errors, 'error_message' => $exception->getMessage(), 'generation_run_id' => $exception->generationRunId]);
        } catch (LlmConfigurationError|AiBudgetExceeded $exception) {
            $result->forceFill(['status' => 'skipped', 'error_message' => $exception->getMessage()]);
        } catch (LlmUnavailable $exception) {
            $result->forceFill(['status' => 'failed', 'error_message' => $exception->getMessage()]);
        }

        $result->save();

        return $result;
    }

    /**
     * Server-side label check of every claim the output makes (answer_writing segments, or
     * claim_check claims): what the model said vs. what the evidence allows.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, array{status: string, text: string}>  $evidence
     * @return list<array<string, mixed>>
     */
    private function groundingOf(array $output, array $evidence): array
    {
        $claims = [];

        foreach ((array) ($output['segments'] ?? []) as $segment) {
            foreach ((array) ($segment['claims'] ?? []) as $claim) {
                $claims[] = $claim;
            }
        }

        foreach ((array) ($output['claims'] ?? []) as $claim) {
            $claims[] = $claim;
        }

        $normalised = array_values(array_map(fn (mixed $claim): array => [
            'text' => (string) ($claim['text'] ?? ''),
            'classification' => (string) ($claim['classification'] ?? ''),
            'evidence_ids' => array_values(array_map(strval(...), (array) ($claim['evidence_ids'] ?? []))),
        ], array_filter($claims, is_array(...))));

        return $this->grounding->check($normalised, $evidence);
    }
}
