<?php

use App\Models\PendingUpload;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @return TestResponse
 */
function postRawUpload(string $uri, string $content)
{
    return test()->call('POST', $uri, [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $content);
}

test('unauthenticated requests are rejected', function () {
    postRawUpload('/api/v1/uploads', 'file contents')->assertUnauthorized();
});

test('a request with an empty body is rejected', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    postRawUpload('/api/v1/uploads', '')->assertUnprocessable();
});

test('an authenticated user can upload a file and receives an id and token', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = postRawUpload('/api/v1/uploads?filename=notes.txt', 'hello world');

    $response->assertCreated();
    $id = $response->json('upload.id');
    $token = $response->json('upload.token');

    $media = Media::findOrFail($id);

    expect($id)->not->toBeNull()
        ->and($token)->toBe("{$id}.{$media->uuid}");

    $pendingUpload = PendingUpload::where('user_id', $user->id)->firstOrFail();
    expect($pendingUpload->pendingMedia()?->file_name)->toBe('notes.txt')
        ->and(file_exists($media->getPath()))->toBeTrue()
        ->and(file_get_contents($media->getPath()))->toBe('hello world');
});

test('a file larger than attachment_max_size is rejected', function () {
    $user = User::factory()->create();
    Setting::set('attachment_max_size', 1);
    Passport::actingAs($user);

    postRawUpload('/api/v1/uploads', str_repeat('x', 2048))->assertUnprocessable();
});

test('a denied file extension is rejected', function () {
    $user = User::factory()->create();
    Setting::set('attachment_extensions_denied', 'exe');
    Passport::actingAs($user);

    postRawUpload('/api/v1/uploads?filename=virus.exe', 'binary content')->assertUnprocessable();
});
