<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which ScmAdapter a Repository resolves to. Only Git is implemented —
 * Subversion is the plan's other Phase 4 target, and Mercurial/CVS/Bazaar
 * are explicit scope questions for the user, not yet built.
 */
enum RepositoryType: string
{
    case Git = 'git';
}
