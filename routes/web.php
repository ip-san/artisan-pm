<?php

use App\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return redirect()->route('projects.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/attachments/{media}', AttachmentController::class)->name('attachments.show');

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

    Volt::route('/roles', 'roles.index')->name('roles.index');
    Volt::route('/roles/create', 'roles.form')->name('roles.create');
    Volt::route('/roles/{role}/edit', 'roles.form')->name('roles.edit');

    Volt::route('/custom-fields', 'custom-fields.index')->name('custom-fields.index');
    Volt::route('/custom-fields/create', 'custom-fields.form')->name('custom-fields.create');
    Volt::route('/custom-fields/{customField}/edit', 'custom-fields.form')->name('custom-fields.edit');
});
