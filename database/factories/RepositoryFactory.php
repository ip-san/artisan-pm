<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RepositoryType;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => RepositoryType::Git->value,
            'path' => sys_get_temp_dir().'/'.fake()->unique()->uuid(),
        ];
    }

    /**
     * A10-01b: a Subversion repository on a remote server, with credentials.
     */
    public function remote(string $url = 'https://svn.example.com/repos/project', ?string $login = 'svnuser', ?string $password = 'svn-secret'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => RepositoryType::Svn->value,
            'path' => null,
            'url' => $url,
            'login' => $login,
            'password' => $password,
        ]);
    }
}
