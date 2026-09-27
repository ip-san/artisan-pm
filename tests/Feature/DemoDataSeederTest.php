<?php

use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\WikiPageVersion;
use Database\Seeders\DemoDataSeeder;

test('the demo data seeder builds a lived-in data set and is safe to run twice', function () {
    putenv('DEMO_DATA_SCALE=0.1');
    $this->seed();
    $this->seed(DemoDataSeeder::class);

    expect(Project::query()->where('identifier', 'ec-payment')->value('parent_id'))
        ->toBe(Project::query()->where('identifier', 'ec-renewal')->value('id'))
        ->and(Issue::query()->count())->toBeGreaterThan(40)
        ->and(Issue::query()->whereNotNull('closed_on')->exists())->toBeTrue()
        ->and(Issue::query()->whereNotNull('parent_id')->exists())->toBeTrue()
        ->and(Journal::query()->whereHas('details')->exists())->toBeTrue()
        ->and(TimeEntry::query()->exists())->toBeTrue()
        ->and(WikiPageVersion::query()->count())->toBeGreaterThan(Project::query()->count());

    $issues = Issue::query()->count();
    $this->seed(DemoDataSeeder::class);

    expect(Issue::query()->count())->toBe($issues);
})->after(fn () => putenv('DEMO_DATA_SCALE'));
