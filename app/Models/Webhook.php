<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookEvent;
use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'url', 'secret', 'project_id', 'user_id', 'events', 'is_active'])]
#[Hidden(['secret'])]
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

    /**
     * The hook's owner, when it belongs to a user.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The webhooks that should be called for $event on $object in the given
     * project: active, listening for the event, covering the project — and,
     * for one owned by a user, only when that user is active, holds
     * use_webhooks in the project and may view the object (Redmine's
     * Webhook.hooks_for). Nothing is delivered when `webhooks_enabled` is off.
     *
     * @return Collection<int, self>
     */
    public static function deliverableFor(Model $object, int $projectId, WebhookEvent $event): Collection
    {
        if (! Setting::get('webhooks_enabled', true)) {
            return new Collection;
        }

        $project = null;

        return self::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', $projectId))
            ->with('user')
            ->get()
            ->filter(fn (self $webhook) => $webhook->listensFor($event))
            ->filter(function (self $webhook) use ($object, $projectId, &$project): bool {
                if ($webhook->user_id === null) {
                    return true;
                }

                $owner = $webhook->user;
                $project ??= Project::query()->find($projectId);

                return $owner !== null && $owner->isActive() && $project !== null
                    && app(AuthorizationService::class)->can($owner, 'use_webhooks', $project)
                    && $owner->can('view', $object);
            })
            ->values();
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
