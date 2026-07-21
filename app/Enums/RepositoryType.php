<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which ScmAdapter a Repository resolves to. Git and Subversion — the
 * plan's two Phase 4 targets — are implemented; Mercurial/CVS/Bazaar are
 * explicit scope questions for the user, not yet built.
 */
enum RepositoryType: string
{
    case Git = 'git';
    case Svn = 'svn';
}
