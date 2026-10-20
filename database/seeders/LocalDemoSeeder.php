<?php

namespace Database\Seeders;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\EmploymentType;
use App\Domain\Knowledge\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo accounts and a little career data for clicking through the UI locally.
 * Every account's password is `password`. Refuses to run outside local/testing.
 *
 * Not `WithoutModelEvents`: ULIDs, the tenant `workspace_id` fill and employer
 * normalisation all rely on model events.
 */
class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('LocalDemoSeeder only runs in the local or testing environment.');
        }

        $candidate = User::factory()->candidate()->create([
            'name' => 'Alex Morgan',
            'email' => 'candidate@example.com',
        ]);

        $workspace = $candidate->membership->workspace;
        $workspace->update(['name' => "Alex's applications"]);

        $profile = CandidateProfile::withoutGlobalScopes()->where('user_id', $candidate->id)->sole();
        $profile->update(['display_name' => 'Alex Morgan', 'headline' => 'Senior Laravel developer']);

        $va = User::factory()->va($workspace)->create(['name' => 'Vera Assistant', 'email' => 'va@example.com']);
        User::factory()->va($workspace)->create(['name' => 'Sam Helper', 'email' => 'va2@example.com']);
        User::factory()->inactive()->va($workspace)->create(['name' => 'Dee Former', 'email' => 'va-inactive@example.com']);

        $current = Employment::factory()->forProfile($profile)->verified($candidate)->create([
            'employer_name' => 'Northwind Payments Ltd',
            'title' => 'Senior Backend Developer',
            'employment_type' => EmploymentType::FullTime,
            'location' => 'London (hybrid)',
            'start_date' => '2022-04-01',
            'end_date' => null,
            'summary' => "Backend lead for the merchant billing platform.\nLaravel, PostgreSQL, Redis, AWS.",
            'created_by' => $candidate->id,
        ]);

        $previous = Employment::factory()->forProfile($profile)->verified($candidate)->create([
            'employer_name' => 'Brightside Digital',
            'title' => 'PHP Developer',
            'employment_type' => EmploymentType::FullTime,
            'location' => 'Manchester',
            'start_date' => '2018-09-01',
            'end_date' => '2022-03-01',
            'summary' => 'Agency work: e-commerce builds and integrations for retail clients.',
            'created_by' => $candidate->id,
        ]);

        Employment::factory()->forProfile($profile)->create([
            'employer_name' => 'Freelance',
            'title' => 'Web Developer',
            'employment_type' => EmploymentType::Freelance,
            'start_date' => '2017-01-01',
            'end_date' => '2018-08-01',
            'summary' => 'Added by a VA from the old CV. Waiting for the candidate to verify.',
            'created_by' => $va->id,
        ]);

        Project::factory()->forProfile($profile)->verified($candidate)->create([
            'employment_id' => $current->id,
            'name' => 'Invoice engine rewrite',
            'role' => 'Tech lead',
            'start_date' => '2023-01-01',
            'end_date' => '2023-10-01',
            'summary' => 'Replaced the legacy invoicing cron jobs with queued, idempotent jobs.',
            'created_by' => $candidate->id,
        ]);

        Project::factory()->forProfile($profile)->create([
            'employment_id' => $previous->id,
            'name' => 'Shopify ERP integration',
            'role' => 'Developer',
            'client_name' => null,
            'summary' => 'Proposed by a VA: order sync between Shopify and the client ERP.',
            'created_by' => $va->id,
        ]);

        Project::factory()->forProfile($profile)->verified($candidate)->create([
            'name' => 'laravel-audit-trail (open source)',
            'role' => 'Maintainer',
            'url' => 'https://example.com/laravel-audit-trail',
            'summary' => 'Small package for append-only audit logs.',
            'created_by' => $candidate->id,
        ]);

        Project::factory()->forProfile($profile)->archived()->create([
            'name' => 'Old portfolio site',
            'created_by' => $candidate->id,
        ]);
    }
}
