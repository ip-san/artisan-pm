<?php

use App\Enums\CustomFieldFormat;
use App\Enums\ImportStatus;
use App\Jobs\ImportIssuesJob;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueImport;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function importMember(Project $project): User
{
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'import_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function csvFile(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

test('uploading a csv auto-detects headers and lets them be mapped', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $user = importMember($project);

    $csv = "subject,description\nFirst row,Some text\n";

    $component = Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv));

    expect($component->get('headers'))->toBe(['subject', 'description'])
        ->and($component->get('mapping')['subject'])->toBe('subject');
});

test('starting an import dispatches a job that creates issues from csv rows', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "件名,説明\nログインできない,詳細な説明です\nダークモード対応,別の説明\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', '件名')
        ->set('mapping.description', '説明')
        ->call('startImport')
        ->assertRedirect();

    $import = IssueImport::firstOrFail();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->imported_count)->toBe(2)
        ->and($import->failed_count)->toBe(0)
        ->and(Issue::where('subject', 'ログインできない')->exists())->toBeTrue()
        ->and(Issue::where('subject', 'ダークモード対応')->where('description', '別の説明')->exists())->toBeTrue();
});

test('a row missing its mapped subject is recorded as a failure without stopping the rest of the import', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "subject\n\nValid subject\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->call('startImport');

    $import = IssueImport::firstOrFail();

    expect($import->imported_count)->toBe(1)
        ->and($import->failed_count)->toBe(1)
        ->and($import->errors)->toHaveCount(1)
        ->and(Issue::where('subject', 'Valid subject')->exists())->toBeTrue();
});

test('rows resolve tracker, status, and priority by name and fall back to project defaults', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $defaultTracker = Tracker::factory()->create(['name' => 'Bug']);
    $namedTracker = Tracker::factory()->create(['name' => 'Feature']);
    $project->trackers()->attach([$defaultTracker->id, $namedTracker->id]);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "subject,tracker\nUses default tracker,\nUses named tracker,Feature\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.tracker', 'tracker')
        ->call('startImport');

    $defaultRow = Issue::where('subject', 'Uses default tracker')->firstOrFail();
    $namedRow = Issue::where('subject', 'Uses named tracker')->firstOrFail();

    expect($defaultRow->tracker_id)->toBe($defaultTracker->id)
        ->and($namedRow->tracker_id)->toBe($namedTracker->id);
});

test('a user without add_issues cannot start an import', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)->test('issues.import', ['project' => $project])->assertForbidden();
});

test('the import status page shows progress and errors once finished', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "subject\nOnly row\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->call('startImport');

    $import = IssueImport::firstOrFail();

    Livewire::actingAs($user)
        ->test('issues.import-status', ['project' => $project, 'import' => $import])
        ->assertSee('完了しました')
        ->assertSee('1');
});

test('an assigned_to email matching a user outside the project leaves the issue unassigned', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $outsider = User::factory()->create(['email' => 'outsider@example.com']);

    $csv = "subject,assigned_to\nRow one,outsider@example.com\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.assigned_to', 'assigned_to')
        ->call('startImport');

    $issue = Issue::where('subject', 'Row one')->firstOrFail();

    expect($issue->assigned_to_id)->toBeNull()
        ->and($issue->assigned_to_id)->not->toBe($outsider->id);
});

test('rows resolve category and fixed_version by name, leaving them unset when the name is unknown', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $category = IssueCategory::factory()->for($project)->create(['name' => 'Backend']);
    $version = Version::factory()->for($project)->create(['name' => '1.0']);
    $user = importMember($project);

    $csv = "subject,category,fixed_version\nMatched,Backend,1.0\nUnmatched,Nope,Nope\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.category', 'category')
        ->set('mapping.fixed_version', 'fixed_version')
        ->call('startImport');

    $matched = Issue::where('subject', 'Matched')->firstOrFail();
    $unmatched = Issue::where('subject', 'Unmatched')->firstOrFail();

    expect($matched->category_id)->toBe($category->id)
        ->and($matched->fixed_version_id)->toBe($version->id)
        ->and($unmatched->category_id)->toBeNull()
        ->and($unmatched->fixed_version_id)->toBeNull();
});

test('an unknown category/version is auto-created when the user opts in and holds the manage permission', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'import_issues', 'manage_categories', 'manage_versions']]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $csv = "subject,category,fixed_version\nNew stuff,Frontend,2.0\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.category', 'category')
        ->set('mapping.fixed_version', 'fixed_version')
        ->set('createCategories', true)
        ->set('createVersions', true)
        ->call('startImport');

    $issue = Issue::where('subject', 'New stuff')->firstOrFail();
    $category = IssueCategory::where('project_id', $project->id)->where('name', 'Frontend')->firstOrFail();
    $version = Version::where('project_id', $project->id)->where('name', '2.0')->firstOrFail();

    expect($issue->category_id)->toBe($category->id)
        ->and($issue->fixed_version_id)->toBe($version->id);
});

