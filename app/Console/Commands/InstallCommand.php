<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Domain\Audit\AuditLogger;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:install
    {--workspace= : Workspace name}
    {--name= : Candidate full name}
    {--email= : Candidate email address}
    {--password= : Candidate password}')]
#[Description('Create the workspace, the candidate account and the candidate profile (run once)')]
class InstallCommand extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(AuditLogger $audit, TenantContext $context): int
    {
        if (Workspace::query()->exists()) {
            $this->components->error('The application is already installed: a workspace exists.');

            return self::FAILURE;
        }

        $input = [
            'workspace' => $this->stringOption('workspace') ?? text('Workspace name', default: 'My applications', required: true),
            'name' => $this->stringOption('name') ?? text('Your full name', required: true),
            'email' => $this->stringOption('email') ?? text('Your email address', required: true),
            'password' => $this->stringOption('password') ?? password('Password', required: true),
        ];
        $input['password_confirmation'] = $this->stringOption('password') ?? password('Confirm password', required: true);

        $validator = Validator::make($input, [
            'workspace' => ['required', 'string', 'max:255'],
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($input, $audit, $context): void {
            $candidate = User::create([
                'name' => $input['name'],
                'email' => Str::lower($input['email']),
                'password' => $input['password'],
            ]);
            $candidate->forceFill(['email_verified_at' => now()])->save();

            $workspace = Workspace::create(['name' => $input['workspace'], 'created_by' => $candidate->id]);

            $workspace->memberships()->create(['user_id' => $candidate->id, 'role' => Role::Candidate]);

            $context->run($workspace, function () use ($candidate, $audit): void {
                CandidateProfile::create(['user_id' => $candidate->id, 'display_name' => $candidate->name]);

                $audit->record('workspace.installed', $candidate, ['role' => Role::Candidate->value]);
            });
        });

        $this->components->info('Installed. Sign in at '.url('/login').' — you will be asked to set up two-factor authentication first.');

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }
}
