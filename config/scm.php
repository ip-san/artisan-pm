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

];
