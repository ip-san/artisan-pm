<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Support\Authorization\AuthorizationService;

final class WikiPagePolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function viewAny(?User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'view_wiki_pages', $project);
    }

    public function view(?User $user, WikiPage $wikiPage): bool
    {
        return $this->authorization->can($user, 'view_wiki_pages', $wikiPage->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->authorization->can($user, 'edit_wiki_pages', $project);
    }

    /**
     * A protected page can only be edited by someone who can also protect
     * pages — matching Redmine's WikiPage#editable_by? rule.
     */
    public function update(User $user, WikiPage $wikiPage): bool
    {
        if (! $this->authorization->can($user, 'edit_wiki_pages', $wikiPage->project)) {
            return false;
        }

        return ! $wikiPage->is_protected || $this->authorization->can($user, 'protect_wiki_pages', $wikiPage->project);
    }

    public function rename(User $user, WikiPage $wikiPage): bool
    {
        return $this->authorization->can($user, 'rename_wiki_pages', $wikiPage->project);
    }

    public function protect(User $user, WikiPage $wikiPage): bool
    {
        return $this->authorization->can($user, 'protect_wiki_pages', $wikiPage->project);
    }

    public function delete(User $user, WikiPage $wikiPage): bool
    {
        return $this->authorization->can($user, 'delete_wiki_pages', $wikiPage->project);
    }
}
