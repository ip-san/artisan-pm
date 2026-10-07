<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Collection;

/**
 * Where a viewer may see a role-restricted custom field — Redmine's
 * CustomField#visible_by?(project, user) and visibility_by_project_condition.
 *
 * Administrators and fields without a role restriction are visible
 * everywhere; any other field only in the projects where the viewer holds
 * one of its roles (member roles, or the Non member / Anonymous builtin role
 * on a public project, as AuthorizationService::rolesFor() resolves them).
 * A list spanning several projects offers a field when it is visible in at
 * least one of them, and treats its value as absent on rows of the others,
 * for filtering, sorting, grouping and display alike.
 *
 * The viewer's roles are looked up once per project and reused across
 * fields. Fields must have their `roles` relation loaded.
 */
final class CustomFieldVisibility
{
    /** @var array<int, Collection<int, int>> project id => the viewer's role ids there */
    private array $roleIdsByProject = [];

    public function __construct(
        private readonly ?User $viewer,
        private readonly AuthorizationService $authorization,
    ) {}

    public static function for(?User $viewer): self
    {
        return new self($viewer, app(AuthorizationService::class));
    }

    public function isVisibleIn(CustomField $field, Project $project): bool
    {
        if ($this->isVisibleEverywhere($field)) {
            return true;
        }

        $this->roleIdsByProject[$project->id] ??= $this->authorization->rolesFor($this->viewer, $project)->pluck('id');

        return $field->roles->pluck('id')->intersect($this->roleIdsByProject[$project->id])->isNotEmpty();
    }

    /**
     * The ids of the given projects in which the field is visible, or null
     * when it is visible in every project (no restriction to apply).
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, int>|null
     */
    public function visibleProjectIds(CustomField $field, Collection $projects): ?array
    {
        if ($this->isVisibleEverywhere($field)) {
            return null;
        }

        return $projects
            ->filter(fn (Project $project) => $this->isVisibleIn($field, $project))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Keeps the fields visible in at least one of the given projects.
     *
     * @param  Collection<int, CustomField>  $fields
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, CustomField>
     */
    public function visibleInAny(Collection $fields, Collection $projects): Collection
    {
        return $fields
            ->filter(fn (CustomField $field) => $this->isVisibleEverywhere($field)
                || $projects->contains(fn (Project $project) => $this->isVisibleIn($field, $project)))
            ->values();
    }

    private function isVisibleEverywhere(CustomField $field): bool
    {
        return (bool) $this->viewer?->is_admin || $field->isVisibleToAllRoles();
    }
}
