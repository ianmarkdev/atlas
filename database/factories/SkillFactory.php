<?php

namespace Database\Factories;

use App\Domain\Knowledge\Skill;
use App\Domain\Tenancy\Workspace;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Skill>
 */
#[UseModel(Skill::class)]
class SkillFactory extends Factory
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
            'kind' => 'skill',
            'name' => fake()->unique()->randomElement(['PHP', 'Laravel', 'React', 'TypeScript', 'PostgreSQL', 'Redis', 'Docker', 'Ruby', 'Rails', 'Next.js', 'AWS', 'Vue']),
        ];
    }

    public function inWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (array $attributes) => ['workspace_id' => $workspace->id]);
    }
}
