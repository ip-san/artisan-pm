<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Models\WikiPage;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Single registration point for Eloquent's polymorphic morph map, enforced
 * app-wide so every polymorphic relation (spatie/media-library's Media,
 * Watcher, custom field values, ...) stores a short stable alias instead
 * of a fully-qualified class name that would break if a model were ever
 * renamed or namespaced differently.
 *
 * enforceMorphMap() rejects any morph type not listed here, so every
 * model on either end of a polymorphic relation — including every
 * HasMedia implementer — must be added when it's introduced.
 */
final class MorphMapServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'issue' => Issue::class,
            'news' => News::class,
            'document' => Document::class,
            'version' => Version::class,
            'project' => Project::class,
            'wiki_page' => WikiPage::class,
            'message' => Message::class,
            'group' => Group::class,
            'time_entry_activity' => Enumeration::class,
            'document_category' => Enumeration::class,
            'user' => User::class,
        ]);
    }
}
