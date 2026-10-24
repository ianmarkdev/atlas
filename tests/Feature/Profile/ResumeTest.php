<?php

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\CandidateLanguage;
use App\Domain\Knowledge\CandidateLink;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimRevision;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Domain\Knowledge\Project;
use App\Domain\Knowledge\Skill;
use App\Domain\Resumes\ResumeVariant;
use App\Domain\Resumes\ResumeVariantRevision;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function resumeOf(User $candidate, array $document = [], array $attributes = []): ResumeVariant
{
    return ResumeVariant::factory()->forProfile(profileOf($candidate))->withDocument($document)->create($attributes);
}

/**
 * @return array<string, mixed>
 */
function jobItem(Employment $employment, array $overrides = []): array
{
    return ['employment' => $employment->ulid, 'display_title' => null, 'description' => null, 'bullets' => [], 'projects' => [], ...$overrides];
}

function saveResume(ResumeVariant $resume, array $document, array $overrides = []): array
{
    return [
        'name' => $resume->name,
        'document' => ['schema_version' => 1, 'title' => null, 'summary' => null, 'experience' => [], 'projects' => [], ...$document],
        'lock_version' => $resume->lock_version,
        ...$overrides,
    ];
}

describe('create', function () {
    test('the first resume becomes the default and starts with an empty revision 1', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs($candidate)
            ->post(route('resumes.store'), ['name' => 'PHP focused'])
            ->assertRedirect();

        $resume = inWorkspaceOf($candidate, fn () => ResumeVariant::query()->with('currentRevision')->sole());
        expect($resume->name)->toBe('PHP focused')
            ->and($resume->is_default)->toBeTrue()
            ->and($resume->currentRevision?->revision_no)->toBe(1)
            ->and($resume->document()['experience'])->toBe([]);
    });

    test('a resume copied from another starts with that resume\'s document', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        $source = resumeOf($candidate, ['title' => 'Senior PHP Engineer', 'experience' => [jobItem($employment)]], ['is_default' => true]);

        $this->actingAs($candidate)->post(route('resumes.store'), ['name' => 'React focused', 'copy_from' => $source->ulid]);

        $copy = inWorkspaceOf($candidate, fn () => ResumeVariant::query()->where('name', 'React focused')->with('currentRevision')->sole());
        expect($copy->is_default)->toBeFalse()
            ->and($copy->document()['title'])->toBe('Senior PHP Engineer')
            ->and($copy->document()['experience'][0]['employment'])->toBe($employment->ulid);
    });
});

