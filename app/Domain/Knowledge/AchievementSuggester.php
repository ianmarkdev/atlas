<?php

namespace App\Domain\Knowledge;

use App\Domain\Ai\ClaimGroundingValidator;
use App\Domain\Shared\PlainText;

/**
 * Achievement suggestions (M9, docs/04 §4.6): verified bullet points that describe a duty
 * without a result get a question and a rewritten draft with `[confirm: …]` placeholders. The
 * candidate fills in the real detail and approves; nothing becomes a fact without that.
 *
 * Never asked again: a bullet with a pending suggestion, or one whose suggestion was decided
 * (approved or rejected) while the bullet was at the same revision. Editing the bullet makes it
 * eligible again.
 */
class AchievementSuggester
{
    public const MAX_BULLETS = 15;

    public function __construct(private ClaimGroundingValidator $grounding) {}

    /**
     * Bullets worth asking about: verified duties or achievements without any number.
     *
     * @return list<Claim>
     */
    public function candidates(): array
    {
        return array_values(Claim::query()
            ->where('status', ClaimStatus::Verified)
            ->whereIn('kind', [ClaimKind::Responsibility, ClaimKind::Achievement])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('knowledge_proposals')
                ->where('knowledge_proposals.target_type', 'claim')
                ->whereColumn('knowledge_proposals.target_id', 'claims.id')
                ->where(fn ($inner) => $inner
                    ->where('knowledge_proposals.status', ProposalStatus::Pending->value)
                    ->orWhere(fn ($decided) => $decided
                        ->where('knowledge_proposals.origin', 'achievement_suggestion')
                        ->whereColumn('knowledge_proposals.base_version', 'claims.current_revision_id'))))
            ->with(['currentRevision', 'subject'])
            ->latest('id')
            ->limit(self::MAX_BULLETS * 3)
            ->get()
            ->reject(fn (Claim $claim): bool => preg_match('/\d/', $claim->statement()) === 1)
            ->take(self::MAX_BULLETS)
            ->all());
    }

    /**
     * The bullets as tagged lines for the prompt, with the id → claim map.
     *
     * @param  list<Claim>  $claims
     * @return array{text: string, map: array<string, Claim>}
     */
    public function present(array $claims): array
    {
        $lines = [];
        $map = [];

        foreach ($claims as $i => $claim) {
            $id = 'C'.($i + 1);
            $map[$id] = $claim;
            $where = match (true) {
                $claim->subject instanceof Employment => "{$claim->subject->title} at {$claim->subject->employer_name}",
                $claim->subject instanceof Project => "project {$claim->subject->name}",
                default => 'general',
            };
            $lines[] = sprintf('<bullet id="%s" where="%s">%s</bullet>', $id, htmlspecialchars($where, ENT_QUOTES), htmlspecialchars($claim->statement(), ENT_NOQUOTES));
        }

        return ['text' => implode("\n", $lines), 'map' => $map];
    }

    /**
     * The model's suggestions that pass the checks: a known bullet, a question, and a draft with
     * at least one placeholder that adds no number outside its placeholders.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, Claim>  $map
     * @return list<array{claim: Claim, question: string, draft: string}>
     */
    public function accepted(array $output, array $map): array
    {
        $accepted = [];
        $seen = [];

        foreach ((array) ($output['suggestions'] ?? []) as $suggestion) {
            $claim = $map[(string) ($suggestion['id'] ?? '')] ?? null;
            $question = PlainText::normalize((string) ($suggestion['question'] ?? ''));
            $draft = PlainText::normalize((string) ($suggestion['draft'] ?? ''));

            if ($claim === null || isset($seen[$claim->id]) || $question === '' || $draft === '' || mb_strlen($draft) > 1000) {
                continue;
            }

            if (preg_match('/\[confirm:[^\]]+\]/iu', $draft) !== 1) {
                continue;
            }

            $withoutPlaceholders = (string) preg_replace('/\[confirm:[^\]]*\]/iu', '', $draft);
            $checked = $this->grounding->check(
                [['text' => $withoutPlaceholders, 'classification' => 'verified', 'evidence_ids' => ['C']]],
                ['C' => ['status' => 'verified', 'text' => $claim->statement()]],
            )[0];

            if (in_array('metric_without_source', $checked['flags'], true)) {
                continue;
            }

            $seen[$claim->id] = true;
            $accepted[] = ['claim' => $claim, 'question' => mb_substr($question, 0, 300), 'draft' => $draft];
        }

        return $accepted;
    }
}
