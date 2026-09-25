<?php

declare(strict_types=1);

namespace App\Support\Plugins;

use Illuminate\Database\Eloquent\Model;

/**
 * Redmine's `*_before_save` hook context (`{issue: @issue, params: params}`
 * for `controller_issues_new_before_save`/`_edit_before_save`, similarly for
 * time entries): the model about to be saved, not yet persisted, handed to
 * every plugin listener subscribed to the hook in registration order
 * (PluginManager::runBeforeSave()).
 *
 * A listener changes what gets saved simply by setting attributes on
 * $model directly — the very instance the service is about to call
 * ->save() on, same as a Redmine plugin mutating `context[:issue]`. A
 * listener vetoes the save by calling fail() with a message; the service
 * then raises a ValidationException instead of saving, matching how
 * Redmine's hook adds to `issue.errors` and the subsequent `@issue.save`
 * fails validation. A later listener still runs after an earlier one
 * fails (Redmine calls every registered hook implementation in turn too),
 * but the service never saves once anything failed.
 */
final class BeforeSaveContext
{
    /** @var array<int, string> */
    private array $errors = [];

    public function __construct(
        public readonly Model $model,
        public readonly bool $isNew,
    ) {}

    public function fail(string $message): void
    {
        $this->errors[] = $message;
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