test('the auto-create checkbox has no effect for a user without manage_categories/manage_versions', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "subject,category,fixed_version\nNew stuff,Frontend,2.0\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.category', 'category')
        ->set('mapping.fixed_version', 'fixed_version')
        ->set('createCategories', true)
        ->set('createVersions', true)
        ->call('startImport');

    $issue = Issue::where('subject', 'New stuff')->firstOrFail();

    expect($issue->category_id)->toBeNull()
        ->and($issue->fixed_version_id)->toBeNull()
        ->and(IssueCategory::where('project_id', $project->id)->where('name', 'Frontend')->exists())->toBeFalse()
        ->and(Version::where('project_id', $project->id)->where('name', '2.0')->exists())->toBeFalse();
});

test('the auto-create checkboxes are hidden from a user without manage_categories/manage_versions', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $user = importMember($project);

    $csv = "subject,category,fixed_version\nRow,Frontend,2.0\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.category', 'category')
        ->set('mapping.fixed_version', 'fixed_version')
        ->assertDontSee('自動的に作成する');
});

test('a row referencing a parent issue by number sets parent_id, scoped to the same project', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $otherProject->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $parent = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id, 'author_id' => $user->id,
    ]);
    $outsideParent = Issue::factory()->for($otherProject)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id, 'author_id' => $user->id,
    ]);

    $csv = "subject,parent\nChild,#{$parent->id}\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.parent', 'parent')
        ->call('startImport');

    $child = Issue::where('subject', 'Child')->firstOrFail();

    expect($child->parent_id)->toBe($parent->id)
        ->and($child->parent_id)->not->toBe($outsideParent->id);
});

test('a row referencing a parent issue that does not exist in the project is recorded as a failure', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "subject,parent\nOrphan,#999999\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.parent', 'parent')
        ->call('startImport');

    $import = IssueImport::firstOrFail();

    expect($import->imported_count)->toBe(0)
        ->and($import->failed_count)->toBe(1)
        ->and(Issue::where('subject', 'Orphan')->exists())->toBeFalse();
});

test('a mapped is_private column is honored when the importing user can set issues private', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'import_issues', 'set_issues_private']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $csv = "subject,is_private\nSecret,1\nOpen,0\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.is_private', 'is_private')
        ->call('startImport');

    expect(Issue::where('subject', 'Secret')->firstOrFail()->is_private)->toBeTrue()
        ->and(Issue::where('subject', 'Open')->firstOrFail()->is_private)->toBeFalse();
});

test('a mapped is_private column is ignored when the importing user cannot set issues private', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "subject,is_private\nAttempted secret,1\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.is_private', 'is_private')
        ->call('startImport');

    expect(Issue::where('subject', 'Attempted secret')->firstOrFail()->is_private)->toBeFalse();
});

test('a quoted value may span several lines and a UTF-8 BOM before a quoted header is ignored (A1-28a)', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "\u{FEFF}\"subject\",\"description\"\n\"複数行の課題\",\"1行目\n2行目, カンマ入り\n\"\"引用\"\"\"\n2件目,説明\n";

    $component = Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv));

    expect($component->get('headers'))->toBe(['subject', 'description'])
        ->and($component->get('mapping')['subject'])->toBe('subject');

    $component->set('mapping.description', 'description')->call('startImport');

    $import = IssueImport::firstOrFail();

    expect($import->total_rows)->toBe(2)
        ->and($import->imported_count)->toBe(2)
        ->and(Issue::where('subject', '複数行の課題')->value('description'))->toBe("1行目\n2行目, カンマ入り\n\"引用\"")
        ->and(Issue::where('subject', '2件目')->exists())->toBeTrue();
});

/**
 * A project with one tracker, the defaults an import needs, and a member
 * allowed to import; `$csv` stored as an import mapped by `$mapping`, run
 * with nobody signed in, as a queued job runs.
 *
 * @param  array<string, string>  $mapping
 * @return array{project: Project, tracker: Tracker, user: User}
 */
function customFieldImportScenario(): array
{
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);

    return ['project' => $project, 'tracker' => $tracker, 'user' => importMember($project)];
}

/**
 * @param  array<string, string>  $mapping
 */
function runQueuedImport(Project $project, User $user, string $csv, array $mapping): IssueImport
{
    Storage::disk('local')->put('imports/cf.csv', $csv);

    $import = IssueImport::create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'original_filename' => 'cf.csv',
        'file_path' => 'imports/cf.csv',
        'column_mapping' => $mapping,
    ]);

    auth()->logout();
    ImportIssuesJob::dispatchSync($import);

    return $import->fresh();
}

