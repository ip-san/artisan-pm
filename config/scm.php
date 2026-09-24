<?php

return [

    /*
    |--------------------------------------------------------------------
    | Repositories Root
    |--------------------------------------------------------------------
    |
    | A Repository's path must resolve inside this directory. Project
    | members with manage_repository (a Member-tier, not admin-only,
    | permission) choose which repository a project points at, so an
    | unconstrained path would let them point GitAdapter — which shells
    | out to git — at any directory the app/queue worker can read,
    | including one they've planted a hostile .git/config in. Only a
    | server administrator with filesystem/deploy access can place a
    | directory under this root in the first place, so containment here
    | is what actually limits the blast radius, not validation alone.
    |
    */

    'repositories_root' => env('SCM_REPOSITORIES_ROOT', storage_path('app/private/repositories')),

    /*
    |--------------------------------------------------------------------
    | Allowed Remote Hosts
    |--------------------------------------------------------------------
    |
    | A Subversion repository may point at a remote svn://, http:// or
    | https:// URL instead of a local path (A10-01b) — but only when its
    | host is listed here (comma-separated in SCM_ALLOWED_HOSTS). Empty
    | disables remote repositories entirely. Registering one also needs
    | the manage_remote_repositories permission.
    |
    | Entries are host names or IP addresses (exact, case-insensitive),
    | or CIDR ranges. The host's addresses are resolved when the URL is
    | validated and again before every svn call (against DNS rebinding):
    | a loopback, private, link-local or otherwise non-public address is
    | refused unless that address itself is listed (as an IP or inside a
    | CIDR entry). So an intranet server needs both its name and its
    | address, e.g. "svn.intra.example.com,10.0.5.20".
    |
    */

    'allowed_hosts' => array_values(array_filter(array_map(
        fn (string $entry) => strtolower(trim($entry)),
        explode(',', (string) env('SCM_ALLOWED_HOSTS', '')),
    ))),

];
