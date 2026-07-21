<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\News;
use App\Models\NewsComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsComment>
 */
class NewsCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'news_id' => News::factory(),
            'author_id' => User::factory(),
            'content' => fake()->paragraph(),
        ];
    }
}
