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
    Volt::route('/projects/{project:identifier}/issues/{issue}', 'issues.show')->name('issues.show');
    Volt::route('/projects/{project:identifier}/issues/{issue}/edit', 'issues.form')->name('issues.edit');

    Volt::route('/roles', 'roles.index')->name('roles.index');
    Volt::route('/roles/create', 'roles.form')->name('roles.create');
    Volt::route('/roles/{role}/edit', 'roles.form')->name('roles.edit');

    Volt::route('/custom-fields', 'custom-fields.index')->name('custom-fields.index');
    Volt::route('/custom-fields/create', 'custom-fields.form')->name('custom-fields.create');
    Volt::route('/custom-fields/{customField}/edit', 'custom-fields.form')->name('custom-fields.edit');
});
