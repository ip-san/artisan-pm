<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Notifications\IssueNotification;
use App\Notifications\ProjectEventNotification;
use App\Support\Format\DateTimes;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

/**
 * @return list<string>
 */
function sentMailTexts(): array
{
    return Mail::mailer()->getSymfonyTransport()->messages()
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->map(fn ($message) => $message->getSubject()."\n".$message->getTextBody())
        ->values()->all();
}

test('each recipient gets the mail in their own language', function () {
    $issue = Issue::factory()->for(Project::factory()->create(['name' => 'Alpha']))->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $actor = User::factory()->create();

    User::factory()->create(['language' => 'en'])->notify(new IssueNotification($issue, 'created', $actor));
    User::factory()->create(['language' => 'ja'])->notify(new IssueNotification($issue, 'created', $actor));

    [$english, $japanese] = sentMailTexts();

    expect($english)->toContain('The issue has been created.')->not->toContain('課題が作成されました。')
        ->and($japanese)->toContain('課題が作成されました。')
        ->and(app()->getLocale())->toBe('ja');
});

test('the project event mail is worded when it is sent, per recipient', function () {
    $notification = new ProjectEventNotification('document_added', ['project' => 'Alpha', 'title' => 'Spec', 'author' => 'Alice'], 'Spec', 'https://example.test/d/1');

    User::factory()->create(['language' => 'en'])->notify($notification);
    User::factory()->create()->notify($notification);

    [$english, $japanese] = sentMailTexts();

    expect($english)->toContain('[Alpha] New document: Spec')->toContain('Alice added a document.')
        ->and($japanese)->toContain('[Alpha] 文書を追加しました: Spec')->toContain('Alice さんが文書を追加しました。');
});

test('a user without a language, or with the default forced, receives the default language', function () {
    Setting::set('default_language', 'en');

    expect(User::factory()->create(['language' => null])->preferredLocale())->toBe('en')
        ->and(User::factory()->create(['language' => 'ja'])->preferredLocale())->toBe('ja');

    Setting::set('force_default_language_for_loggedin', true);

    expect(User::factory()->create(['language' => 'ja'])->preferredLocale())->toBe('en');
});

test('each recipient gets the mail with times in their own zone, whoever sent it', function () {
    $issue = Issue::factory()->for(Project::factory()->create())->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $actor = User::factory()->create(['time_zone' => 'America/New_York']);
    $zones = [];
    View::composer('mail.issues.*', function () use (&$zones): void {
        $zones[] = DateTimes::timeZone();
    });

    $this->actingAs($actor);
    User::factory()->create(['time_zone' => 'Asia/Tokyo'])->notify(new IssueNotification($issue, 'created', $actor));
    User::factory()->create(['time_zone' => null])->notify(new IssueNotification($issue, 'created', $actor));

    expect(array_values(array_unique($zones)))->toBe(['Asia/Tokyo', 'UTC'])
        ->and(DateTimes::timeZone())->toBe('America/New_York');
});
