<?php

use App\Models\User;

test('pagination speaks the user\'s language and keeps its disabled links accessible', function () {
    $admin = User::factory()->admin()->create(['language' => 'ja']);
    User::factory()->count(60)->create();

    $html = $this->actingAs($admin)->get(route('users.index'))->assertOk()->getContent();

    expect($html)->toContain('aria-label="ページ送り"')
        ->and($html)->toMatch('/\d+件中 1〜\d+件目/')
        ->and($html)->toContain('<span role="link" aria-disabled="true" aria-label="前へ">')
        ->and($html)->not->toContain('pagination.previous');

    $admin->update(['language' => 'en']);
    expect($this->actingAs($admin->fresh())->get(route('users.index'))->getContent())->toMatch('/Showing 1 to \d+ of \d+ results/');
});
