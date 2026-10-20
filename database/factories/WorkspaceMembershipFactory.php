<?php

namespace Database\Factories;

use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\Workspace;
use App\Domain\Tenancy\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceMembership>
 */
#[UseModel(WorkspaceMembership::class)]
class WorkspaceMembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'role' => Role::Va,
        ];
    }
}
