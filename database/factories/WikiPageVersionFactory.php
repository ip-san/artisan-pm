<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\WikiPage;
use App\Models\WikiPageVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WikiPageVersion>
 */
class WikiPageVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'wiki_page_id' => WikiPage::factory(),
            'author_id' => User::factory(),
            'text' => fake()->paragraphs(3, true),
            'comments' => null,
            'version' => 1,
        ];
    }
}
