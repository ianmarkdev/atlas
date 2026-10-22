<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Issues and revokes the API tokens of the Career Clipper browser extension. A token can only
 * save new applications (`applications:capture`): it cannot read, edit or delete anything.
 */
#[Signature('app:extension-token {email : The account the extension saves jobs as}
    {--days=90 : Days until the token expires (0 = never)}
    {--name=Career Clipper : A label to tell your tokens apart, e.g. the browser}
    {--revoke : Revoke all of the account\'s extension tokens instead of issuing one}
    {--list : List the account\'s extension tokens}')]
#[Description('Issue, list or revoke browser extension API tokens for an account')]
class ExtensionToken extends Command
{
    public const ABILITY = 'applications:capture';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null || ! $user->is_active || $user->membership === null) {
            $this->components->error('No active workspace member has that email address.');

            return self::FAILURE;
        }

        $tokens = $user->tokens()->whereJsonContains('abilities', self::ABILITY);

        if ($this->option('list')) {
            $this->table(['Id', 'Name', 'Created', 'Last used', 'Expires'], $tokens->orderBy('id')->get()->map(fn ($token): array => [
                $token->id,
                $token->name,
                $token->created_at?->toDateTimeString(),
                $token->last_used_at?->toDateTimeString() ?? 'never',
                $token->expires_at?->toDateTimeString() ?? 'never',
            ])->all());

            return self::SUCCESS;
        }

        if ($this->option('revoke')) {
            $count = $tokens->delete();
            $this->components->info("Revoked {$count} extension token(s) of {$user->email}.");

            return self::SUCCESS;
        }

        if ($user->isCandidate() && ! $user->hasEnabledTwoFactorAuthentication() && config('app.require_candidate_two_factor')) {
            $this->components->error('Set up two-factor authentication for this account first.');

            return self::FAILURE;
        }

        $name = $this->option('name');
        $days = max(0, (int) $this->option('days'));
        $token = $user->createToken(is_string($name) && $name !== '' ? $name : 'Career Clipper', [self::ABILITY], $days === 0 ? null : now()->addDays($days));

        $this->components->info('Paste this token into the extension settings. It is shown only once.');
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->components->twoColumnDetail('Account', $user->email);
        $this->components->twoColumnDetail('Expires', $days === 0 ? 'never' : now()->addDays($days)->toDateString());

        return self::SUCCESS;
    }
}
