<?php

use App\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return redirect()->route('projects.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/attachments/{media}', AttachmentController::class)->name('attachments.show');

    Volt::route('/my/page', 'my-page.index')->name('my-page.index');
    Volt::route('/profile', 'profile.index')->name('profile.index');

    Volt::route('/projects', 'projects.index')->name('projects.index');
    Volt::route('/projects/create', 'projects.form')->name('projects.create');
    Volt::route('/projects/{project:identifier}', 'projects.show')->name('projects.show');
    Volt::route('/projects/{project:identifier}/edit', 'projects.form')->name('projects.edit');
    Volt::route('/projects/{project:identifier}/members', 'projects.members')->name('projects.members');

    Volt::route('/projects/{project:identifier}/issues', 'issues.index')->name('issues.index');
    Volt::route('/projects/{project:identifier}/issues/create', 'issues.form')->name('issues.create');
    // Registered before the {issue} routes below so "import" isn't matched
    // as an issue-id route-model-binding segment.
    Volt::route('/projects/{project:identifier}/issues/import', 'issues.import')->name('issues.import');
    Volt::route('/projects/{project:identifier}/issues/imports/{import}', 'issues.import-status')->name('issues.import-status');
    Volt::route('/projects/{project:identifier}/issues/{issue}', 'issues.show')->name('issues.show');
    Volt::route('/projects/{project:identifier}/issues/{issue}/edit', 'issues.form')->name('issues.edit');

    Volt::route('/projects/{project:identifier}/time_entries', 'time-entries.index')->name('time-entries.index');
    Volt::route('/projects/{project:identifier}/time_entries/create', 'time-entries.form')->name('time-entries.create');
    Volt::route('/projects/{project:identifier}/time_entries/{timeEntry}/edit', 'time-entries.form')->name('time-entries.edit');

    Volt::route('/projects/{project:identifier}/wiki', 'wiki.index')->name('wiki.index');
    // Registered before the {wikiPage} routes below so "new" isn't matched
    // as a wiki-page-id route-model-binding segment.
    Volt::route('/projects/{project:identifier}/wiki/new', 'wiki.form')->name('wiki.create');
    Volt::route('/projects/{project:identifier}/wiki/{wikiPage}', 'wiki.show')->name('wiki.show');
    Volt::route('/projects/{project:identifier}/wiki/{wikiPage}/edit', 'wiki.form')->name('wiki.edit');
    Volt::route('/projects/{project:identifier}/wiki/{wikiPage}/history', 'wiki.history')->name('wiki.history');
    Volt::route('/projects/{project:identifier}/wiki/{wikiPage}/versions/{version}', 'wiki.version')->name('wiki.version');

    Volt::route('/projects/{project:identifier}/boards', 'boards.index')->name('boards.index');
    // Registered before the {board} routes below so "new" isn't matched
    // as a board-id route-model-binding segment.
    Volt::route('/projects/{project:identifier}/boards/new', 'boards.form')->name('boards.create');
    Volt::route('/projects/{project:identifier}/boards/{board}/edit', 'boards.form')->name('boards.edit');
    Volt::route('/projects/{project:identifier}/boards/{board}/topics/new', 'messages.form')->name('messages.create');
    Volt::route('/projects/{project:identifier}/boards/{board}', 'boards.show')->name('boards.show');
    Volt::route('/projects/{project:identifier}/boards/{board}/topics/{message}', 'messages.show')->name('messages.show');
    Volt::route('/projects/{project:identifier}/boards/{board}/topics/{message}/edit', 'messages.form')->name('messages.edit');

    Volt::route('/projects/{project:identifier}/news', 'news.index')->name('news.index');
    Volt::route('/projects/{project:identifier}/news/new', 'news.form')->name('news.create');
    Volt::route('/projects/{project:identifier}/news/{news}/edit', 'news.form')->name('news.edit');
    Volt::route('/projects/{project:identifier}/news/{news}', 'news.show')->name('news.show');

    Volt::route('/projects/{project:identifier}/documents', 'documents.index')->name('documents.index');
    Volt::route('/projects/{project:identifier}/documents/new', 'documents.form')->name('documents.create');
    Volt::route('/projects/{project:identifier}/documents/{document}/edit', 'documents.form')->name('documents.edit');
    Volt::route('/projects/{project:identifier}/documents/{document}', 'documents.show')->name('documents.show');

    Volt::route('/projects/{project:identifier}/files', 'files.index')->name('files.index');

    Volt::route('/projects/{project:identifier}/repository', 'repository.index')->name('repository.index');
    Volt::route('/projects/{project:identifier}/repository/edit', 'repository.form')->name('repository.edit');
    Volt::route('/projects/{project:identifier}/repository/revisions/{changeset}', 'repository.show')->name('repository.show');
    Volt::route('/projects/{project:identifier}/repository/browse/{path?}', 'repository.browse')->where('path', '.*')->name('repository.browse');
    Volt::route('/projects/{project:identifier}/repository/entry/{path}', 'repository.entry')->where('path', '.*')->name('repository.entry');

    Volt::route('/projects/{project:identifier}/activity', 'activity.index')->name('activity.index');

    Volt::route('/projects/{project:identifier}/calendar', 'calendar.index')->name('calendar.index');

    Volt::route('/projects/{project:identifier}/gantt', 'gantt.index')->name('gantt.index');

    Volt::route('/projects/{project:identifier}/search', 'search.index')->name('search.index');

    Volt::route('/roles', 'roles.index')->name('roles.index');
    Volt::route('/roles/create', 'roles.form')->name('roles.create');
    Volt::route('/roles/{role}/edit', 'roles.form')->name('roles.edit');

    Volt::route('/groups', 'groups.index')->name('groups.index');
    Volt::route('/groups/create', 'groups.form')->name('groups.create');
    Volt::route('/groups/{group}/edit', 'groups.form')->name('groups.edit');

    Volt::route('/custom-fields', 'custom-fields.index')->name('custom-fields.index');
    Volt::route('/custom-fields/create', 'custom-fields.form')->name('custom-fields.create');
    Volt::route('/custom-fields/{customField}/edit', 'custom-fields.form')->name('custom-fields.edit');

    Volt::route('/settings', 'settings.index')->name('settings.index');

    Volt::route('/auth-sources', 'auth-sources.index')->name('auth-sources.index');
    Volt::route('/auth-sources/create', 'auth-sources.form')->name('auth-sources.create');
    Volt::route('/auth-sources/{authSource}/edit', 'auth-sources.form')->name('auth-sources.edit');

    Volt::route('/webhooks', 'webhooks.index')->name('webhooks.index');
    Volt::route('/webhooks/create', 'webhooks.form')->name('webhooks.create');
    Volt::route('/webhooks/{webhook}/edit', 'webhooks.form')->name('webhooks.edit');

    Volt::route('/users', 'users.index')->name('users.index');
    Volt::route('/users/create', 'users.form')->name('users.create');
    Volt::route('/users/{user}/edit', 'users.form')->name('users.edit');
});