describe('save', function () {
    test('saving appends a revision with the per-resume title, wording and order, leaving the library unchanged', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        $first = claimOf($candidate, 'Cut the invoice run from 4 hours to 20 minutes', $employment);
        $second = claimOf($candidate, 'Mentored two juniors', $employment);
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, [
                'title' => '  Senior PHP Engineer ',
                'experience' => [jobItem($employment, [
                    'display_title' => 'Senior PHP Developer',
                    'bullets' => [
                        ['claim' => $second->ulid, 'text' => 'Mentored two junior PHP developers'],
                        ['claim' => $first->ulid, 'text' => null],
                    ],
                ])],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('resumes.show', $resume));

        $resume->refresh()->load('currentRevision');
        $job = $resume->document()['experience'][0];
        expect($resume->currentRevision?->revision_no)->toBe(2)
            ->and($resume->lock_version)->toBe(1)
            ->and($resume->document()['title'])->toBe('Senior PHP Engineer')
            ->and($job['display_title'])->toBe('Senior PHP Developer')
            // jsonb does not keep key order inside objects, only the order of list items.
            ->and($job['bullets'])->toEqual([
                ['claim' => $second->ulid, 'text' => 'Mentored two junior PHP developers'],
                ['claim' => $first->ulid, 'text' => null],
            ])
            ->and($employment->refresh()->title)->not->toBe('Senior PHP Developer')
            ->and($second->refresh()->load('currentRevision')->statement())->toBe('Mentored two juniors');
    });

    test('a save based on an outdated version is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $resume = resumeOf($candidate, [], ['lock_version' => 3]);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, ['title' => 'Overwrite'], ['lock_version' => 2]))
            ->assertSessionHasErrors('lock_version');

        expect(inWorkspaceOf($candidate, fn () => ResumeVariantRevision::query()->where('resume_variant_id', $resume->id)->count()))->toBe(1);
    });

    test('a job from another workspace is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $foreign = employmentOf(candidateWithTwoFactor());
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, ['experience' => [jobItem($foreign)]]))
            ->assertSessionHasErrors(['document.experience.0.employment' => 'This experience is no longer in your library.']);
    });

    test('an archived job is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $archived = Employment::factory()->forProfile(profileOf($candidate))->archived()->create();
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, ['experience' => [jobItem($archived)]]))
            ->assertSessionHasErrors('document.experience.0.employment');
    });

    test('facts such as the employer or dates cannot be set on a resume', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, [
                'experience' => [jobItem($employment, ['employer_name' => 'Google', 'start_date' => '2010-01'])],
            ]))
            ->assertSessionHasErrors(['document.experience.0.employer_name', 'document.experience.0.start_date']);
    });

    test('a bullet point of a different job is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        $otherJobClaim = claimOf($candidate, 'Belongs elsewhere', employmentOf($candidate));
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, [
                'experience' => [jobItem($employment, ['bullets' => [['claim' => $otherJobClaim->ulid, 'text' => null]]])],
            ]))
            ->assertSessionHasErrors(['document.experience.0.bullets.0.claim' => 'This bullet point does not belong to that item.']);
    });

    test('a project listed under a job it does not belong to is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        $standalone = Project::factory()->forProfile(profileOf($candidate))->verified($candidate)->create();
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, [
                'experience' => [jobItem($employment, ['projects' => [['project' => $standalone->ulid, 'description' => null, 'bullets' => []]]])],
            ]))
            ->assertSessionHasErrors('document.experience.0.projects.0.project');
    });

    test('a summary over 2,000 characters is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, ['summary' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors('document.summary');
    });

    test('VAs can read a resume but not change it', function () {
        $candidate = candidateWithTwoFactor();
        $va = vaOf($candidate);
        $resume = resumeOf($candidate);

        $this->actingAs($va)->get(route('resumes.show', $resume))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('profile/resume')->where('canEdit', false));

        $this->actingAs($va)->put(route('resumes.update', $resume), saveResume($resume, ['title' => 'VA edit']))->assertForbidden();
        $this->actingAs($va)->post(route('resumes.store'), ['name' => 'VA resume'])->assertForbidden();
    });

    test('a resume of another workspace responds 404', function () {
        $candidate = candidateWithTwoFactor();
        $foreign = resumeOf(candidateWithTwoFactor());

        $this->actingAs($candidate)->get(route('resumes.show', $foreign))->assertNotFound();
    });
});

