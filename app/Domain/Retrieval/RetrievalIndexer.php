<?php

namespace App\Domain\Retrieval;

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps `retrieval_units` in step with the library. A job's or project's text is part of its
 * bullet points' context, so changing one re-indexes those too. Runs inside a tenant context.
 */
class RetrievalIndexer
{
    /**
     * Index (or re-index) one source and everything whose text includes it.
     */
    public function sync(Model $source): void
    {
        $this->upsert($source);

        if ($source instanceof Employment) {
            foreach ($source->projects()->get() as $project) {
                $this->upsert($project);
                $this->syncClaimsOf($project);
            }

            $this->syncClaimsOf($source);
        }

        if ($source instanceof Project) {
            $this->syncClaimsOf($source);
        }
    }

    /**
     * Remove the unit of a source that no longer exists.
     */
    public function forget(string $sourceType, int $sourceId): void
    {
        RetrievalUnit::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->delete();
    }

    /**
     * Index the whole library of the current workspace (initial build, or after a text template
     * change). Returns the number of sources processed.
     */
    public function rebuild(): int
    {
        $count = 0;

        foreach ([Employment::class, Project::class, Claim::class, AnswerLibraryEntry::class] as $class) {
            $class::query()->chunkById(200, function ($sources) use (&$count): void {
                foreach ($sources as $source) {
                    $this->upsert($source);
                    $count++;
                }
            });
        }

        return $count;
    }

    private function syncClaimsOf(Employment|Project $subject): void
    {
        Claim::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->with('currentRevision')
            ->each(function (Claim $claim) use ($subject): void {
                $claim->setRelation('subject', $subject);
                $this->upsert($claim);
            });
    }

    private function upsert(Model $source): void
    {
        $text = RetrievalText::for($source);

        if ($text === null) {
            return;
        }

        $unit = RetrievalUnit::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->first() ?? new RetrievalUnit;

        $unit->forceFill([
            ...$text,
            'candidate_profile_id' => $source->getAttribute('candidate_profile_id'),
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'content_hash' => RetrievalText::contentHash($text['search_text']),
            'is_active' => in_array($text['status'], RetrievalText::ACTIVE_STATUSES, true),
        ])->save();
    }
}
