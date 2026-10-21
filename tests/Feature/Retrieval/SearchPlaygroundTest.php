<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the candidate searches the library and sees how each hit was found', function () {
    $candidate = candidateWithTwoFactor();
    claimOf($candidate, 'Wrote the Terraform modules for every environment');

    $this->actingAs($candidate)->get(route('knowledge.search', ['q' => 'Terraform']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge/search')
            ->where('result.semantic', true)
            ->has('result.hits', 1)
            ->where('result.hits.0.unit_type', 'claim')
            ->where('result.hits.0.lexical_rank', 1)
            ->where('index.active', 1)
            ->where('index.embedded', 1)
            ->where('index.read_model', 'fake/word-hash (1024)'));
});

test('a VA cannot open the search playground or rebuild the index', function () {
    $va = vaOf(candidateWithTwoFactor());

    $this->actingAs($va)->get(route('knowledge.search'))->assertForbidden();
    $this->actingAs($va)->post(route('knowledge.search.rebuild'))->assertForbidden();
});

test('rebuilding re-indexes the library', function () {
    $candidate = candidateWithTwoFactor();
    claimOf($candidate, 'Built the billing API');

    $this->actingAs($candidate)
        ->post(route('knowledge.search.rebuild'))
        ->assertInertiaFlash('toast.message', 'Re-indexed 1 library items; embedding runs in the background.');
});
