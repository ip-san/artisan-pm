<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookEvent;
use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'url', 'secret', 'project_id', 'events', 'is_active'])]
final class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function listensFor(WebhookEvent $event): bool
    {
        return in_array($event->value, $this->events ?? [], true);
    }

    /**
     * Null project_id means "fires for every project".
     */
    public function appliesToProject(Project $project): bool
    {
        return $this->project_id === null || $this->project_id === $project->id;
    }
}
