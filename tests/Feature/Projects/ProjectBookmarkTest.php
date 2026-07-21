<?php

use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('a user can bookmark and unbookmark a project from the show page', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('projects.show', ['project' => $project])->call('toggleBookmark');
    expect($project->isBookmarkedBy($user))->toBeTrue();

    Livewire::actingAs($user)->test('projects.show', ['project' => $project])->call('toggleBookmark');
    expect($project->fresh()->isBookmarkedBy($user))->toBeFalse();
});

test('a user can bookmark a project from the project list', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('projects.index')
        ->call('toggleBookmark', $project->id);

    expect($project->isBookmarkedBy($user))->toBeTrue();
});

test('the bookmarked-only filter shows only bookmarked projects', function () {
    $bookmarked = Project::factory()->create(['name' => 'Bookmarked']);
    $other = Project::factory()->create(['name' => 'Other']);
    $user = User::factory()->create();
    $user->bookmarkedProjects()->attach($bookmarked->id);

    $component = Livewire::actingAs($user)->test('projects.index')->set('bookmarkedOnly', true);

    $names = $component->get('projects')->pluck('name');

    expect($names)->toContain('Bookmarked')->not->toContain('Other');
});