test('custom field columns are read like keywords for the importing user with nobody signed in (A1-28b)', function () {
    ['project' => $project, 'tracker' => $tracker, 'user' => $user] = customFieldImportScenario();
    $text = CustomField::factory()->create(['name' => '顧客名']);
    $list = CustomField::factory()->list(['高', '中', '低'])->create(['name' => '重要度']);
    $multi = CustomField::factory()->list(['Web', 'iOS', 'Android'])->multiple()->create(['name' => '対象']);
    $flag = CustomField::factory()->create(['name' => '要確認', 'field_format' => CustomFieldFormat::Bool->value]);
    // Visible only to the importer's role: seen through the importing
    // user even though the job runs with nobody signed in.
    $roleOnly = CustomField::factory()->create(['name' => 'メンバー限定']);
    $roleOnly->roles()->attach(Member::query()->where('user_id', $user->id)->firstOrFail()->roles->first());
    collect([$text, $list, $multi, $flag, $roleOnly])->each(fn (CustomField $field) => $field->trackers()->attach($tracker));

    $import = runQueuedImport($project, $user,
        "subject,顧客名,重要度,対象,要確認,メンバー限定\n課題A,株式会社ABC,中,\"web, Android, 不明\",yes,限定値\n",
        ['subject' => 'subject', "cf_{$text->id}" => '顧客名', "cf_{$list->id}" => '重要度', "cf_{$multi->id}" => '対象', "cf_{$flag->id}" => '要確認', "cf_{$roleOnly->id}" => 'メンバー限定'],
    );

    $issue = Issue::where('subject', '課題A')->firstOrFail();

    expect($import->imported_count)->toBe(1)
        ->and($issue->customValue($text))->toBe('株式会社ABC')
        ->and($issue->customValue($list))->toBe('中')
        ->and(array_values($issue->customFieldFormValues(collect([$multi]))[$multi->id]))->toEqualCanonicalizing(['Web', 'Android'])
        ->and((string) $issue->customValue($flag))->toBe('1')
        ->and($issue->customValue($roleOnly))->toBe('限定値');
});

test('a custom field the importing user may not see or edit is not imported even when mapped (A1-28b)', function () {
    ['project' => $project, 'tracker' => $tracker, 'user' => $user] = customFieldImportScenario();
    $hidden = CustomField::factory()->create(['name' => '社内限定']);
    $hidden->trackers()->attach($tracker);
    $hidden->roles()->attach(Role::factory()->create());
    $readOnly = CustomField::factory()->create(['name' => '読み取り専用', 'editable' => false]);
    $readOnly->trackers()->attach($tracker);

    runQueuedImport($project, $user, "subject,a,b\n課題B,秘密,変更\n", ['subject' => 'subject', "cf_{$hidden->id}" => 'a', "cf_{$readOnly->id}" => 'b']);

    $issue = Issue::where('subject', '課題B')->firstOrFail();

    expect($issue->customValue($hidden))->toBeNull()
        ->and($issue->customValue($readOnly))->toBeNull();
});

test('a required custom field left empty fails the row unless it has a default, and an invalid value fails it (A1-28b)', function () {
    ['project' => $project, 'tracker' => $tracker, 'user' => $user] = customFieldImportScenario();
    $required = CustomField::factory()->required()->create(['name' => '必須項目']);
    $withDefault = CustomField::factory()->required()->create(['name' => '既定あり', 'default_value' => '既定値']);
    $number = CustomField::factory()->create(['name' => '見積点数', 'field_format' => CustomFieldFormat::Int->value]);
    collect([$required, $withDefault, $number])->each(fn (CustomField $field) => $field->trackers()->attach($tracker));

    $import = runQueuedImport($project, $user,
        "subject,必須項目,見積点数\n空の必須,,\n数値でない,あり,abc\n正しい行,あり,3\n",
        ['subject' => 'subject', "cf_{$required->id}" => '必須項目', "cf_{$number->id}" => '見積点数'],
    );

    expect($import->imported_count)->toBe(1)
        ->and($import->failed_count)->toBe(2)
        ->and(collect($import->errors)->pluck('row')->all())->toBe([2, 3])
        ->and($import->errors[0]['message'])->toContain('必須項目')
        ->and(Issue::where('subject', '正しい行')->firstOrFail()->customValue($withDefault))->toBe('既定値');
});

test('the mapping form offers the editable custom fields and matches them to headers by name (A1-28b)', function () {
    ['project' => $project, 'tracker' => $tracker, 'user' => $user] = customFieldImportScenario();
    $field = CustomField::factory()->create(['name' => '顧客名']);
    $field->trackers()->attach($tracker);
    $hidden = CustomField::factory()->create(['name' => '社内限定']);
    $hidden->trackers()->attach($tracker);
    $hidden->roles()->attach(Role::factory()->create());

    $component = Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', "subject,顧客名,社内限定\nx,y,z\n"))
        ->assertSee('顧客名')
        ->assertDontSee('<label class="text-sm text-neutral-700">社内限定</label>', escape: false);

    expect($component->get('mapping'))->toHaveKey("cf_{$field->id}", '顧客名')
        ->not->toHaveKey("cf_{$hidden->id}");
});
