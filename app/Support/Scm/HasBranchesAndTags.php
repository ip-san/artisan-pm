<?php

declare(strict_types=1);

namespace App\Support\Scm;

/**
 * An adapter whose repositories have named branches and tags that can be
 * browsed (Redmine's Repository#branches / #tags: Git, Mercurial, Bazaar
 * tags). Subversion keeps them as paths, and CVS and Filesystem have none.
 */
interface HasBranchesAndTags
{
    /**
     * @return list<string>
     */
    public function branches(): array;

    /**
     * @return list<string>
     */
    public function tags(): array;
}
