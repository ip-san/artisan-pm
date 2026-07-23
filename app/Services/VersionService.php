<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\VersionCreated;
use App\Events\VersionDeleted;
use App\Events\VersionUpdated;
use App\Models\Version;

/**
 * Thin wrapper around Version mutations whose only job is dispatching the
 * Created/Updated/Deleted events every write path needs (currently just
 * for webhooks) — mirrors IssueService/WikiPageService/TimeEntryService's
 * shape. Like those, this performs no authorization itself; every caller
 * already gates access before reaching here.
 */
final class VersionService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Version
    {
        $version = Version::create($attributes);

        VersionCreated::dispatch($version);

        return $version;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Version $version, array $attributes): Version
    {
        $version->update($attributes);

        VersionUpdated::dispatch($version);

        return $version;
    }

    /**
     * Dispatched before the row is actually removed, so listeners (e.g.
     * the webhook payload builder) see a fully intact model — matches
     * IssueService::delete()'s same ordering rationale.
     */
    public function delete(Version $version): void
    {
        VersionDeleted::dispatch($version);

        $version->delete();
    }
}
