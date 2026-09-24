<?php

use App\Enums\MailNotificationOption;
use App\Mail\NewsNotificationMail;
use App\Models\Board;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Message;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ProjectEventNotification;
use App\Services\IncomingMailService;
use App\Support\Mail\ParsedIncomingMail;

/**
 * @param  array<int, string>  $permissions
 * @return array{0: Project, 1: User}
 */
function replyingMember(array $permissions): array
{
    $project = Project::factory()->create();
    $user = User::factory()->create(['email' => 'poster@example.com', 'mail_notification' => MailNotificationOption::All]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return [$project, $user];
}

function receiveReply(string $subject, string $body, array $headers = []): mixed
{
    return app(IncomingMailService::class)->createIssueFromMail(new ParsedIncomingMail(
        subject: $subject,
        body: $body,
        fromEmail: 'poster@example.com',
        replyHeaders: $headers,
    ));
}

test('a reply to a forum post mail becomes a reply to its topic', function () {
    [$project, $user] = replyingMember(['view_messages', 'add_messages']);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create(['subject' => 'Release plan']);
    $answer = Message::factory()->for($board)->create(['parent_id' => $topic->id, 'subject' => 'RE: Release plan']);

    $reply = receiveReply('Re: whatever', 'Sounds good', ["<redmine.message-{$answer->id}.20260101000000.{$user->id}@example.com>"]);

    expect($reply)->toBeInstanceOf(Message::class)
        ->and($reply->parent_id)->toBe($topic->id)
        ->and($reply->author_id)->toBe($user->id)
        ->and($reply->content)->toBe('Sounds good')
        ->and(Issue::query()->count())->toBe(0);
});

test('a forum reply is also found by the msg subject tag', function () {
    [$project, $user] = replyingMember(['view_messages', 'add_messages']);
    $topic = Message::factory()->for(Board::factory()->for($project)->create(['name' => 'General']))->create(['subject' => 'Release plan']);

    $reply = receiveReply("Re: [{$project->name} - General - msg{$topic->id}] Release plan", 'Agreed');

    expect($reply)->toBeInstanceOf(Message::class)
        ->and($reply->parent_id)->toBe($topic->id)
        ->and($reply->subject)->toBe('Release plan');
});

test('a forum reply is refused on a locked topic or without add_messages', function () {
    [$project, $user] = replyingMember(['view_messages']);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create();

    expect(receiveReply('Re: x', 'No permission', ["<redmine.message-{$topic->id}.20260101000000@example.com>"]))->toBeNull();

    $otherProject = Project::factory()->create();
    Member::factory()->for($otherProject)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_messages', 'add_messages']]));
    $locked = Message::factory()->for(Board::factory()->for($otherProject)->create())->create(['is_locked' => true]);

    expect(receiveReply('Re: x', 'Locked', ["<redmine.message-{$locked->id}.20260101000000@example.com>"]))->toBeNull()
        ->and(Message::query()->whereNotNull('parent_id')->count())->toBe(0);
});

test('a reply to a news or news comment mail becomes a comment on the news', function () {
    [$project, $user] = replyingMember(['view_news', 'comment_news']);
    $news = News::factory()->for($project)->create();
    $comment = NewsComment::create(['news_id' => $news->id, 'author_id' => User::factory()->create()->id, 'content' => 'First']);

    $fromNews = receiveReply('Re: news', 'Nice news', ["<redmine.news-{$news->id}.20260101000000@example.com>"]);
    $fromComment = receiveReply('Re: news', 'Me too', ["<redmine.news-{$news->id}.20260101000000@example.com> <redmine.comment-{$comment->id}.20260101000000@example.com>"]);

    expect($fromNews)->toBeInstanceOf(NewsComment::class)
        ->and($fromNews->news_id)->toBe($news->id)
        ->and($fromComment->news_id)->toBe($news->id)
        ->and($news->comments()->pluck('content')->all())->toContain('Nice news', 'Me too');
});

test('a news reply without comment_news is ignored', function () {
    [$project] = replyingMember(['view_news']);
    $news = News::factory()->for($project)->create();

    expect(receiveReply('Re: news', 'Nope', ["<redmine.news-{$news->id}.20260101000000@example.com>"]))->toBeNull()
        ->and($news->comments()->count())->toBe(0);
});

test('a news reply from someone who cannot see the project is ignored', function () {
    [, $user] = replyingMember(['view_news', 'comment_news']);
    $hidden = News::factory()->for(Project::factory()->private()->create())->create();

    expect(receiveReply('Re: news', 'Sneaky', ["<redmine.news-{$hidden->id}.20260101000000@example.com>"]))->toBeNull();
});

test('news and forum mails carry the ids a reply points back to', function () {
    [$project, $user] = replyingMember(['view_news']);
    $news = News::factory()->for($project)->create();
    $comment = NewsComment::create(['news_id' => $news->id, 'author_id' => $user->id, 'content' => 'x']);

    $newsHeaders = (new NewsNotificationMail($news, 'added', $user))->forRecipient($user)->headers();
    $commentHeaders = (new NewsNotificationMail($news, 'comment_added', $user, $comment))->forRecipient($user)->headers();

    expect($newsHeaders->messageId)->toStartWith("redmine.news-{$news->id}.")
        ->and($commentHeaders->messageId)->toStartWith("redmine.comment-{$comment->id}.")
        ->and($commentHeaders->references[0])->toStartWith("redmine.news-{$news->id}.");

    $board = Board::factory()->for($project)->create(['name' => 'General']);
    $topic = Message::factory()->for($board)->create();
    $answer = Message::factory()->for($board)->create(['parent_id' => $topic->id]);
    $mail = (new ProjectEventNotification('message_posted', ['project' => $project->name, 'board' => 'General', 'id' => $topic->id, 'subject' => 'S', 'author' => 'A'], 'S', 'https://example.test', null, $answer))->toMail($user);

    expect($mail->headers()->messageId)->toStartWith("redmine.message-{$answer->id}.")
        ->and($mail->headers()->references[0])->toStartWith("redmine.message-{$topic->id}.")
        ->and($mail->subjectLine)->toBe("[{$project->name} - General - msg{$topic->id}] S");
});
