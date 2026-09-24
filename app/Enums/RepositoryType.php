<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which ScmAdapter a Repository resolves to. Filesystem (B'-03) is a plain
 * directory with no history — see ScmCapability. Mercurial, CVS and Bazaar
 * (B'-01) follow Redmine's adapters of the same names.
 */
enum RepositoryType: string
{
    case Git = 'git';
    case Svn = 'svn';
    case Filesystem = 'filesystem';
    case Mercurial = 'mercurial';
}
