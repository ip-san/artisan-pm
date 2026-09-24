<?php

use Illuminate\Support\Facades\File;

/**
 * The dark theme (resources/css/app.css) works by overriding the design
 * tokens, so a view only follows the theme if its colour classes are token
 * classes: neutral/brand/danger/success/warning/caution/discovery, surface,
 * and text-white on a bold background. A raw Tailwind palette colour
 * (bg-gray-100, text-red-600, ...) or bg-white would stay light in the dark
 * theme.
 */
function rawPaletteColorClasses(string $contents): array
{
    $utilities = 'bg|text|border(?:-[trblxyse])?|ring(?:-offset)?|divide|outline|fill|stroke|from|via|to|placeholder|accent|caret|decoration|shadow';
    $palettes = 'slate|gray|zinc|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

    preg_match_all(
        "/(?<![\\w-])(?:[\\w-]+:)*(?:(?:{$utilities})-(?:{$palettes})-\\d{2,3}|(?:bg|ring|divide|outline|fill|stroke)-(?:white|black)|(?:text|border)-black)(?:\\/\\d+)?(?![\\w-])/",
        $contents,
        $matches,
    );

    return $matches[0];
}

test('views and view-producing code only use themeable colour classes', function () {
    /**
     * Deliberate exceptions, by path relative to the project root: the
     * classes each file may keep.
     *
     * @var array<string, array<int, string>|true>
     */
    $allowed = [
        // Laravel's stock welcome page; not routed (/ redirects to projects).
        'resources/views/welcome.blade.php' => true,
        // The git-blame block palette keeps a set of blocks apart rather
        // than signalling anything; app.css darkens these in the dark theme.
        'resources/views/livewire/repository/annotate.blade.php' => [
            'bg-red-50', 'bg-orange-50', 'bg-amber-50', 'bg-lime-50', 'bg-green-50', 'bg-teal-50',
            'bg-cyan-50', 'bg-sky-50', 'bg-indigo-50', 'bg-violet-50', 'bg-fuchsia-50', 'bg-rose-50',
        ],
        // The diff preview is always dark (data-theme="dark"); its hunk
        // headers use cyan like a terminal.
        'resources/views/attachments/preview.blade.php' => ['text-cyan-400'],
    ];

    $files = collect([...File::allFiles(resource_path('views')), ...File::allFiles(app_path())])
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php');

    $offenders = [];

    foreach ($files as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $exceptions = $allowed[$relative] ?? [];

        if ($exceptions === true) {
            continue;
        }

        $found = array_values(array_diff(array_unique(rawPaletteColorClasses(File::get($file->getPathname()))), $exceptions));

        if ($found !== []) {
            $offenders[$relative] = $found;
        }
    }

    expect($offenders)->toBe([]);
});

test('the guard recognises raw palette classes, with variants and opacity, but not tokens', function () {
    expect(rawPaletteColorClasses('class="bg-gray-100 hover:text-red-600 border-t-blue-200/50 bg-white text-black ring-white"'))
        ->toBe(['bg-gray-100', 'hover:text-red-600', 'border-t-blue-200/50', 'bg-white', 'text-black', 'ring-white'])
        ->and(rawPaletteColorClasses('class="bg-surface text-neutral-700 bg-brand-bold text-white border-danger-subtle bg-neutral-50/50"'))
        ->toBe([]);
});
