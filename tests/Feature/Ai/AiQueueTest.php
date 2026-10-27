<?php

use App\Domain\Ai\AiQueue;
use App\Jobs\AnalyseFeedback;
use App\Jobs\AnalyseJobApplication;
use App\Jobs\CheckAnswerClaims;
use App\Jobs\EmbedRetrievalUnits;
use App\Jobs\SuggestAchievements;
use App\Jobs\SyncRetrievalSource;
use App\Jobs\TailorApplicationResume;

test('a task waits on the local or the Claude lane by its provider, ahead of bulk work', function (?string $provider, bool $bulk, string $queue) {
    config(['ai.tasks.answer_writing.provider' => $provider]);

    expect(AiQueue::for('answer_writing', $bulk))->toBe($queue);
})->with([
    'default provider (Ollama)' => [null, false, 'local-now'],
    'default provider, bulk' => [null, true, 'local-bulk'],
    'Claude' => ['claude', false, 'claude-now'],
    'Claude, bulk' => ['claude', true, 'claude-bulk'],
]);

test('each AI job takes the lane of its task, and embeddings and the re-index before them always run locally', function () {
    config(['ai.tasks.claim_check.provider' => 'claude', 'ai.tasks.feedback_analysis.provider' => 'claude']);

    expect((new AnalyseJobApplication(1, 1))->queue)->toBe('local-now')
        ->and((new TailorApplicationResume(1, 1))->queue)->toBe('local-now')
        ->and((new CheckAnswerClaims(1, 1))->queue)->toBe('claude-now')
        ->and((new AnalyseFeedback(1, 1))->queue)->toBe('claude-bulk')
        ->and((new SuggestAchievements(1))->queue)->toBe('local-bulk')
        ->and((new SuggestAchievements(1, requestedBy: 5))->queue)->toBe('local-now')
        ->and((new EmbedRetrievalUnits(1))->queue)->toBe('local-now')
        ->and((new SyncRetrievalSource(1, 'claim', 1))->queue)->toBe('local-now');
});
