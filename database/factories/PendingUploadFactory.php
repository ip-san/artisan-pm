<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PendingUpload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingUpload>
 */
class PendingUploadFactory extends Factory
{
    protected $model = PendingUpload::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
        ];
    }
}