describe('skills, education, certifications, languages and links', function () {
    test('grouped skills, credentials, languages and links are saved; duplicates and empty groups are dropped', function () {
        $candidate = candidateWithTwoFactor();
        $workspace = workspaceOf($candidate);
        $php = Skill::factory()->inWorkspace($workspace)->create(['name' => 'PHP']);
        $react = Skill::factory()->inWorkspace($workspace)->create(['name' => 'React']);
        $degree = Credential::factory()->forProfile(profileOf($candidate))->verified($candidate)->create();
        $cert = Credential::factory()->forProfile(profileOf($candidate))->certification()->verified($candidate)->create();
        $language = CandidateLanguage::create(['workspace_id' => $workspace->id, 'candidate_profile_id' => profileOf($candidate)->id, 'language' => 'German', 'proficiency' => 'fluent']);
        $link = CandidateLink::create(['workspace_id' => $workspace->id, 'candidate_profile_id' => profileOf($candidate)->id, 'kind' => 'github', 'url' => 'https://github.com/alex']);
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, [
                'skills' => [
                    ['label' => 'Backend', 'items' => [$php->ulid, $php->ulid]],
                    ['label' => 'Frontend', 'items' => [$react->ulid, $php->ulid]],
                    ['label' => 'Empty', 'items' => []],
                ],
                'education' => [['credential' => $degree->ulid]],
                'certifications' => [['credential' => $cert->ulid]],
                'languages' => [$language->ulid],
                'links' => [$link->ulid],
            ]))
            ->assertSessionHasNoErrors();

        $document = $resume->refresh()->load('currentRevision')->document();
        expect($document['skills'])->toEqual([
            ['label' => 'Backend', 'items' => [$php->ulid]],
            ['label' => 'Frontend', 'items' => [$react->ulid]],
        ])
            ->and($document['education'])->toEqual([['credential' => $degree->ulid]])
            ->and($document['certifications'])->toEqual([['credential' => $cert->ulid]])
            ->and($document['languages'])->toBe([$language->ulid])
            ->and($document['links'])->toBe([$link->ulid]);
    });

    test('education listed as a certification is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $degree = Credential::factory()->forProfile(profileOf($candidate))->verified($candidate)->create();
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, ['certifications' => [['credential' => $degree->ulid]]]))
            ->assertSessionHasErrors(['document.certifications.0.credential' => 'This certification is no longer in your library.']);
    });

    test('a skill or language of another workspace is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $other = candidateWithTwoFactor();
        $foreignSkill = Skill::factory()->inWorkspace(workspaceOf($other))->create(['name' => 'Cobol']);
        $foreignLanguage = CandidateLanguage::create(['workspace_id' => workspaceOf($other)->id, 'candidate_profile_id' => profileOf($other)->id, 'language' => 'Latin', 'proficiency' => 'basic']);
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('resumes.update', $resume), saveResume($resume, [
                'skills' => [['label' => null, 'items' => [$foreignSkill->ulid]]],
                'languages' => [$foreignLanguage->ulid],
            ]))
            ->assertSessionHasErrors(['document.skills.0.items.0', 'document.languages.0']);
    });

    test('an older resume revision without the new sections opens with them empty', function () {
        $candidate = candidateWithTwoFactor();
        $resume = ResumeVariant::factory()->forProfile(profileOf($candidate))->create();
        ResumeVariantRevision::create([
            'workspace_id' => $resume->workspace_id,
            'resume_variant_id' => $resume->id,
            'revision_no' => 1,
            'document' => ['schema_version' => 1, 'title' => 'Old', 'summary' => null, 'experience' => [], 'projects' => []],
        ]);
        $resume->forceFill(['current_revision_id' => ResumeVariantRevision::withoutGlobalScopes()->where('resume_variant_id', $resume->id)->value('id')])->save();

        $this->actingAs($candidate)->get(route('resumes.show', $resume))
            ->assertInertia(fn (Assert $page) => $page
                ->where('resume.document.title', 'Old')
                ->where('resume.document.skills', [])
                ->where('resume.document.links', []));
    });
});

describe('apply everywhere', function () {
    test('applying a rewording creates a new claim revision with that wording', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        $claim = claimOf($candidate, 'Mentored two juniors', $employment);
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->postJson(route('resumes.apply-bullet', [$resume, $claim]), ['text' => 'Mentored two junior PHP developers'])
            ->assertOk()
            ->assertJsonPath('claim.statement', 'Mentored two junior PHP developers');

        expect(inWorkspaceOf($candidate, fn () => ClaimRevision::query()->where('claim_id', $claim->id)->count()))->toBe(2)
            ->and($claim->refresh()->status)->toBe(ClaimStatus::Verified);
    });

    test('VAs cannot apply a rewording', function () {
        $candidate = candidateWithTwoFactor();
        $claim = claimOf($candidate, 'Mentored two juniors', employmentOf($candidate));

        $this->actingAs(vaOf($candidate))
            ->postJson(route('resumes.apply-bullet', [resumeOf($candidate), $claim]), ['text' => 'Changed'])
            ->assertForbidden();
    });
});

