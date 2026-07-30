<?php

use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

test('welcome_text is rendered as Markdown on the project list page', function () {
    Setting::set('welcome_text', "**Welcome** to the tracker.\n\nSee [[Some Page]] for more.");
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)->test('projects.index')->html();

    expect($html)->toContain('<strong>Welcome</strong>')
        ->toContain('to the tracker')
        // No project is in scope on this page, so a [[Page]] link is left
        // as literal text rather than resolved against an arbitrary one.
        ->toContain('[[Some Page]]');
});

test('nothing is rendered when welcome_text is unset', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)->test('projects.index')->html();

    expect($html)->not->toContain('to the tracker');
});
