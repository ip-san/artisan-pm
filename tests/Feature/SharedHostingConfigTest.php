<?php

use Illuminate\Support\Facades\File;

/**
 * Shared hosting: no writable system temp directory under open_basedir,
 * no image optimizer binaries.
 */
test('dompdf keeps its temporary files under storage and creates the directory', function () {
    $tempDir = config('dompdf.options.temp_dir');
    File::deleteDirectory($tempDir);

    app('dompdf');

    expect($tempDir)->toStartWith(storage_path())
        ->and(is_dir($tempDir))->toBeTrue();
});

test('image optimizers are off unless MEDIA_IMAGE_OPTIMIZERS is set', function () {
    expect(config('media-library.image_optimizers'))->toBe([]);
});
