<?php

use App\Jobs\PruneExpiredPendingUploadsJob;
use App\Models\PendingUpload;
use App\Models\User;

function pendingUploadTestFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'pending-upload-test-').'.txt';
    file_put_contents($path, 'test content');

    return $path;
}

test('a pending upload older than the expiry window is deleted along with its media', function () {
    $user = User::factory()->create();
    $upload = PendingUpload::factory()->for($user)->create(['created_at' => now()->subHours(2)]);
    $media = $upload->addMedia(pendingUploadTestFile())->toMediaCollection('pending');

    (new PruneExpiredPendingUploadsJob)->handle();

    expect(PendingUpload::find($upload->id))->toBeNull()
        ->and($media->fresh())->toBeNull();
});

test('a recent pending upload is left alone', function () {
    $user = User::factory()->create();
    $upload = PendingUpload::factory()->for($user)->create();
    $upload->addMedia(pendingUploadTestFile())->toMediaCollection('pending');

    (new PruneExpiredPendingUploadsJob)->handle();

    expect(PendingUpload::find($upload->id))->not->toBeNull();
});
