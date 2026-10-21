<?php

use App\Domain\Retrieval\RetrievalUnit;
use App\Models\User;

test('the retrieval eval runs on the fixture, reports recall and leakage, and leaves no data behind', function () {
    $usersBefore = User::query()->count();

    $this->artisan('app:eval-retrieval', ['--min' => 0])
        ->expectsOutputToContain('hybrid (keyword + embeddings)')
        ->expectsOutputToContain('Mean Recall@8')
        ->expectsOutputToContain('Leaked results')
        ->assertSuccessful();

    expect(User::query()->count())->toBe($usersBefore)
        ->and(RetrievalUnit::withoutGlobalScopes()->count())->toBe(0);
});

test('the eval fails when recall is below the target', function () {
    $this->artisan('app:eval-retrieval', ['--min' => 1.01])
        ->expectsOutputToContain('Recall is below the target.')
        ->assertFailed();
});
