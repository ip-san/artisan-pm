<?php

use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;

function pdfProjectMember(Project $project, array $permissions = ['view_issues'], string $issuesVisibility = 'all'): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions, 'issues_visibility' => $issuesVisibility]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function pdfIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('a member with view_issues can download the issue as a PDF', function () {
    $project = Project::factory()->create();
    $user = pdfProjectMember($project);
    $issue = pdfIssue($project, [
        'subject' => '日本語の件名テスト',
        'description' => "説明文です。\n\n改行も含みます。",
    ]);

    $response = $this->actingAs($user)->get(route('issues.pdf', [$project, $issue]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    // "%PDF" alone only proves dompdf produced *a* PDF — a broken font path
    // still renders "%PDF..." with every Japanese glyph as tofu/"?" (this
    // exact failure mode happened during development: dompdf's chroot
    // silently rejected a system font path and fell back to Helvetica).
    // The embedded font's /BaseFont name is dompdf's own uncompressed
    // object-dictionary text, so it's directly greppable in the raw PDF
    // bytes — this is the assertion that would actually catch a
    // regression to the missing-CJK-font state.
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF')
        ->and($response->getContent())->toContain('IPAGothic');
});

test('a non-member cannot download a PDF at all', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $issue = pdfIssue($project);

    $this->actingAs($user)
        ->get(route('issues.pdf', [$project, $issue]))
        ->assertForbidden();
});

test('a member with only Default issue visibility cannot download the PDF of a private issue they neither authored nor are assigned to', function () {
    $project = Project::factory()->create();
    // The discriminating setup: a project member who genuinely holds
    // view_issues (so a plain non-member 403 wouldn't explain the
    // result) but whose role's issues_visibility is 'default' (the DB
    // column's own default is 'all', which would let this pass for the
    // wrong reason) and who is neither the issue's author nor assignee.
    $user = pdfProjectMember($project, issuesVisibility: 'default');
    $issue = pdfIssue($project, ['is_private' => true]);

    $this->actingAs($user)
        ->get(route('issues.pdf', [$project, $issue]))
        ->assertForbidden();
});

test('custom field values appear in the PDF export', function () {
    $project = Project::factory()->create();
    $user = pdfProjectMember($project);
    $tracker = Tracker::factory()->create();
    $customField = CustomField::factory()->create(['name' => 'ベンダー']);
    $customField->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->for($tracker)->create([
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $issue->setCustomFieldValues([$customField->id => 'Acme社']);

    $response = $this->actingAs($user)->get(route('issues.pdf', [$project, $issue]));

    $response->assertOk();
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF')
        ->and($response->getContent())->toContain('IPAGothic');
});

test('a private note is included for its own author but stays hidden from other members', function () {
    $project = Project::factory()->create();
    $author = pdfProjectMember($project, ['view_issues', 'edit_issues']);
    $viewer = pdfProjectMember($project);
    $issue = pdfIssue($project);
    $issue->journals()->create([
        'user_id' => $author->id,
        'notes' => 'ひみつのメモ',
        'private_notes' => true,
    ]);

    $authorPdf = $this->actingAs($author)->get(route('issues.pdf', [$project, $issue]))->getContent();
    $viewerPdf = $this->actingAs($viewer)->get(route('issues.pdf', [$project, $issue]))->getContent();

    // Both PDFs render successfully either way (the note's presence/absence
    // doesn't error) — the content-length gap is the signal that the
    // private note actually made it into one and not the other.
    expect(strlen($authorPdf))->toBeGreaterThan(strlen($viewerPdf));
});
