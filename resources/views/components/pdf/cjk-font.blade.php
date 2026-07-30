{{--
    Shared by every PDF export view (issue, wiki, ...). dompdf ships no
    CJK-capable font of its own, and its default chroot only allows local
    file access under base_path() — a system font path (e.g.
    /usr/share/fonts/...) is silently rejected and dompdf falls back to
    Helvetica, rendering every Japanese character as "?" (confirmed
    empirically). IPAGothic (resources/fonts/ipag.ttf, redistributed under
    the IPA Font License v1.0, which permits this — see
    resources/fonts/IPA_Font_License_Agreement_v1.0.txt) is bundled with the
    app instead, so it's inside the chroot and doesn't depend on what the
    host/container happens to have installed.

    Registered under both weights (same file — IPAGothic has no separate
    bold master) because dompdf resolves @font-face by family+weight
    together: a bold element with no bold-weight entry falls back out of
    the family entirely rather than synthesizing bold from the normal face,
    silently reverting to Helvetica/"?" for just those elements — confirmed
    empirically.

    dompdf's table/heading rendering doesn't reliably inherit font-family
    from <body> (confirmed empirically: table-cell text rendered as "?"
    while sibling non-table text using the exact same inherited
    font-family rendered correctly) — every element is targeted explicitly
    via `*` rather than relying on the cascade.
--}}
@font-face {
    font-family: 'IPAGothic';
    font-weight: normal;
    src: url('{{ resource_path('fonts/ipag.ttf') }}') format('truetype');
}
@font-face {
    font-family: 'IPAGothic';
    font-weight: bold;
    src: url('{{ resource_path('fonts/ipag.ttf') }}') format('truetype');
}
* { font-family: 'IPAGothic', sans-serif; }