describe('add from the editor', function () {
    test('adding a job as JSON saves a verified job to the library and returns it', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs($candidate)
            ->postJson(route('knowledge.employments.store'), [
                'employer_name' => 'Acme',
                'title' => 'Senior Developer',
                'start_date' => '2021-03',
            ])
            ->assertCreated()
            ->assertJsonPath('employment.title', 'Senior Developer')
            ->assertJsonPath('employment.status', 'verified')
            ->assertJsonPath('employment.claims', []);
    });

    test('adding a bullet point as JSON returns the new claim', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);

        $this->actingAs($candidate)
            ->postJson(route('knowledge.claims.store'), [
                'subject' => "employment:{$employment->ulid}",
                'kind' => 'achievement',
                'statement' => 'Shipped the billing rewrite',
            ])
            ->assertCreated()
            ->assertJsonPath('claim.statement', 'Shipped the billing rewrite');
    });

    test('a duplicate bullet point added as JSON gets a 422 with the reason', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        claimOf($candidate, 'Shipped the billing rewrite', $employment);

        $this->actingAs($candidate)
            ->postJson(route('knowledge.claims.store'), [
                'subject' => "employment:{$employment->ulid}",
                'kind' => 'achievement',
                'statement' => 'Shipped the billing rewrite.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['statement' => 'You already have this claim.']);
    });

    test('pasting a list as JSON returns the created claims and the skipped count', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);

        $this->actingAs($candidate)
            ->postJson(route('knowledge.claims.bulk.store'), [
                'subject' => "employment:{$employment->ulid}",
                'kind' => 'achievement',
                'statements' => "- First\n- Second\n- first",
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'claims')
            ->assertJsonPath('skipped', 1);
    });
});

