<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which ScmAdapter a Repository resolves to. Filesystem (B'-03) is a plain
 * directory with no history — see ScmCapability.
 */
enum RepositoryType: string
{
    case Git = 'git';
    case Svn = 'svn';
    case Filesystem = 'filesystem';
}
