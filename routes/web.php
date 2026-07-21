<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return redirect()->route('projects.index');
});

Route::middleware('auth')->group(function () {
    Volt::route('/projects', 'projects.index')->name('projects.index');
    Volt::route('/projects/create', 'projects.form')->name('projects.create');
    Volt::route('/projects/{project:identifier}', 'projects.show')->name('projects.show');
    Volt::route('/projects/{project:identifier}/edit', 'projects.form')->name('projects.edit');
    Volt::route('/projects/{project:identifier}/members', 'projects.members')->name('projects.members');

    Volt::route('/roles', 'roles.index')->name('roles.index');
    Volt::route('/roles/create', 'roles.form')->name('roles.create');
    Volt::route('/roles/{role}/edit', 'roles.form')->name('roles.edit');
});
