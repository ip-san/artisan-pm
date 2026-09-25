<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function parentSubjectMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('A15-06: the parent subject column shows the parent issue subject', function () {
    $project = Project::factory()->create();
    $user = parentSubjectMember($project);
    $parent = Issue::factory()->for($project)->create(['subject' => 'Umbrella task']);
    $child = Issue::factory()->for($project)->create(['subject' => 'Sub task', 'parent_id' => $parent->id]);
    $orphan = Issue::factory()->for($project)->create(['subject' => 'No parent']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', 'parent_subject'])
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名', '親課題の題名']).csvRow([$orphan->subject, '']).csvRow([$child->subject, $parent->subject]).csvRow([$parent->subject, ''])
        );
});

test('A15-06: the parent subject is blank when the parent is not visible to the viewer', function () {
    $project = Project::factory()->create();
    $privateParentProject = Project::factory()->private()->create();
    $user = parentSubjectMember($project);

    $parent = Issue::factory()->for($privateParentProject)->create(['subject' => 'Secret umbrella task']);
    $child = Issue::factory()->for($project)->create(['subject' => 'Visible child', 'parent_id' => $parent->id]);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', 'parent_subject'])
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名', '親課題の題名']).csvRow([$child->subject, ''])
        );
});
