<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ProjectStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Redmine's admin "Projects" tab on a user or group (principal_memberships):
 * the projects the account belongs to, with their roles, and adding,
 * changing and removing them from the account's own edit page. The host
 * component says whose memberships these are; only administrators reach the
 * host pages, and each change is also checked against the project's
 * manageMembers ability.
 */
trait ManagesPrincipalMemberships
{
    public ?int $membershipProjectId = null;

    /** @var array<int, int|string> */
    public array $membershipRoleIds = [];

    public ?int $editingMembershipId = null;

    /**
     * The user or group whose memberships these are.
     */
    abstract protected function membershipPrincipal(): Model;

    /**
     * The members column that points at it: user_id or group_id.
     */
    abstract protected function membershipColumn(): string;

    /**
     * @return Collection<int, Member>
     */
    #[Computed]
    public function principalMemberships(): Collection
    {
        return Member::query()
            ->where($this->membershipColumn(), $this->membershipPrincipal()->getKey())
            ->with(['project', 'roles'])
            ->get()
            ->sortBy(fn (Member $member) => $member->project->name)
            ->values();
    }

    /**
     * Active projects the account is not in yet.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function membershipProjects(): Collection
    {
        return Project::query()
            ->where('status', ProjectStatus::Active)
            ->whereNotIn('id', $this->principalMemberships->pluck('project_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function membershipRoles(): Collection
    {
        return Role::query()->givable()->orderBy('position')->get();
    }

    public function editMembership(int $memberId): void
    {
        $member = $this->principalMemberships->firstWhere('id', $memberId);

        abort_if($member === null, 404);

        $this->editingMembershipId = $member->id;
        $this->membershipProjectId = $member->project_id;
        $this->membershipRoleIds = $member->roles->pluck('id')->all();
    }

    public function cancelMembershipEdit(): void
    {
        $this->reset('editingMembershipId', 'membershipProjectId', 'membershipRoleIds');
        $this->resetErrorBag();
    }

    public function saveMembership(): void
    {
        $this->authorize('update', $this->membershipPrincipal());

        $editing = $this->editingMembershipId !== null
            ? $this->principalMemberships->firstWhere('id', $this->editingMembershipId)
            : null;

        abort_if($this->editingMembershipId !== null && $editing === null, 404);

        $data = $this->validate([
            'membershipProjectId' => $editing !== null
                ? ['required', Rule::in([$editing->project_id])]
                : ['required', Rule::in($this->membershipProjects->pluck('id')->all())],
            'membershipRoleIds' => ['required', 'array', 'min:1'],
            'membershipRoleIds.*' => [Rule::in($this->membershipRoles->pluck('id')->all())],
        ], attributes: ['membershipRoleIds' => 'ロール', 'membershipProjectId' => 'プロジェクト']);

        $project = Project::query()->findOrFail($data['membershipProjectId']);

        $this->authorize('manageMembers', $project);

        $member = $editing ?? Member::query()->firstOrCreate([
            'project_id' => $project->id,
            $this->membershipColumn() => $this->membershipPrincipal()->getKey(),
        ]);

        $member->roles()->sync($data['membershipRoleIds']);

        $this->cancelMembershipEdit();
        unset($this->principalMemberships, $this->membershipProjects);
    }

    public function removeMembership(int $memberId): void
    {
        $this->authorize('update', $this->membershipPrincipal());

        $member = $this->principalMemberships->firstWhere('id', $memberId);

        abort_if($member === null, 404);

        $this->authorize('manageMembers', $member->project);

        $member->delete();

        $this->cancelMembershipEdit();
        unset($this->principalMemberships, $this->membershipProjects);
    }
}
