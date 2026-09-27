{{--
    A small outline icon set drawn on a 24px grid, so navigation and actions can carry a symbol
    without adding an icon package. Decorative only: the label next to it is what screen readers read.
--}}
@props(['name'])

@php
    $paths = [
        'home' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
        'folder' => '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5H9l2 2h8.5A1.5 1.5 0 0 1 21 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5z"/>',
        'issue' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'activity' => '<path d="M3 12h4l3-7 4 14 3-7h4"/>',
        'news' => '<path d="M4 10v4h3l7 4V6l-7 4z"/><path d="M18 9a4 4 0 0 1 0 6"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="1.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'gantt' => '<path d="M4 6h8M8 12h10M6 18h7"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
        'overview' => '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
        'roadmap' => '<path d="M5 21V4h11l-2 4 2 4H5"/>',
        'wiki' => '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H11v16H5.5A1.5 1.5 0 0 1 4 18.5zM20 5.5A1.5 1.5 0 0 0 18.5 4H13v16h5.5a1.5 1.5 0 0 0 1.5-1.5z"/>',
        'forum' => '<path d="M4 5h16v11H9l-5 4z"/>',
        'document' => '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
        'file' => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 20h14"/>',
        'code' => '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M13.5 5l-3 14"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0M16 4.5a3.5 3.5 0 0 1 0 7M21 20a6 6 0 0 0-3.5-5.5"/>',
        'tag' => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.25"/>',
        'version' => '<path d="M4 7.5 12 4l8 3.5v9L12 20l-8-3.5z"/><path d="M4 7.5 12 11l8-3.5M12 11v9"/>',
        'settings' => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'back' => '<path d="M15 5l-7 7 7 7"/>',
        'plugin' => '<path d="M5 5h6v6H5zM13 13h6v6h-6z"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.5 8-8 9-4.5-1-8-4.5-8-9V6z"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'size-5 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $paths[$name] ?? '' !!}</svg>
