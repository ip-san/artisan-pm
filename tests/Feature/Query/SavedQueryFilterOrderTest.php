<?php

use App\Models\Query as SavedQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keys MySQL's JSON type would re-sort (it orders object keys by length,
 * then bytewise), at the top level and inside each filter.
 *
 * @return array<string, array{operator: string, values: array<int, string>}>
 */
function unsortedFilters(): array
{
    return [
        'tracker_id' => ['operator' => '=', 'values' => ['1']],
        'status' => ['operator' => 'o', 'values' => []],
        'subject' => ['operator' => '~', 'values' => ['ünïcode']],
        'any_searchable' => ['operator' => '~', 'values' => ['x']],
    ];
}

test('saved query filters come back in the order they were saved, on any database', function () {
    $query = SavedQuery::create([
        'name' => 'Ordered', 'type' => 'issue', 'user_id' => User::factory()->create()->id,
        'project_id' => null, 'visibility' => 'private',
        'filters' => unsortedFilters(), 'column_names' => ['subject'],
    ]);

    expect(SavedQuery::query()->findOrFail($query->id)->filters)->toBe(unsortedFilters());
});

test('empty filters stay an empty array', function () {
    $query = SavedQuery::create([
        'name' => 'Empty', 'type' => 'issue', 'user_id' => User::factory()->create()->id,
        'project_id' => null, 'visibility' => 'private', 'filters' => [], 'column_names' => [],
    ]);

    expect(SavedQuery::query()->findOrFail($query->id)->filters)->toBe([]);
});

test('a query saved before the order-preserving format is still read', function () {
    $userId = User::factory()->create()->id;
    DB::table('queries')->insert([
        'name' => 'Legacy', 'type' => 'issue', 'user_id' => $userId, 'visibility' => 'private',
        'filters' => json_encode(['status' => ['operator' => 'o', 'values' => []]]),
        'column_names' => json_encode(['subject']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // toEqual: on MySQL a row stored as a JSON object has already lost its key order.
    expect(SavedQuery::query()->where('name', 'Legacy')->sole()->filters)
        ->toEqual(['status' => ['operator' => 'o', 'values' => []]]);
});
