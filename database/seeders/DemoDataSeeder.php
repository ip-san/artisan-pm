<?php

namespace Database\Seeders;

use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use App\Enums\IssueRelationType;
use App\Enums\ProjectStatus;
use App\Enums\VersionStatus;
use App\Models\Board;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Message;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Models\Wiki;
use App\Models\WikiPage;
use App\Models\WikiPageVersion;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A "lived-in" data set: several projects with a hierarchy, members and groups, hundreds of issues
 * with history, relations and subtasks, half a year of time entries, wiki revisions, news and forums.
 * Deterministic (fixed seed). Size scales with DEMO_DATA_SCALE (default 1; tests use a fraction).
 *
 * Run on top of DatabaseSeeder: vendor/bin/sail artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private Generator $faker;

    private float $scale;

    private Carbon $now;

    /** @var Collection<int, User> */
    private Collection $users;

    public function run(): void
    {
        if (Project::query()->where('identifier', 'ec-renewal')->exists()) {
            $this->command?->info('Demo data already present; skipping.');

            return;
        }

        $this->faker = Factory::create('ja_JP');
        $this->faker->seed(20260927);
        mt_srand(20260927);
        $this->scale = max(0.05, (float) (getenv('DEMO_DATA_SCALE') ?: 1));
        $this->now = Carbon::now();

        $this->users = $this->seedUsers();
        $groups = $this->seedGroups();

        foreach ($this->projectPlan() as $plan) {
            $this->seedProject($plan, $groups);
        }
    }

    private function count(int $base): int
    {
        return max(1, (int) round($base * $this->scale));
    }

    /**
     * @return Collection<int, User>
     */
    private function seedUsers(): Collection
    {
        $users = collect();

        for ($i = 1; $i <= 25; $i++) {
            $lastname = $this->faker->lastName();
            $firstname = $this->faker->firstName();
            $users->push(User::factory()->create([
                'name' => "{$lastname} {$firstname}",
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => "demo{$i}@example.com",
                'login' => "demo{$i}",
                'language' => 'ja',
            ]));
        }

        // Someone with a very long name, to catch truncation in narrow columns.
        $users->push(User::factory()->create([
            'name' => '長谷川 アレクサンドラ・ミハイロヴナ・ヴァシリエヴァ',
            'firstname' => 'アレクサンドラ・ミハイロヴナ・ヴァシリエヴァ',
            'lastname' => '長谷川',
            'email' => 'demo-long-name@example.com',
            'login' => 'demo-long-name',
            'language' => 'ja',
        ]));

        return $users;
    }

    /**
     * @return Collection<int, Group>
     */
    private function seedGroups(): Collection
    {
        return collect(['開発チーム', 'デザインチーム', 'カスタマーサポート'])->map(function (string $name, int $index) {
            $group = Group::query()->create(['name' => $name]);
            $group->users()->attach($this->users->slice($index * 6, 6)->pluck('id'));

            return $group;
        });
    }

    /**
     * @return list<array{identifier: string, name: string, parent?: string, issues: int, status?: ProjectStatus, public?: bool}>
     */
    private function projectPlan(): array
    {
        return [
            ['identifier' => 'ec-renewal', 'name' => 'ECサイトリニューアル', 'issues' => 90],
            ['identifier' => 'ec-payment', 'name' => '決済システム', 'parent' => 'ec-renewal', 'issues' => 70],
            ['identifier' => 'ec-search', 'name' => '商品検索', 'parent' => 'ec-renewal', 'issues' => 45],
            ['identifier' => 'helpdesk', 'name' => '社内ヘルプデスク', 'issues' => 110, 'public' => false],
            ['identifier' => 'mobile-app', 'name' => 'モバイルアプリ開発', 'issues' => 60],
            ['identifier' => 'core-system-2026', 'name' => '2026年度 基幹システム刷新プロジェクト(フェーズ2:在庫・受発注連携)', 'issues' => 40],
            ['identifier' => 'legacy-portal', 'name' => '旧ポータル保守(終了)', 'issues' => 15, 'status' => ProjectStatus::Closed],
        ];
    }

    /**
     * @param  array{identifier: string, name: string, parent?: string, issues: int, status?: ProjectStatus, public?: bool}  $plan
     * @param  Collection<int, Group>  $groups
     */
    private function seedProject(array $plan, Collection $groups): void
    {
        $project = Project::factory()->create([
            'identifier' => $plan['identifier'],
            'name' => $plan['name'],
            'description' => $this->faker->realText(160),
            'is_public' => $plan['public'] ?? true,
            'parent_id' => isset($plan['parent']) ? Project::query()->where('identifier', $plan['parent'])->value('id') : null,
        ]);
        $project->trackers()->syncWithoutDetaching(Tracker::query()->pluck('id'));

        $roles = Role::query()->whereNull('builtin')->orderBy('position')->get();
        $members = $this->users->shuffle()->take(mt_rand(6, 12))->values();
        foreach ($members as $index => $user) {
            $member = $project->members()->create(['user_id' => $user->id]);
            $member->roles()->attach($roles[$index === 0 ? 0 : min($roles->count() - 1, 1 + $index % 2)]->id);
        }
        $groupMember = $project->members()->create(['group_id' => $groups->random()->id]);
        $groupMember->roles()->attach($roles[min(1, $roles->count() - 1)]->id);
        $members = $members->merge(User::query()->where('is_admin', true)->get())->values();

        $versions = $this->seedVersions($project);
        $categories = collect(['フロントエンド', 'バックエンド', 'インフラ', 'デザイン', 'ドキュメント', '運用'])
            ->shuffle()->take(mt_rand(3, 6))
            ->map(fn (string $name) => IssueCategory::query()->create(['project_id' => $project->id, 'name' => $name]));

        $issues = $this->seedIssues($project, $members, $versions, $categories, $this->count($plan['issues']));
        $this->seedTimeEntries($project, $members, $issues);
        $this->seedWiki($project, $members);
        $this->seedNews($project, $members);
        $this->seedForum($project, $members);

        Document::query()->create(['project_id' => $project->id, 'title' => '要件定義書 v'.mt_rand(1, 3), 'description' => $this->faker->realText(200)]);
        Document::query()->create(['project_id' => $project->id, 'title' => '運用手順書', 'description' => $this->faker->realText(200)]);

        if (isset($plan['status'])) {
            $project->forceFill(['status' => $plan['status']->value])->save();
        }
    }

    /**
     * @return Collection<int, Version>
     */
    private function seedVersions(Project $project): Collection
    {
        return collect([
            ['v1.0', VersionStatus::Closed, -120],
            ['v1.1', VersionStatus::Closed, -45],
            ['v2.0', VersionStatus::Open, 20],
            ['v2.1', VersionStatus::Open, 75],
        ])->map(fn (array $v) => Version::query()->create([
            'project_id' => $project->id,
            'name' => $v[0],
            'description' => $this->faker->realText(60),
            'status' => $v[1]->value,
            'due_date' => $this->now->copy()->addDays($v[2])->toDateString(),
        ]));
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  Collection<int, Version>  $versions
     * @param  Collection<int, IssueCategory>  $categories
     * @return Collection<int, Issue>
     */
    private function seedIssues(Project $project, Collection $members, Collection $versions, Collection $categories, int $count): Collection
    {
        $trackers = Tracker::query()->orderBy('position')->get();
        $open = IssueStatus::query()->where('is_closed', false)->orderBy('position')->get();
        $closed = IssueStatus::query()->where('is_closed', true)->orderBy('position')->get();
        $priorities = Enumeration::query()->ofType(EnumerationType::IssuePriority)->orderBy('position')->get();
        $fields = CustomField::query()->where('customized_type', CustomizableType::Issue->value)->get();
        $issues = collect();

        for ($i = 0; $i < $count; $i++) {
            $isClosed = mt_rand(1, 100) <= 35;
            $status = $isClosed ? $closed->random() : $open->random();
            $created = $this->now->copy()->subDays(mt_rand(1, 180))->subMinutes(mt_rand(0, 1440));
            $start = $created->copy()->addDays(mt_rand(0, 5));
            $version = mt_rand(1, 100) <= 75 ? ($isClosed ? $versions->take(2)->random() : $versions->slice(2)->random()) : null;
            $parent = $issues->isNotEmpty() && mt_rand(1, 100) <= 12 ? $issues->random() : null;

            $issue = Issue::query()->create([
                'project_id' => $project->id,
                'tracker_id' => $trackers->random()->id,
                'status_id' => $status->id,
                'priority_id' => $this->weightedPriority($priorities)->id,
                'author_id' => $members->random()->id,
                'assigned_to_id' => mt_rand(1, 100) <= 80 ? $members->random()->id : null,
                'fixed_version_id' => $version?->id,
                'category_id' => $categories->isNotEmpty() && mt_rand(1, 100) <= 70 ? $categories->random()->id : null,
                'parent_id' => $parent?->id,
                'subject' => $this->subject(),
                'description' => $this->description(),
                'start_date' => $start->toDateString(),
                'due_date' => mt_rand(1, 100) <= 70 ? $start->copy()->addDays(mt_rand(1, 30))->toDateString() : null,
                'done_ratio' => $isClosed ? 100 : [0, 0, 10, 30, 50, 70, 90][mt_rand(0, 6)],
                'estimated_hours' => mt_rand(1, 100) <= 60 ? mt_rand(1, 40) / 2 : null,
                'is_private' => mt_rand(1, 100) <= 3,
            ]);

            $fields->each(fn (CustomField $field) => mt_rand(1, 100) <= 60 && $field->possible_values
                ? $issue->setCustomFieldValues([$field->id => $field->possible_values[array_rand($field->possible_values)]])
                : null);

            foreach ($members->random(min($members->count(), mt_rand(0, 4))) as $watcher) {
                $issue->watchers()->firstOrCreate(['user_id' => $watcher->id]);
            }

            $updated = $this->seedJournals($issue, $members, $created, $open, $closed);
            $issue->forceFill([
                'created_at' => $created,
                'updated_at' => $updated,
                'closed_on' => $isClosed ? $updated : null,
            ])->saveQuietly();

            $issues->push($issue);
        }

        foreach ($issues->random(min($issues->count(), $this->count(25))) as $from) {
            $to = $issues->random();
            if ($to->id !== $from->id) {
                IssueRelation::query()->firstOrCreate(
                    ['issue_from_id' => $from->id, 'issue_to_id' => $to->id],
                    ['relation_type' => collect([IssueRelationType::Relates, IssueRelationType::Blocks, IssueRelationType::Precedes, IssueRelationType::Duplicates])->random()->value]
                );
            }
        }

        return $issues;
    }

    /**
     * @param  Collection<int, Enumeration>  $priorities
     */
    private function weightedPriority(Collection $priorities): Enumeration
    {
        $roll = mt_rand(1, 100);
        $index = match (true) {
            $roll <= 15 => 0,
            $roll <= 75 => min(1, $priorities->count() - 1),
            $roll <= 92 => min(2, $priorities->count() - 1),
            default => $priorities->count() - 1,
        };

        return $priorities[$index];
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  Collection<int, IssueStatus>  $open
     * @param  Collection<int, IssueStatus>  $closed
     */
    private function seedJournals(Issue $issue, Collection $members, Carbon $at, Collection $open, Collection $closed): Carbon
    {
        $at = $at->copy();

        for ($n = mt_rand(0, 7); $n > 0; $n--) {
            $at = $at->copy()->addHours(mt_rand(2, 96));
            if ($at->greaterThan($this->now)) {
                break;
            }

            $journal = Journal::query()->create([
                'issue_id' => $issue->id,
                'user_id' => $members->random()->id,
                'notes' => mt_rand(1, 100) <= 70 ? $this->comment() : null,
                'private_notes' => false,
            ]);

            if (mt_rand(1, 100) <= 50) {
                $journal->details()->create(['property' => 'attr', 'prop_key' => 'status_id', 'old_value' => (string) $open->random()->id, 'new_value' => (string) $issue->status_id]);
            }
            if (mt_rand(1, 100) <= 30) {
                $journal->details()->create(['property' => 'attr', 'prop_key' => 'done_ratio', 'old_value' => '0', 'new_value' => (string) $issue->done_ratio]);
            }
            if (mt_rand(1, 100) <= 25) {
                $journal->details()->create(['property' => 'attr', 'prop_key' => 'assigned_to_id', 'old_value' => (string) $members->random()->id, 'new_value' => (string) ($issue->assigned_to_id ?? '')]);
            }

            $journal->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        }

        return $at->min($this->now);
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  Collection<int, Issue>  $issues
     */
    private function seedTimeEntries(Project $project, Collection $members, Collection $issues): void
    {
        $activities = Enumeration::query()->ofType(EnumerationType::TimeEntryActivity)->get();

        for ($i = $this->count(220); $i > 0; $i--) {
            $issue = mt_rand(1, 100) <= 85 ? $issues->random() : null;
            TimeEntry::query()->create([
                'project_id' => $project->id,
                'issue_id' => $issue?->id,
                'user_id' => $user = $members->random()->id,
                'author_id' => $user,
                'activity_id' => $activities->random()->id,
                'hours' => mt_rand(1, 16) / 2,
                'spent_on' => $this->now->copy()->subDays(mt_rand(0, 180))->toDateString(),
                'comments' => mt_rand(1, 100) <= 60 ? collect(['実装', 'レビュー対応', '打ち合わせ', '調査', 'テスト', '資料作成'])->random() : null,
            ]);
        }
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function seedWiki(Project $project, Collection $members): void
    {
        Wiki::query()->firstOrCreate(['project_id' => $project->id], ['start_page' => 'Wiki']);

        foreach (['Wiki', '開発環境の構築手順', 'リリース手順', 'コーディング規約', '障害対応フロー', '用語集', '議事録_2026-09'] as $title) {
            $page = WikiPage::query()->create(['project_id' => $project->id, 'title' => $title]);
            for ($v = 1, $versions = mt_rand(1, 5); $v <= $versions; $v++) {
                WikiPageVersion::query()->create([
                    'wiki_page_id' => $page->id,
                    'author_id' => $members->random()->id,
                    'text' => $this->wikiText($title),
                    'comments' => $v === 1 ? '作成' : '追記',
                    'version' => (int) $page->versions()->max('version') + 1,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function seedNews(Project $project, Collection $members): void
    {
        foreach (['v2.0 リリースのお知らせ', '定期メンテナンスのお知らせ', '新メンバー参加のお知らせ'] as $title) {
            $news = News::query()->create([
                'project_id' => $project->id,
                'author_id' => $members->random()->id,
                'title' => $title,
                'summary' => $this->faker->realText(60),
                'description' => $this->faker->realText(400),
            ]);
            for ($c = mt_rand(0, 4); $c > 0; $c--) {
                NewsComment::query()->create(['news_id' => $news->id, 'author_id' => $members->random()->id, 'content' => $this->comment()]);
            }
        }
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function seedForum(Project $project, Collection $members): void
    {
        foreach (['雑談', '技術相談'] as $name) {
            $board = Board::query()->create(['project_id' => $project->id, 'name' => $name, 'description' => $this->faker->realText(40)]);
            for ($t = $this->count(8); $t > 0; $t--) {
                $topic = Message::query()->create([
                    'board_id' => $board->id,
                    'author_id' => $members->random()->id,
                    'subject' => $this->faker->realText(25),
                    'content' => $this->faker->realText(300),
                ]);
                for ($r = mt_rand(0, 6); $r > 0; $r--) {
                    Message::query()->create([
                        'board_id' => $board->id,
                        'parent_id' => $topic->id,
                        'author_id' => $members->random()->id,
                        'subject' => 'RE: '.$topic->subject,
                        'content' => $this->comment(),
                    ]);
                }
            }
        }
    }

    private function subject(): string
    {
        $targets = ['ログイン画面', '商品一覧', 'カート', '決済処理', '管理画面', 'メール通知', '検索機能', 'CSV出力', 'マイページ', 'API', 'バッチ処理', '在庫連携'];
        $problems = ['でエラーが発生する', 'の表示が崩れる', 'が遅い', 'の文言を修正', 'に項目を追加したい', 'のテストを追加', 'をリファクタリング', 'の仕様を確認したい', 'でタイムアウトする', 'のアクセシビリティ改善'];
        $subject = $targets[array_rand($targets)].$problems[array_rand($problems)];

        return mt_rand(1, 100) <= 5
            ? $subject.'(本番環境でのみ再現、Safari 17 以降かつ特定の決済手段を選んだ場合に限り、確認画面から戻ると入力内容が消える)'
            : $subject;
    }

    private function description(): string
    {
        return implode("\n\n", [
            '## 概要',
            $this->faker->realText(120),
            '## 再現手順',
            "1. 管理画面にログインする\n2. 対象の画面を開く\n3. 「保存」を押す",
            '## 期待する動作',
            $this->faker->realText(80),
        ]);
    }

    private function comment(): string
    {
        return collect([
            '確認しました。対応します。',
            '再現できました。原因を調査中です。',
            "修正版をステージングに反映しました。確認をお願いします。\n\n```\nphp artisan migrate --force\n```",
            'こちらの件、仕様について PM に確認中です。',
            $this->faker->realText(140),
        ])->random();
    }

    private function wikiText(string $title): string
    {
        return "# {$title}\n\n".$this->faker->realText(300)."\n\n| 項目 | 内容 |\n|---|---|\n| 担当 | 開発チーム |\n| 更新頻度 | 月1回 |\n\n".$this->faker->realText(200);
    }
}
