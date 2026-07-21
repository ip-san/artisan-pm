<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Board;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'parent_id' => null,
            'author_id' => User::factory(),
            'subject' => fake()->sentence(4),
            'content' => fake()->paragraphs(2, true),
            'is_sticky' => false,
            'is_locked' => false,
        ];
    }
}
