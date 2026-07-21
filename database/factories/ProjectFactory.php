<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectModuleKey;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => Str::title($name),
            'identifier' => Str::slug($name),
            'description' => fake()->sentence(),
            'is_public' => true,
            'status' => ProjectStatus::Active->value,
        ];
    }

    public function configure(): static
    {
        // Mirrors what the project creation form does by default, so
        // factory-created projects behave like real ones unless a test
        // explicitly overrides modules via syncModules().
        return $this->afterCreating(function (Project $project) {
            $project->syncModules(ProjectModuleKey::defaults());
        });
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => false,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Closed->value,
        ]);
    }

    public function withoutModules(): static
    {
        return $this->afterCreating(function (Project $project) {
            $project->syncModules([]);
        });
    }
}