describe('profile page and status', function () {
    test('the profile lists resumes with the default first', function () {
        $candidate = candidateWithTwoFactor();
        resumeOf($candidate, ['title' => 'React'], ['name' => 'A React focused']);
        resumeOf($candidate, ['title' => 'PHP'], ['name' => 'PHP focused', 'is_default' => true]);

        $this->actingAs($candidate)->get(route('resumes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('profile/index')
                ->has('resumes', 2)
                ->where('resumes.0.name', 'PHP focused')
                ->where('resumes.0.is_default', true)
                ->where('resumes.1.title', 'React'));
    });

    test('the candidate can set, change and clear a resume\'s file link without adding a revision', function () {
        $candidate = candidateWithTwoFactor();
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->patch(route('resumes.file-url', $resume), ['file_url' => 'https://drive.google.com/file/d/abc/view'])
            ->assertSessionHasNoErrors();

        expect($resume->refresh()->file_url)->toBe('https://drive.google.com/file/d/abc/view')
            ->and($resume->currentRevision?->revision_no)->toBe(1);

        $this->actingAs($candidate)->get(route('resumes.index'))
            ->assertInertia(fn (Assert $page) => $page->where('resumes.0.file_url', 'https://drive.google.com/file/d/abc/view'));

        $this->actingAs($candidate)->patch(route('resumes.file-url', $resume), ['file_url' => '']);

        expect($resume->refresh()->file_url)->toBeNull();
    });

    test('a file link must be an http(s) URL', function () {
        $candidate = candidateWithTwoFactor();
        $resume = resumeOf($candidate);

        $this->actingAs($candidate)
            ->patch(route('resumes.file-url', $resume), ['file_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('file_url');

        expect($resume->refresh()->file_url)->toBeNull();
    });

    test('VAs see a resume\'s file link but cannot change it', function () {
        $candidate = candidateWithTwoFactor();
        $va = vaOf($candidate);
        $resume = resumeOf($candidate, [], ['file_url' => 'https://drive.google.com/file/d/abc/view']);

        $this->actingAs($va)->get(route('resumes.show', $resume))
            ->assertInertia(fn (Assert $page) => $page->where('resume.file_url', 'https://drive.google.com/file/d/abc/view'));

        $this->actingAs($va)
            ->patch(route('resumes.file-url', $resume), ['file_url' => 'https://example.com/other.pdf'])
            ->assertForbidden();
    });

    test('making a resume the default unsets the previous default', function () {
        $candidate = candidateWithTwoFactor();
        $old = resumeOf($candidate, [], ['is_default' => true]);
        $new = resumeOf($candidate);

        $this->actingAs($candidate)->patch(route('resumes.default', $new));

        expect($new->refresh()->is_default)->toBeTrue()
            ->and($old->refresh()->is_default)->toBeFalse();
    });

    test('the default resume cannot be archived', function () {
        $candidate = candidateWithTwoFactor();
        $default = resumeOf($candidate, [], ['is_default' => true]);

        $this->actingAs($candidate)
            ->patch(route('resumes.archive', $default))
            ->assertSessionHasErrors('resume');

        expect($default->refresh()->status)->toBe('active');
    });

    test('restoring an older revision saves it as a new revision', function () {
        $candidate = candidateWithTwoFactor();
        $resume = resumeOf($candidate, ['title' => 'First title']);
        $this->actingAs($candidate)->put(route('resumes.update', $resume), saveResume($resume, ['title' => 'Second title']));
        $first = inWorkspaceOf($candidate, fn () => ResumeVariantRevision::query()->where('resume_variant_id', $resume->id)->where('revision_no', 1)->sole());

        $this->actingAs($candidate)->post(route('resumes.revisions.restore', [$resume, $first->id]))->assertRedirect();

        $resume->refresh()->load('currentRevision');
        expect($resume->currentRevision?->revision_no)->toBe(3)
            ->and($resume->document()['title'])->toBe('First title');
    });

    test('the library shows which resumes use each job', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        resumeOf($candidate, ['experience' => [jobItem($employment)]], ['name' => 'PHP focused']);
        resumeOf($candidate, [], ['name' => 'Ruby focused']);

        $this->actingAs($candidate)->get(route('knowledge.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where("usedIn.employments.{$employment->ulid}", ['PHP focused']));
    });

    test('the library shows which resumes use each skill and how many current answers rely on each bullet point', function () {
        $candidate = candidateWithTwoFactor();
        $php = Skill::factory()->inWorkspace(workspaceOf($candidate))->create(['name' => 'PHP']);
        resumeOf($candidate, ['skills' => [['label' => null, 'items' => [$php->ulid]]]], ['name' => 'PHP focused']);
        $claim = claimOf($candidate, 'Led a team of four');
        $answers = AnswerLibraryEntry::factory()->forProfile(profileOf($candidate))->answering('I led four engineers.', [$claim->ulid]);
        $answers->approved($candidate)->create();
        $answers->create();
        $answers->archived()->create();

        $this->actingAs($candidate)->get(route('knowledge.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where("usedIn.skills.{$php->ulid}", ['PHP focused'])
                ->where("answerCitations.{$claim->ulid}", 2));
    });
});

test('an archived job in a saved resume makes that resume fail validation until it is removed', function () {
    $candidate = candidateWithTwoFactor();
    $employment = employmentOf($candidate);
    $resume = resumeOf($candidate, ['experience' => [jobItem($employment)]]);
    $employment->forceFill(['status' => KnowledgeStatus::Archived])->save();

    $this->actingAs($candidate)
        ->put(route('resumes.update', $resume), saveResume($resume, ['experience' => [jobItem($employment)]]))
        ->assertSessionHasErrors('document.experience.0.employment');

    $this->actingAs($candidate)
        ->put(route('resumes.update', $resume), saveResume($resume, ['experience' => []]))
        ->assertSessionHasNoErrors();

    expect(inWorkspaceOf($candidate, fn () => Claim::query()->count()))->toBe(0);
});
