<?php

use App\Models\Board;
use App\Models\Member;
use App\Models\Message;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function boardMember(Project $project, array $permissions = ['view_messages', 'add_messages']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with view_messages can see the board list and a board', function () {
    $project = Project::factory()->create();
    $user = boardMember($project, ['view_messages']);
    $board = Board::factory()->for($project)->create();

    Livewire::actingAs($user)->test('boards.index', ['project' => $project])->assertOk();
    Livewire::actingAs($user)->test('boards.show', ['project' => $project, 'board' => $board])->assertOk();
});

test('a member without view_messages is forbidden from boards', function () {
    $project = Project::factory()->create();
    $user = boardMember($project, []);
    $board = Board::factory()->for($project)->create();

    Livewire::actingAs($user)->test('boards.index', ['project' => $project])->assertForbidden();
    Livewire::actingAs($user)->test('boards.show', ['project' => $project, 'board' => $board])->assertForbidden();
});

test('only a member with manage_boards can create or edit a board', function () {
    $project = Project::factory()->create();
    $member = boardMember($project, ['view_messages']);
    $manager = boardMember($project, ['view_messages', 'manage_boards']);
    $board = Board::factory()->for($project)->create();

    Livewire::actingAs($member)->test('boards.form', ['project' => $project])->assertForbidden();

    Livewire::actingAs($manager)
        ->test('boards.form', ['project' => $project])
        ->set('name', 'General')
        ->call('save');

    expect(Board::where('name', 'General')->exists())->toBeTrue();
});

test('a member with add_messages can start a new topic', function () {
    $project = Project::factory()->create();
    $user = boardMember($project);
    $board = Board::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('messages.form', ['project' => $project, 'board' => $board])
        ->set('subject', 'Hello')
        ->set('content', 'World')
        ->call('save');

    $topic = Message::where('subject', 'Hello')->firstOrFail();

    expect($topic->board_id)->toBe($board->id)
        ->and($topic->parent_id)->toBeNull()
        ->and($topic->author_id)->toBe($user->id);
});

test('a member without add_messages cannot start a topic', function () {
    $project = Project::factory()->create();
    $user = boardMember($project, ['view_messages']);
    $board = Board::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('messages.form', ['project' => $project, 'board' => $board])
        ->assertForbidden();
});

test('a member can reply to an unlocked topic', function () {
    $project = Project::factory()->create();
    $user = boardMember($project);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create();

    Livewire::actingAs($user)
        ->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $topic])
        ->set('replyContent', 'Thanks!')
        ->call('addReply');

    expect($topic->replies)->toHaveCount(1)
        ->and($topic->replies->first()->content)->toBe('Thanks!')
        ->and($topic->replies->first()->subject)->toBe("RE: {$topic->subject}");
});

test('nobody can reply to a locked topic, even a board manager', function () {
    $project = Project::factory()->create();
    $manager = boardMember($project, ['view_messages', 'add_messages', 'manage_boards', 'edit_messages']);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create(['is_locked' => true]);

    Livewire::actingAs($manager)
        ->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $topic])
        ->set('replyContent', 'Too late')
        ->call('addReply')
        ->assertForbidden();

    expect($topic->replies)->toHaveCount(0);
});

test('only edit_messages holders can toggle sticky and locked, not edit_own_messages authors', function () {
    $project = Project::factory()->create();
    $author = boardMember($project, ['view_messages', 'add_messages', 'edit_own_messages']);
    $moderator = boardMember($project, ['view_messages', 'add_messages', 'edit_messages']);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->for($author, 'author')->create();

    $ownerComponent = Livewire::actingAs($author)->test('messages.form', ['project' => $project, 'board' => $board, 'message' => $topic]);
    expect($ownerComponent->get('canManageFlags'))->toBeFalse();

    $ownerComponent->set('subject', 'Edited by owner')->call('save');
    expect($topic->fresh()->subject)->toBe('Edited by owner');

    $moderatorComponent = Livewire::actingAs($moderator)->test('messages.form', ['project' => $project, 'board' => $board, 'message' => $topic]);
    expect($moderatorComponent->get('canManageFlags'))->toBeTrue();

    $moderatorComponent->set('is_sticky', true)->call('save');
    expect($topic->fresh()->is_sticky)->toBeTrue();
});

test('a member with only edit_own_messages cannot edit another members message', function () {
    $project = Project::factory()->create();
    $author = boardMember($project);
    $otherUser = boardMember($project, ['view_messages', 'add_messages', 'edit_own_messages']);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->for($author, 'author')->create();

    Livewire::actingAs($otherUser)
        ->test('messages.form', ['project' => $project, 'board' => $board, 'message' => $topic])
        ->assertForbidden();
});

test('deleting a topic cascades to its replies', function () {
    $project = Project::factory()->create();
    $user = boardMember($project, ['view_messages', 'add_messages', 'delete_own_messages']);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->for($user, 'author')->create();
    $reply = Message::factory()->for($board)->create(['parent_id' => $topic->id]);

    Livewire::actingAs($user)
        ->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $topic])
        ->call('deleteMessage', $topic->id);

    expect(Message::find($topic->id))->toBeNull()
        ->and(Message::find($reply->id))->toBeNull();
});

test('sticky topics are listed before non-sticky topics regardless of recency', function () {
    $project = Project::factory()->create();
    $user = boardMember($project, ['view_messages']);
    $board = Board::factory()->for($project)->create();
    $older = Message::factory()->for($board)->create(['is_sticky' => true, 'created_at' => now()->subDays(5)]);
    $newer = Message::factory()->for($board)->create(['is_sticky' => false, 'created_at' => now()]);

    $component = Livewire::actingAs($user)->test('boards.show', ['project' => $project, 'board' => $board]);

    expect($component->get('topics')->pluck('id')->all())->toBe([$older->id, $newer->id]);
});

test('visiting a reply directly redirects to its parent topic', function () {
    $project = Project::factory()->create();
    $user = boardMember($project, ['view_messages']);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create();
    $reply = Message::factory()->for($board)->create(['parent_id' => $topic->id]);

    Livewire::actingAs($user)
        ->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $reply])
        ->assertRedirect(route('messages.show', [$project, $board, $topic]));
});

test('a member with add_messages can attach a file when starting a topic, and delete it', function () {
    $project = Project::factory()->create();
    $user = boardMember($project, ['view_messages', 'add_messages', 'edit_own_messages']);
    $board = Board::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('messages.form', ['project' => $project, 'board' => $board])
        ->set('subject', 'Topic with a file')
        ->set('content', 'body')
        ->set('newAttachments', [UploadedFile::fake()->create('spec.pdf', 200)])
        ->call('save')
        ->assertRedirect();

    $topic = Message::where('subject', 'Topic with a file')->firstOrFail();
    expect($topic->attachments())->toHaveCount(1);

    $media = $topic->attachments()->first();

    Livewire::actingAs($user)
        ->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $topic])
        ->call('deleteAttachment', $topic->id, $media->id);

    expect($topic->fresh()->attachments())->toHaveCount(0);
});

test('a member with view_messages can watch and unwatch a topic', function () {
    $project = Project::factory()->create();
    $user = boardMember($project, ['view_messages']);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create();

    Livewire::actingAs($user)
        ->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $topic])
        ->call('toggleWatch');

    expect($topic->fresh()->isWatchedBy($user))->toBeTrue();

    Livewire::actingAs($user)
        ->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $topic])
        ->call('toggleWatch');

    expect($topic->fresh()->isWatchedBy($user))->toBeFalse();
});
