<?php

namespace Database\Factories;

use App\Models\EmailAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAddress>
 */
class EmailAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'address' => fake()->unique()->safeEmail(),
            'notify' => true,
        ];
    }
}
