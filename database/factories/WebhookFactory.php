<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' webhook',
            'url' => fake()->url(),
            'secret' => null,
            'project_id' => null,
            'events' => [WebhookEvent::IssueCreated->value, WebhookEvent::IssueUpdated->value],
            'is_active' => true,
        ];
    }
}
