<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EnumerationType;
use App\Enums\RoleBuiltin;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\WorkflowTransition;
use Illuminate\Database\Seeder;

/**
 * Redmine's "load the default configuration": the built-in roles, three
 * roles, trackers, issue statuses, priorities, time entry activities and a
 * simple workflow. Used by DatabaseSeeder and by the admin page that loads
 * it on an empty installation. Names come in English or Japanese; the
 * workflow is wired through the internal keys, so it works in either.
 */
class DefaultConfigurationSeeder extends Seeder
{
    /**
     * @var array<string, array<string, array<string, string>>>
     */
    public const array NAMES = [
        'en' => [
            'roles' => ['manager' => 'Manager', 'developer' => 'Developer', 'reporter' => 'Reporter', 'anonymous' => 'Anonymous', 'non_member' => 'Non member'],
            'trackers' => ['bug' => 'Bug', 'feature' => 'Feature', 'support' => 'Support'],
            'statuses' => ['new' => 'New', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'feedback' => 'Feedback', 'closed' => 'Closed', 'rejected' => 'Rejected'],
            'priorities' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent', 'immediate' => 'Immediate'],
            'activities' => ['design' => 'Design', 'development' => 'Development', 'testing' => 'Testing'],
        ],
        'ja' => [
            'roles' => ['manager' => 'マネージャー', 'developer' => '開発者', 'reporter' => '報告者', 'anonymous' => '匿名ユーザー', 'non_member' => '非メンバー'],
            'trackers' => ['bug' => 'バグ', 'feature' => '機能', 'support' => 'サポート'],
            'statuses' => ['new' => '新規', 'in_progress' => '進行中', 'resolved' => '解決', 'feedback' => 'フィードバック', 'closed' => '終了', 'rejected' => '却下'],
            'priorities' => ['low' => '低め', 'normal' => '通常', 'high' => '高め', 'urgent' => '急いで', 'immediate' => '今すぐ'],
            'activities' => ['design' => '設計作業', 'development' => '開発作業', 'testing' => 'テスト'],
        ],
    ];

    public function __construct(private readonly string $locale = 'en') {}

    /**
     * Whether any configuration data exists already. Loading the defaults on
     * top of an installation that has some would mix two sets of names, so
     * the admin page only offers the load when this is false.
     */
    public static function isConfigured(): bool
    {
        return Tracker::query()->exists()
            || IssueStatus::query()->exists()
            || Enumeration::query()->ofType(EnumerationType::IssuePriority)->exists()
            || Role::query()->whereNull('builtin')->exists();
    }

    public function run(): void
    {
        $this->seedBuiltinRoles();
        $this->seedDefaultRoles();
        $this->seedTrackers();
        $this->seedIssueStatuses();
        $this->seedEnumerations();
        $this->seedWorkflow();
    }

    /**
     * @return array<string, string>
     */
    private function names(string $group): array
    {
        return self::NAMES[$this->locale][$group] ?? self::NAMES['en'][$group];
    }

    private function seedBuiltinRoles(): void
    {
        $roles = $this->names('roles');
        $builtinRoles = [
            RoleBuiltin::Anonymous->value => ['name' => $roles['anonymous'], 'permissions' => ['view_project'], 'position' => 1],
            RoleBuiltin::NonMember->value => ['name' => $roles['non_member'], 'permissions' => ['view_project'], 'position' => 2],
        ];

        foreach ($builtinRoles as $builtin => $attributes) {
            Role::query()->firstOrCreate(['builtin' => $builtin], $attributes);
        }
    }

    private function seedDefaultRoles(): void
    {
        $names = $this->names('roles');
        $roles = [
            $names['manager'] => [
                'view_project', 'edit_project', 'close_project', 'delete_project', 'select_project_modules',
                'manage_members', 'add_subprojects', 'manage_versions', 'manage_categories',
                'view_issues', 'add_issues', 'edit_issues', 'delete_issues', 'manage_issue_relations', 'add_issue_watchers',
            ],
            $names['developer'] => [
                'view_project', 'manage_versions',
                'view_issues', 'add_issues', 'edit_issues', 'add_issue_watchers',
            ],
            $names['reporter'] => [
                'view_project', 'view_issues', 'add_issues', 'add_issue_watchers',
            ],
        ];

        $position = 3;
        foreach ($roles as $name => $permissions) {
            Role::query()->updateOrCreate(
                ['name' => $name],
                ['permissions' => $permissions, 'position' => $position++]
            );
        }
    }

    private function seedTrackers(): void
    {
        foreach ($this->names('trackers') as $name) {
            Tracker::query()->firstOrCreate(['name' => $name]);
        }
    }

    private function seedIssueStatuses(): void
    {
        $names = $this->names('statuses');
        $closed = ['closed', 'rejected'];

        foreach ($names as $key => $name) {
            IssueStatus::query()->firstOrCreate(['name' => $name], ['is_closed' => in_array($key, $closed, true)]);
        }
    }

    private function seedEnumerations(): void
    {
        foreach ($this->names('priorities') as $key => $name) {
            Enumeration::query()->firstOrCreate(
                ['type' => EnumerationType::IssuePriority->value, 'name' => $name],
                ['is_default' => $key === 'normal']
            );
        }

        foreach ($this->names('activities') as $key => $name) {
            Enumeration::query()->firstOrCreate(
                ['type' => EnumerationType::TimeEntryActivity->value, 'name' => $name],
                ['is_default' => $key === 'development']
            );
        }
    }

    /**
     * Every tracker shares the same simple New -> In Progress -> Resolved ->
     * Closed flow for Manager/Developer/Reporter, with Manager additionally
     * able to reject or reopen closed issues.
     */
    private function seedWorkflow(): void
    {
        $statusIds = IssueStatus::query()->pluck('id', 'name');
        $statusNames = $this->names('statuses');
        $roleNames = $this->names('roles');
        $roleIds = Role::query()->whereIn('name', [$roleNames['manager'], $roleNames['developer'], $roleNames['reporter']])->pluck('id', 'name');

        $status = fn (string $key): int => $statusIds[$statusNames[$key]];

        $commonTransitions = [
            ['new', 'in_progress'],
            ['in_progress', 'resolved'],
            ['resolved', 'in_progress'],
            ['resolved', 'closed'],
            ['feedback', 'in_progress'],
        ];

        $managerOnlyTransitions = [
            ['new', 'rejected'],
            ['in_progress', 'rejected'],
            ['closed', 'new'],
        ];

        // Flatten into a single (role, from, to) list so every tracker
        // creates its transitions through one loop instead of duplicating
        // the firstOrCreate call for the common and manager-only cases.
        $roleTransitions = [];
        foreach (['manager', 'developer', 'reporter'] as $roleKey) {
            foreach ($commonTransitions as [$from, $to]) {
                $roleTransitions[] = [$roleKey, $from, $to];
            }
        }
        foreach ($managerOnlyTransitions as [$from, $to]) {
            $roleTransitions[] = ['manager', $from, $to];
        }

        foreach (Tracker::query()->whereIn('name', $this->names('trackers'))->get() as $tracker) {
            foreach ($roleTransitions as [$roleKey, $from, $to]) {
                WorkflowTransition::query()->firstOrCreate([
                    'tracker_id' => $tracker->id,
                    'role_id' => $roleIds[$roleNames[$roleKey]],
                    'old_status_id' => $status($from),
                    'new_status_id' => $status($to),
                ]);
            }
        }
    }
}
