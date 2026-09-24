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
    case Bazaar = 'bazaar';
    case Cvs = 'cvs';

    /**
     * The enabled_scm_types default, as Redmine's settings.yml enabled_scm:
     * every type except Filesystem, which an administrator enables
     * explicitly.
     *
     * @return array<int, string>
     */
    public static function defaultEnabledValues(): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => $type !== self::Filesystem),
        ));
    }
}
