<?php

use App\Enums\EnumerationType;
use App\Enums\MailNotificationOption;
use App\Enums\ProjectModuleKey;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Enums\RepositoryType;
use App\Models\Enumeration;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Support\Attachments\AttachmentArchive;
use App\Support\Avatar\UserAvatar;
use App\Support\Format\Hours;
use App\Support\Mail\PublicUrl;
use App\Support\Pagination\PageSize;
use App\Support\Preferences\UserPreferences;
use App\Support\Query\ListDefaults;
use App\Support\Scm\CodesetConverter;
use App\Support\Scm\DisplayLimits;
use App\Support\TimeLog\TimeLogConstraints;
use App\Rules\RequiredPasswordCharacterClasses;
use App\Support\Issues\DoneRatioSteps;
use App\Support\Issues\RelatedIssueColumns;
use App\Models\Tracker;
use App\Models\IssueStatus;
use App\Support\Mail\NotificationRecipients;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /**
     * Native columns selectable as the issue list's default display
     * columns — matches issues/index.blade.php's own DISPLAY_COLUMNS
     * (kept as a separate copy here rather than shared, the same way
     * issues/global-index.blade.php already keeps its own independent
     * copy). Custom fields are deliberately excluded from this setting,
     * unlike Redmine's own issue_list_default_columns which allows them
     * — they're per-tracker and not a stable install-wide default.
     *
     * @var array<string, string>
     */
    public const array ISSUE_LIST_COLUMNS = [
        'tracker_id' => 'トラッカー',
        'status_id' => 'ステータス',
        'priority_id' => '優先度',
        'subject' => '題名',
        'category_id' => 'カテゴリ',
        'assigned_to_id' => '担当者',
        'author_id' => '作成者',
        'fixed_version_id' => '対象バージョン',
        'start_date' => '開始日',
        'due_date' => '期日',
        'created_at' => '作成日',
        'done_ratio' => '進捗率',
    ];

    /**
     * Matches App\Support\Mail\NotificationRecipients::defaultNotifiedEvents()
     * — only the two event keys actually wired to a mail-sending listener
     * today. Redmine also has issue_note_added/news_added/wiki_content_*,
     * but this app's IssueService only ever dispatches a single
     * "issue_updated" event for any update (comment-only included, so a
     * separate issue_note_added toggle would be a no-op), and News/Wiki
     * have no notification listener yet at all — offering their toggles
     * here would violate this checklist's own §0.5 principle #1 (no
     * settings row without the matching feature in the same commit).
     * Add a key here in the same commit that wires its listener.
     *
     * @var array<string, string>
     */
    public const array NOTIFIED_EVENTS = [
        'issue_added' => '課題が作成されたとき',
        'issue_updated' => '課題が更新されたとき',
        'wiki_content_added' => 'Wikiページが追加されたとき',
        'wiki_content_updated' => 'Wikiページが更新されたとき',
        'news_added' => 'お知らせが投稿されたとき',
        'news_comment_added' => 'お知らせにコメントが投稿されたとき',
    ];

    public string $app_title = '';

    public string $welcome_text = '';

    public int $default_issues_per_page = 25;

    // Redmine's own default is 10 — kept at 7 here to match this app's
    // pre-existing hardcoded activity-feed window (activity/index.blade.php,
    // activity/global-index.blade.php), so introducing this setting doesn't
    // silently change the default view for existing installs.
    public int $activity_days_default = 7;

    public int $feeds_limit = 15;

    public string $per_page_options = '25,50,100';

    public int $search_results_per_page = 10;

    public bool $cache_formatted_text = false;

    public string $new_item_menu_tab = '2';

    public ?int $default_issue_query = null;

    public bool $default_users_hide_mail = false;

    /** @var array<int, string> */
    public array $default_users_auto_watch_on = [];

    public string $timespan_format = 'decimal';

    /** @var array<int, string> */
    public array $issue_list_default_totals = [];

    /** @var array<int, string> */
    public array $time_entry_list_default_columns = [];

    public bool $time_entry_list_show_total = true;

    public bool $gravatar_enabled = false;

    public string $gravatar_default = 'identicon';

    public string $host_name = '';

    public string $protocol = 'http';

    public int $gantt_items_limit = 500;

    public int $gantt_months_limit = 24;

    public bool $reactions_enabled = true;

    public bool $incoming_mail_enabled = false;

    public ?int $incoming_mail_default_project_id = null;

    public ?int $incoming_mail_default_tracker_id = null;

    public ?int $incoming_mail_default_status_id = null;

    public string $mail_handler_body_delimiters = '';

    public string $mail_handler_excluded_filenames = '';

    public string $mail_handler_preferred_body_part = 'plain';

    public bool $autofetch_changesets = false;

    public bool $sys_api_enabled = false;

    public string $sys_api_key = '';

    public int $repository_log_display_limit = 100;

    public int $diff_max_lines_displayed = 1500;

    public int $file_max_size_displayed = 512;

    public int $thumbnails_size = 100;

    public string $repositories_encodings = '';

    public string $commit_logs_encoding = 'UTF-8';

    public bool $commit_logs_formatting = true;

    public string $commit_ref_keywords = '*';

    public bool $commit_cross_project_ref = true;

    public bool $commit_logtime_enabled = false;

    public ?int $commit_logtime_activity_id = null;

    /** @var array<string> */
    public array $enabled_scm_types = [];

    /** @var array<int, array{keywords: string, status_id: ?int}> */
    public array $commit_fixing_keyword_rules = [];

    /** @var array<int, string> */
    public array $timelog_required_fields = [];

    public bool $timelog_accept_0_hours = true;

    public float $timelog_max_hours_per_day = 999;

    public bool $timelog_accept_future_dates = true;

    public bool $timelog_accept_closed_issues = true;

    public int $attachment_max_size = 10240;

    public int $bulk_download_max_size = 102400;

    public string $attachment_extensions_allowed = '';

    public string $attachment_extensions_denied = '';

    public string $issue_done_ratio = 'issue_field';

    public int $issue_done_ratio_interval = 10;

    public bool $close_duplicate_issues = true;

    public bool $parent_issue_priority = true;

    public bool $parent_issue_dates = true;

    public bool $parent_issue_done_ratio = true;

    public bool $cross_project_issue_relations = false;

    public bool $default_issue_start_date_to_creation_date = true;

    public ?int $default_issue_due_date_offset = null;

    /** @var array<int, string> */
    public array $issue_list_default_columns = [];

    /** @var array<int, string> */
    public array $related_issues_default_columns = [];

    public bool $display_related_issues_table_headers = false;

    public int $start_of_week = 0;

    public string $self_registration = 'automatic';

    public bool $unsubscribe = true;

    public int $session_timeout = 0;

    public int $session_lifetime = 0;

    public string $email_domains_allowed = '';

    public string $email_domains_denied = '';

    public int $password_min_length = 8;

    /** @var array<int, string> */
    public array $password_required_char_classes = [];

    public bool $autologin = false;

    public bool $lost_password = true;

    public bool $rest_api_enabled = false;

    public bool $jsonp_enabled = false;

    public bool $login_required = true;

    public string $twofa = '0';

    public bool $default_projects_public = true;

    /** @var array<string> */
    public array $default_projects_modules = [];

    /** @var array<int> */
    public array $default_projects_tracker_ids = [];

    public bool $sequential_project_identifiers = false;

    public ?int $new_project_user_role_id = null;

    /** @var array<string> */
    public array $notified_events = [];

    public string $mail_from = '';

    public bool $plain_text_mail = false;

    public bool $default_users_no_self_notified = true;

    public string $default_notification_option = 'only_assigned';

    public string $emails_header = '';

    public bool $show_status_changes_in_mail_subject = true;

    public string $emails_footer = '';

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);

        $this->self_registration = Setting::get('self_registration', 'automatic');
        $this->unsubscribe = Setting::get('unsubscribe', true);
        $this->session_timeout = Setting::get('session_timeout', 0);
        $this->session_lifetime = Setting::get('session_lifetime', 0);
        $this->email_domains_allowed = Setting::get('email_domains_allowed', '');
        $this->email_domains_denied = Setting::get('email_domains_denied', '');
        $this->password_min_length = Setting::get('password_min_length', 8);
        $this->password_required_char_classes = RequiredPasswordCharacterClasses::required();
        $this->autologin = Setting::get('autologin', false);
        $this->lost_password = Setting::get('lost_password', true);
        $this->rest_api_enabled = Setting::get('rest_api_enabled', false);
        $this->jsonp_enabled = Setting::get('jsonp_enabled', false);
        $this->login_required = Setting::get('login_required', true);
        $this->twofa = Setting::get('twofa', '0');
        $this->app_title = Setting::get('app_title', config('app.name'));
        $this->welcome_text = Setting::get('welcome_text', '');
        $this->default_issues_per_page = Setting::get('default_issues_per_page', 25);
        $this->activity_days_default = Setting::get('activity_days_default', 7);
        $this->feeds_limit = Setting::get('feeds_limit', 15);
        $this->per_page_options = Setting::get('per_page_options', PageSize::DEFAULT_OPTIONS);
        $this->search_results_per_page = Setting::get('search_results_per_page', PageSize::DEFAULT_SEARCH_RESULTS);
        $this->cache_formatted_text = Setting::get('cache_formatted_text', false);
        $this->new_item_menu_tab = (string) Setting::get('new_item_menu_tab', '2');
        $this->default_issue_query = filled(Setting::get('default_issue_query')) ? (int) Setting::get('default_issue_query') : null;
        $this->default_users_hide_mail = (bool) Setting::get('default_users_hide_mail', false);
        $this->default_users_auto_watch_on = UserPreferences::defaults()['auto_watch_on'];
        $this->timespan_format = Hours::timespanFormat();
        $this->issue_list_default_totals = ListDefaults::issueTotals();
        $this->time_entry_list_default_columns = ListDefaults::timeEntryColumns();
        $this->time_entry_list_show_total = ListDefaults::timeEntriesShowHoursTotal();
        $this->gravatar_enabled = UserAvatar::gravatarEnabled();
        $this->gravatar_default = UserAvatar::defaultStyle();
        $this->host_name = (string) Setting::get('host_name', '');
        $this->protocol = (string) Setting::get('protocol', PublicUrl::DEFAULT_PROTOCOL);
        $this->gantt_items_limit = Setting::get('gantt_items_limit', 500);
        $this->gantt_months_limit = Setting::get('gantt_months_limit', 24);
        $this->reactions_enabled = Setting::get('reactions_enabled', true);
        $this->issue_done_ratio = Setting::get('issue_done_ratio', 'issue_field');
        $this->issue_done_ratio_interval = DoneRatioSteps::interval();
        $this->close_duplicate_issues = Setting::get('close_duplicate_issues', true);
        $this->parent_issue_priority = Setting::get('parent_issue_priority', true);
        $this->parent_issue_dates = Setting::get('parent_issue_dates', true);
        $this->parent_issue_done_ratio = Setting::get('parent_issue_done_ratio', true);
        $this->cross_project_issue_relations = Setting::get('cross_project_issue_relations', false);
        $this->default_issue_start_date_to_creation_date = Setting::get('default_issue_start_date_to_creation_date', true);
        $this->default_issue_due_date_offset = Setting::get('default_issue_due_date_offset');
        $this->related_issues_default_columns = array_keys(RelatedIssueColumns::selected());
        $this->display_related_issues_table_headers = RelatedIssueColumns::showHeaders();
        $this->issue_list_default_columns = Setting::get(
            'issue_list_default_columns',
            ['tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id']
        );
        // 0 = Sunday (Carbon::SUNDAY), matching the calendar views' own default.
        $this->start_of_week = Setting::get('start_of_week', 0);
        $this->incoming_mail_enabled = Setting::get('incoming_mail_enabled', false);
        $this->incoming_mail_default_project_id = Setting::get('incoming_mail_default_project_id');
        $this->incoming_mail_default_tracker_id = Setting::get('incoming_mail_default_tracker_id');
        $this->incoming_mail_default_status_id = Setting::get('incoming_mail_default_status_id');
        $this->mail_handler_body_delimiters = Setting::get('mail_handler_body_delimiters', '');
        $this->mail_handler_excluded_filenames = Setting::get('mail_handler_excluded_filenames', '');
        $this->mail_handler_preferred_body_part = Setting::get('mail_handler_preferred_body_part', 'plain');
        $this->autofetch_changesets = Setting::get('autofetch_changesets', false);
        $this->repository_log_display_limit = Setting::get('repository_log_display_limit', PageSize::DEFAULT_REPOSITORY_LOG_LIMIT);
        $this->sys_api_enabled = Setting::get('sys_api_enabled', false);
        $this->sys_api_key = Setting::get('sys_api_key', '');
        $this->diff_max_lines_displayed = DisplayLimits::maxDiffLines();
        $this->file_max_size_displayed = DisplayLimits::maxFileSizeKb();
        $this->thumbnails_size = Setting::get('thumbnails_size', 100);
        $this->repositories_encodings = Setting::get('repositories_encodings', '');
        $this->commit_logs_encoding = Setting::get('commit_logs_encoding', 'UTF-8');
        $this->commit_logs_formatting = Setting::get('commit_logs_formatting', true);
        $this->commit_ref_keywords = Setting::get('commit_ref_keywords', '*');
        $this->commit_cross_project_ref = Setting::get('commit_cross_project_ref', true);
        $this->commit_logtime_enabled = Setting::get('commit_logtime_enabled', false);
        $this->commit_logtime_activity_id = Setting::get('commit_logtime_activity_id');
        $this->enabled_scm_types = Setting::get('enabled_scm_types', array_map(fn (RepositoryType $type) => $type->value, RepositoryType::cases()));
        // Unconfigured default: a single rule covering the classic keyword
        // list, targeting the first closed status — matches
        // RepositorySyncService's own fallback when no rules are stored,
        // so the UI's initial suggestion mirrors the actual runtime
        // default. Only offered when a closed status actually exists;
        // otherwise there's nothing sensible to pre-select and the row
        // would just fail validation the moment the form is saved as-is.
        $defaultFixingStatusId = IssueStatus::query()->where('is_closed', true)->orderBy('position')->value('id');
        $this->commit_fixing_keyword_rules = Setting::get('commit_fixing_keyword_rules', $defaultFixingStatusId !== null
            ? [['keywords' => 'fixes, fix, closes, close', 'status_id' => $defaultFixingStatusId]]
            : []);
        $this->timelog_required_fields = TimeLogConstraints::requiredFields();
        $this->timelog_accept_0_hours = TimeLogConstraints::acceptsZeroHours();
        $this->timelog_max_hours_per_day = TimeLogConstraints::maxHoursPerDay();
        $this->timelog_accept_future_dates = TimeLogConstraints::acceptsFutureDates();
        $this->timelog_accept_closed_issues = TimeLogConstraints::acceptsClosedIssues();
        $this->bulk_download_max_size = AttachmentArchive::maxSizeKb();
        $this->attachment_max_size = Setting::get('attachment_max_size', intdiv((int) config('media-library.max_file_size'), 1024));
        $this->attachment_extensions_allowed = Setting::get('attachment_extensions_allowed', '');
        $this->attachment_extensions_denied = Setting::get('attachment_extensions_denied', '');
        $this->default_projects_public = Setting::get('default_projects_public', true);
        $this->default_projects_modules = Setting::get(
            'default_projects_modules',
            array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::defaults())
        );
        $this->default_projects_tracker_ids = Setting::get('default_projects_tracker_ids', []);
        $this->sequential_project_identifiers = Setting::get('sequential_project_identifiers', false);
        $this->new_project_user_role_id = Setting::get('new_project_user_role_id');
        $this->notified_events = Setting::get('notified_events', NotificationRecipients::defaultNotifiedEvents());
        $this->mail_from = Setting::get('mail_from', '');
        $this->plain_text_mail = Setting::get('plain_text_mail', false);
        $this->default_users_no_self_notified = Setting::get('default_users_no_self_notified', true);
        $this->default_notification_option = Setting::get('default_notification_option', 'only_assigned');
        $this->emails_header = Setting::get('emails_header', '');
        $this->show_status_changes_in_mail_subject = Setting::get('show_status_changes_in_mail_subject', true);
        $this->emails_footer = Setting::get('emails_footer', '');
    }

    #[Computed]
    public function projects(): Collection
    {
        return Project::query()->orderBy('name')->get();
    }

    #[Computed]
    public function trackers(): Collection
    {
        return Tracker::query()->orderBy('position')->get();
    }

    #[Computed]
    public function statuses(): Collection
    {
        return IssueStatus::query()->orderBy('position')->get();
    }

    #[Computed]
    public function activities(): Collection
    {
        return Enumeration::query()->ofType(EnumerationType::TimeEntryActivity)->orderBy('position')->get();
    }

    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->givable()->get();
    }

    /**
     * A fresh random key for the repository management web service.
     */
    public function generateSysApiKey(): void
    {
        $this->sys_api_key = Str::random(40);
    }

    public function addFixingKeywordRule(): void
    {
        $this->commit_fixing_keyword_rules[] = ['keywords' => '', 'status_id' => null, 'done_ratio' => null, 'if_tracker_id' => null];
    }

    public function removeFixingKeywordRule(int $index): void
    {
        unset($this->commit_fixing_keyword_rules[$index]);
        $this->commit_fixing_keyword_rules = array_values($this->commit_fixing_keyword_rules);
    }

    public function save(): void
    {
        // A commit rule with keywords has to change something: a status or a
        // done ratio.
        $ruleChangeRules = [];

        foreach ($this->commit_fixing_keyword_rules as $index => $rule) {
            if (trim((string) ($rule['keywords'] ?? '')) !== '' && blank($rule['done_ratio'] ?? null)) {
                $ruleChangeRules["commit_fixing_keyword_rules.{$index}.status_id"] = ['required', 'exists:issue_statuses,id'];
            }
        }

        $isKnownEncoding = CodesetConverter::isKnownEncoding(...);
        $encodingName = fn (string $attribute, mixed $value, \Closure $fail) => $isKnownEncoding((string) $value) ? null : $fail("「{$value}」は未対応のエンコーディングです。");
        $encodingList = function (string $attribute, mixed $value, \Closure $fail) use ($isKnownEncoding): void {
            foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $name) {
                if (! $isKnownEncoding($name)) {
                    $fail("「{$name}」は未対応のエンコーディングです。");
                }
            }
        };

        $data = $this->validate([
            ...$ruleChangeRules,
            'app_title' => ['required', 'string', 'max:255'],
            'welcome_text' => ['nullable', 'string', 'max:5000'],
            'default_issues_per_page' => ['required', 'integer', 'min:5', 'max:200'],
            'activity_days_default' => ['required', 'integer', 'min:1', 'max:365'],
            'feeds_limit' => ['required', 'integer', 'min:1', 'max:500'],
            'per_page_options' => ['required', 'string', 'max:100', 'regex:/^\s*[1-9]\d{0,3}([\s,]+[1-9]\d{0,3})*\s*$/'],
            'search_results_per_page' => ['required', 'integer', 'min:1', 'max:200'],
            'cache_formatted_text' => ['boolean'],
            'new_item_menu_tab' => ['required', Rule::in(['0', '1', '2'])],
            'default_issue_query' => ['nullable', Rule::exists('queries', 'id')->where('type', QueryType::Issue->value)->where('visibility', QueryVisibility::Public->value)->whereNull('project_id')],
            'default_users_hide_mail' => ['boolean'],
            'default_users_auto_watch_on' => ['array'],
            'default_users_auto_watch_on.*' => [Rule::in(array_keys(UserPreferences::AUTO_WATCH_ON))],
            'timespan_format' => ['required', Rule::in(array_keys(Hours::FORMATS))],
            'issue_list_default_totals' => ['array'],
            'issue_list_default_totals.*' => [Rule::in(array_keys(ListDefaults::ISSUE_TOTALS))],
            'time_entry_list_default_columns' => ['array', 'min:1'],
            'time_entry_list_default_columns.*' => [Rule::in(array_keys(ListDefaults::TIME_ENTRY_COLUMNS))],
            'time_entry_list_show_total' => ['boolean'],
            'gravatar_enabled' => ['boolean'],
            'gravatar_default' => ['nullable', Rule::in(array_keys(UserAvatar::DEFAULT_STYLES))],
            'host_name' => ['nullable', 'string', 'max:255', 'regex:'.PublicUrl::HOST_PATTERN],
            'protocol' => ['required', Rule::in(['http', 'https'])],
            'gantt_items_limit' => ['required', 'integer', 'min:0', 'max:100000'],
            'gantt_months_limit' => ['required', 'integer', 'min:0', 'max:1200'],
            'reactions_enabled' => ['boolean'],
            'incoming_mail_enabled' => ['boolean'],
            'incoming_mail_default_project_id' => ['nullable', 'exists:projects,id'],
            'incoming_mail_default_tracker_id' => ['nullable', 'exists:trackers,id'],
            'incoming_mail_default_status_id' => ['nullable', 'exists:issue_statuses,id'],
            'mail_handler_body_delimiters' => ['nullable', 'string', 'max:1000'],
            'mail_handler_excluded_filenames' => ['nullable', 'string', 'max:1000'],
            'mail_handler_preferred_body_part' => ['required', 'in:plain,html'],
            'autofetch_changesets' => ['boolean'],
            'commit_logtime_enabled' => ['boolean'],
            'commit_logtime_activity_id' => ['nullable', 'exists:enumerations,id'],
            'enabled_scm_types' => ['array', 'min:1'],
            'enabled_scm_types.*' => [Rule::in(array_map(fn (RepositoryType $type) => $type->value, RepositoryType::cases()))],
            'commit_fixing_keyword_rules' => ['array'],
            'commit_fixing_keyword_rules.*.keywords' => ['nullable', 'string', 'max:255'],
            'commit_fixing_keyword_rules.*.status_id' => ['nullable', 'exists:issue_statuses,id'],
            'commit_fixing_keyword_rules.*.done_ratio' => ['nullable', 'integer', 'min:0', 'max:100'],
            'commit_fixing_keyword_rules.*.if_tracker_id' => ['nullable', 'exists:trackers,id'],
            'commit_ref_keywords' => ['nullable', 'string', 'max:255'],
            'repository_log_display_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'sys_api_enabled' => ['boolean'],
            'sys_api_key' => ['nullable', 'string', 'max:255'],
            'diff_max_lines_displayed' => ['required', 'integer', 'min:0', 'max:100000'],
            'file_max_size_displayed' => ['required', 'integer', 'min:0', 'max:102400'],
            'thumbnails_size' => ['required', 'integer', 'min:16', 'max:2000'],
            'repositories_encodings' => ['nullable', 'string', 'max:255', $encodingList],
            'commit_logs_encoding' => ['required', 'string', 'max:50', $encodingName],
            'commit_logs_formatting' => ['boolean'],
            'commit_cross_project_ref' => ['boolean'],
            'timelog_required_fields' => ['array'],
            'timelog_required_fields.*' => [Rule::in(array_keys(TimeLogConstraints::REQUIRABLE_FIELDS))],
            'timelog_accept_0_hours' => ['boolean'],
            'timelog_max_hours_per_day' => ['required', 'numeric', 'min:0', 'max:1000'],
            'timelog_accept_future_dates' => ['boolean'],
            'timelog_accept_closed_issues' => ['boolean'],
            'bulk_download_max_size' => ['required', 'integer', 'min:0', 'max:10485760'],
            'attachment_max_size' => ['required', 'integer', 'min:1', 'max:'.intdiv((int) config('media-library.max_file_size'), 1024)],
            'attachment_extensions_allowed' => ['nullable', 'string', 'max:1000'],
            'attachment_extensions_denied' => ['nullable', 'string', 'max:1000'],
            'issue_done_ratio' => ['required', 'in:issue_field,issue_status'],
            'issue_done_ratio_interval' => ['required', 'integer', Rule::in(DoneRatioSteps::INTERVALS)],
            'close_duplicate_issues' => ['boolean'],
            'parent_issue_priority' => ['boolean'],
            'parent_issue_dates' => ['boolean'],
            'parent_issue_done_ratio' => ['boolean'],
            'cross_project_issue_relations' => ['boolean'],
            'default_issue_start_date_to_creation_date' => ['boolean'],
            'default_issue_due_date_offset' => ['nullable', 'integer', 'min:0'],
            'related_issues_default_columns' => ['array'],
            'related_issues_default_columns.*' => [Rule::in(array_keys(RelatedIssueColumns::AVAILABLE))],
            'display_related_issues_table_headers' => ['boolean'],
            'issue_list_default_columns' => ['array', 'min:1'],
            'issue_list_default_columns.*' => [Rule::in(array_keys(self::ISSUE_LIST_COLUMNS))],
            'start_of_week' => ['required', Rule::in([0, 1, 6])],
            'self_registration' => ['required', 'in:disabled,manual,email,automatic'],
            'unsubscribe' => ['boolean'],
            'session_timeout' => ['required', Rule::in([0, 60, 120, 240, 480, 720, 1440, 2880])],
            'session_lifetime' => ['required', Rule::in([0, 240, 480, 720, 1440, 10080, 43200, 86400, 525600])],
            'email_domains_allowed' => ['nullable', 'string', 'max:1000'],
            'email_domains_denied' => ['nullable', 'string', 'max:1000'],
            'password_min_length' => ['required', 'integer', 'min:1', 'max:255'],
            'password_required_char_classes' => ['array'],
            'password_required_char_classes.*' => [Rule::in(array_keys(RequiredPasswordCharacterClasses::CLASSES))],
            'autologin' => ['boolean'],
            'lost_password' => ['boolean'],
            'rest_api_enabled' => ['boolean'],
            'jsonp_enabled' => ['boolean'],
            'login_required' => ['boolean'],
            'twofa' => ['required', 'in:0,1,2,3'],
            'default_projects_public' => ['boolean'],
            'default_projects_modules' => ['array'],
            'default_projects_modules.*' => [Rule::in(array_map(fn (ProjectModuleKey $m) => $m->value, ProjectModuleKey::cases()))],
            'default_projects_tracker_ids' => ['array'],
            'default_projects_tracker_ids.*' => ['exists:trackers,id'],
            'sequential_project_identifiers' => ['boolean'],
            'new_project_user_role_id' => ['nullable', 'exists:roles,id'],
            'notified_events' => ['array'],
            'notified_events.*' => [Rule::in(array_keys(self::NOTIFIED_EVENTS))],
            'mail_from' => ['nullable', 'email', 'max:255'],
            'plain_text_mail' => ['boolean'],
            'default_users_no_self_notified' => ['boolean'],
            'default_notification_option' => ['required', Rule::in(array_map(fn (MailNotificationOption $o) => $o->value, MailNotificationOption::cases()))],
            'emails_header' => ['nullable', 'string', 'max:2000'],
            'show_status_changes_in_mail_subject' => ['boolean'],
            'emails_footer' => ['nullable', 'string', 'max:2000'],
        ]);

        // Blank rows (no keywords typed) are dropped rather than saved and
        // silently ignored forever — matches Redmine's own
        // commit_update_keywords_array, which strips rules with no
        // keywords before storing.
        $data['commit_fixing_keyword_rules'] = collect($data['commit_fixing_keyword_rules'])
            ->filter(fn (array $rule) => trim((string) ($rule['keywords'] ?? '')) !== '')
            ->map(fn (array $rule) => [
                'keywords' => trim($rule['keywords']),
                'status_id' => filled($rule['status_id'] ?? null) ? (int) $rule['status_id'] : null,
                'done_ratio' => filled($rule['done_ratio'] ?? null) ? (int) $rule['done_ratio'] : null,
                'if_tracker_id' => filled($rule['if_tracker_id'] ?? null) ? (int) $rule['if_tracker_id'] : null,
            ])
            ->values()
            ->all();

        $data['commit_ref_keywords'] = trim((string) ($data['commit_ref_keywords'] ?? ''));
        $data['host_name'] = trim((string) ($data['host_name'] ?? ''), " \t\n\r\0\x0B/");
        $data['gravatar_default'] = (string) ($data['gravatar_default'] ?? '');
        $data['sys_api_key'] = trim((string) ($data['sys_api_key'] ?? ''));
        $data['repositories_encodings'] = trim((string) ($data['repositories_encodings'] ?? ''));

        // Stored the way Redmine's time_entry_list_defaults is.
        Setting::set('time_entry_list_defaults', [
            'column_names' => array_values($data['time_entry_list_default_columns']),
            'totalable_names' => $data['time_entry_list_show_total'] ? ['hours'] : [],
        ]);
        unset($data['time_entry_list_default_columns'], $data['time_entry_list_show_total']);

        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        $this->commit_fixing_keyword_rules = $data['commit_fixing_keyword_rules'];

        session()->flash('status', '設定を保存しました。');
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">設定</h1>

    <form wire:submit="save" class="space-y-8">
        <section class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">アプリケーション名</label>
                <input type="text" wire:model="app_title" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('app_title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">ウェルカムメッセージ</label>
                <textarea wire:model="welcome_text" rows="5"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
                <p class="mt-1 text-xs text-gray-500">プロジェクト一覧(ホーム)画面の先頭に表示されます。Markdown記法が使えます。空欄の場合は何も表示されません。</p>
                @error('welcome_text') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">課題一覧の1ページあたりの件数</label>
                <input type="number" wire:model="default_issues_per_page" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('default_issues_per_page') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">ホスト名(メール内リンク用)</label>
                    <input type="text" wire:model="host_name" placeholder="例: pm.example.com または pm.example.com/redmine"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <p class="mt-1 text-xs text-gray-500">空欄のときは APP_URL を使います。メールやキューで生成するリンクに使われます。</p>
                    @error('host_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">プロトコル</label>
                    <select wire:model="protocol" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                        <option value="http">HTTP</option>
                        <option value="https">HTTPS</option>
                    </select>
                    @error('protocol') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">活動画面の既定の表示期間(日数)</label>
                <input type="number" min="1" max="365" wire:model="activity_days_default" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('activity_days_default') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">一覧の表示件数の選択肢</label>
                <input type="text" wire:model="per_page_options" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-gray-500">カンマまたは空白区切り(例: 25,50,100)。課題・プロジェクト・お知らせ一覧の「表示件数」に出る選択肢です。</p>
                @error('per_page_options') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">検索結果の1ページあたりの件数</label>
                <input type="number" min="1" max="200" wire:model="search_results_per_page" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('search_results_per_page') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Atomフィードの最大エントリ数</label>
                <input type="number" min="1" max="500" wire:model="feeds_limit" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-gray-500">活動・課題・お知らせ・フォーラムの各Atomフィードに共通で適用されます。</p>
                @error('feeds_limit') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="cache_formatted_text" class="rounded border-gray-300">
                    2KBを超えるMarkdown本文の描画結果をキャッシュする
                </label>
                <p class="mt-1 text-xs text-gray-500">大きなWikiページの表示を速くします。他ページの取り込み(@{{include}})や子ページ一覧(@{{child_pages}})を含む本文は対象外で、キャッシュは1時間で失効します。#123やページリンクの参照先が作成・削除された直後は、最大1時間古い表示が残ることがあります。</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">課題の進捗率</label>
                <select wire:model="issue_done_ratio" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="issue_field">課題ごとに手動入力</option>
                    <option value="issue_status">ステータスから算出</option>
                </select>
                @error('issue_done_ratio') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">進捗率の選択肢の刻み</label>
                <select wire:model="issue_done_ratio_interval" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @foreach (DoneRatioSteps::INTERVALS as $interval)
                        <option value="{{ $interval }}">{{ $interval }} %</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">課題フォーム・一括編集・ステータスの既定進捗率・進捗率型カスタムフィールドの選択肢の刻み幅です(保存済みの値の妥当性には影響しません)。</p>
                @error('issue_done_ratio_interval') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="close_duplicate_issues" class="rounded border-gray-300">
                重複課題を自動的にクローズする(この課題を複製とする課題がクローズされたとき)
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="parent_issue_priority" class="rounded border-gray-300">
                親課題の優先度を子課題から算出する(未クローズの子課題のうち最高優先度)
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="parent_issue_dates" class="rounded border-gray-300">
                親課題の開始日/期日を子課題から算出する(最も早い開始日〜最も遅い期日)
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="parent_issue_done_ratio" class="rounded border-gray-300">
                親課題の進捗率を子課題から算出する(予定工数で重み付けした平均)
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="cross_project_issue_relations" class="rounded border-gray-300">
                プロジェクトをまたいだ課題関連を許可する
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="reactions_enabled" class="rounded border-gray-300">
                リアクション(いいね)機能を有効にする
            </label>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="default_issue_start_date_to_creation_date" class="rounded border-gray-300">
                    新規課題の開始日を作成日にする
                </label>
                <p class="mt-1 text-xs text-gray-500">無効の場合、開始日は自動設定されません(コピー元の課題がある場合はその開始日を引き継ぎます)。</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">新規課題の期日の既定値(作成日からの日数)</label>
                <input type="number" min="0" wire:model="default_issue_due_date_offset"
                    placeholder="未設定(既定値なし)"
                    class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-gray-500">空欄の場合、期日は自動設定されません。</p>
                @error('default_issue_due_date_offset') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700 mb-2">課題一覧の既定表示列</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (self::ISSUE_LIST_COLUMNS as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="issue_list_default_columns" value="{{ $key }}" class="rounded border-gray-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('issue_list_default_columns') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                @error('issue_list_default_columns.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700 mb-2">関連課題・サブタスクの表示列</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (RelatedIssueColumns::AVAILABLE as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="related_issues_default_columns" value="{{ $key }}" class="rounded border-gray-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-gray-500">課題の詳細画面で、サブタスクと関連課題の表に題名と一緒に表示する列です。</p>
                @error('related_issues_default_columns.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <label class="mt-2 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="display_related_issues_table_headers" class="rounded border-gray-300">
                    表に見出し行を表示する
                </label>
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">表示</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700">週の始まり</label>
                <select wire:model="start_of_week" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="0">日曜日</option>
                    <option value="1">月曜日</option>
                    <option value="6">土曜日</option>
                </select>
                @error('start_of_week') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">カレンダー画面(プロジェクト内/全プロジェクト共通)の週始まりに反映されます。</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">新規作成メニュー(プロジェクト内)</label>
                <select wire:model="new_item_menu_tab" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="0">なし</option>
                    <option value="1">「新しい課題」リンクのみ</option>
                    <option value="2">「+」ドロップダウン(課題・バージョン・お知らせなど)</option>
                </select>
                @error('new_item_menu_tab') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">課題一覧の既定クエリ(全体)</label>
                <select wire:model="default_issue_query" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">指定しない</option>
                    @foreach (\App\Models\Query::query()->where('type', \App\Enums\QueryType::Issue->value)->where('visibility', \App\Enums\QueryVisibility::Public->value)->whereNull('project_id')->orderBy('name')->get() as $query)
                        <option value="{{ $query->id }}">{{ $query->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">個人設定・プロジェクトの既定がないときに使われます。公開クエリのみ選べます。</p>
                @error('default_issue_query') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700">新規ユーザーの既定の個人設定</span>
                <label class="mt-1 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="default_users_hide_mail" class="rounded border-gray-300">
                    メールアドレスを他のユーザーに表示しない
                </label>
                <div class="mt-1 flex flex-wrap gap-4 text-sm text-gray-700">
                    @foreach (\App\Support\Preferences\UserPreferences::AUTO_WATCH_ON as $value => $label)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" value="{{ $value }}" wire:model="default_users_auto_watch_on" class="rounded border-gray-300">
                            {{ $label }}をウォッチ
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-gray-500">個人設定を変更していないユーザーに適用されます。</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">時間の表示形式</label>
                <select wire:model="timespan_format" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Format\Hours::FORMATS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('timespan_format') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700">課題一覧で合計する項目</span>
                <div class="mt-1 flex flex-wrap gap-4 text-sm text-gray-700">
                    @foreach (\App\Support\Query\ListDefaults::ISSUE_TOTALS as $key => $label)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" value="{{ $key }}" wire:model="issue_list_default_totals" class="rounded border-gray-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('issue_list_default_totals.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700">工数一覧の初期表示列</span>
                <div class="mt-1 flex flex-wrap gap-4 text-sm text-gray-700">
                    @foreach (\App\Support\Query\ListDefaults::TIME_ENTRY_COLUMNS as $key => $label)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" value="{{ $key }}" wire:model="time_entry_list_default_columns" class="rounded border-gray-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <label class="mt-2 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="time_entry_list_show_total" class="rounded border-gray-300">
                    工数一覧に時間の合計を表示する
                </label>
                @error('time_entry_list_default_columns') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="gravatar_enabled" class="rounded border-gray-300">
                    Gravatarを使う
                </label>
                <p class="mt-1 text-xs text-gray-500">有効にすると、ユーザーのメールアドレスのハッシュが gravatar.com に送られ、閲覧者のブラウザが画像を直接取得します。無効のときはイニシャルのアイコンを表示します。</p>
                <select wire:model="gravatar_default" class="mt-2 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Avatar\UserAvatar::DEFAULT_STYLES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('gravatar_default') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">ガントチャートの最大表示課題数(0で無制限)</label>
                    <input type="number" min="0" max="100000" wire:model="gantt_items_limit" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @error('gantt_items_limit') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">ガントチャートの最大表示月数(0で無制限)</label>
                    <input type="number" min="0" max="1200" wire:model="gantt_months_limit" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @error('gantt_months_limit') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">プロジェクト</h2>
            <p class="text-xs text-gray-500">新規プロジェクト作成フォームの初期値です。作成時にプロジェクトごと変更できます。</p>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="default_projects_public" class="rounded border-gray-300">
                既定で公開プロジェクトにする
            </label>

            <div>
                <span class="block text-sm font-medium text-gray-700 mb-2">既定で有効なモジュール</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (\App\Enums\ProjectModuleKey::cases() as $module)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="default_projects_modules" value="{{ $module->value }}" class="rounded border-gray-300">
                            {{ $module->value }}
                        </label>
                    @endforeach
                </div>
                @error('default_projects_modules.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700 mb-2">既定で使用するトラッカー(未選択の場合は全トラッカー)</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($this->trackers as $tracker)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="default_projects_tracker_ids" value="{{ $tracker->id }}" class="rounded border-gray-300">
                            {{ $tracker->name }}
                        </label>
                    @endforeach
                </div>
                @error('default_projects_tracker_ids.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="sequential_project_identifiers" class="rounded border-gray-300">
                識別子を自動的に連番採番する(識別子を空欄のまま保存した場合のみ)
            </label>

            <div>
                <label class="block text-sm font-medium text-gray-700">新規プロジェクトの既定ロール</label>
                <p class="text-xs text-gray-500">管理者以外がプロジェクト(サブプロジェクト)を作成した際に、作成者へ自動的に付与されるロールです。</p>
                <select wire:model="new_project_user_role_id" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">---</option>
                    @foreach ($this->roles as $role)
                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                    @endforeach
                </select>
                @error('new_project_user_role_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">認証</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700">アカウント登録</label>
                <select wire:model="self_registration" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="disabled">無効(登録ページを表示しない)</option>
                    <option value="manual">管理者の承認が必要</option>
                    <option value="email">メールでの確認が必要</option>
                    <option value="automatic">自動的に有効化</option>
                </select>
                @error('self_registration') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="unsubscribe" class="rounded border-gray-300">
                    ユーザーが自分自身でアカウントを削除できるようにする
                </label>
                @error('unsubscribe') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">登録を許可するメールドメイン(カンマ区切り、空欄は制限なし)</label>
                <input type="text" wire:model="email_domains_allowed" placeholder="例: example.com, .example.org"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('email_domains_allowed') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">登録を拒否するメールドメイン(カンマ区切り、許可リストより優先)</label>
                <input type="text" wire:model="email_domains_denied" placeholder="例: example.com, .example.org"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('email_domains_denied') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">
                    先頭に「.」を付けると、そのドメインとサブドメインすべてに一致します(例: .example.org)。自己登録時のみ適用され、管理者による直接のユーザー作成には適用されません。
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">セッションタイムアウト</label>
                <select wire:model="session_timeout" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="0">無効</option>
                    <option value="60">1時間</option>
                    <option value="120">2時間</option>
                    <option value="240">4時間</option>
                    <option value="480">8時間</option>
                    <option value="720">12時間</option>
                    <option value="1440">24時間</option>
                    <option value="2880">48時間</option>
                </select>
                @error('session_timeout') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">この時間操作が無かったセッションは無効になり、再ログインが必要になります。</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">セッションの最大有効期間</label>
                <select wire:model="session_lifetime" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="0">無効</option>
                    <option value="240">4時間</option>
                    <option value="480">8時間</option>
                    <option value="720">12時間</option>
                    <option value="1440">1日</option>
                    <option value="10080">7日</option>
                    <option value="43200">30日</option>
                    <option value="86400">60日</option>
                    <option value="525600">365日</option>
                </select>
                @error('session_lifetime') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">操作の有無にかかわらず、ログインからこの時間が経過したセッションは無効になり、再ログインが必要になります。</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">パスワードの最小文字数</label>
                <input type="number" wire:model="password_min_length" min="1" max="255"
                    class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('password_min_length') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">
                    新規登録・管理者によるユーザー作成・パスワード変更のすべてに適用されます(パスワード有効期限は専用の運用基盤が必要なため対象外です)。
                </p>
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700 mb-2">パスワードに必ず含める文字種</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (RequiredPasswordCharacterClasses::CLASSES as $key => $class)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="password_required_char_classes" value="{{ $key }}" class="rounded border-gray-300">
                            {{ $class['message'] }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-gray-500">選んだ文字種は、それぞれ1文字以上必要です。既存のパスワードは、次に変更するときから対象になります。</p>
                @error('password_required_char_classes.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="lost_password" class="rounded border-gray-300">
                    ログインページに「パスワードをお忘れの場合」のリンクを表示し、本人によるパスワード再設定を許可する
                </label>
                <p class="mt-1 text-xs text-gray-500">無効の場合、本人からの再設定リクエストは受け付けません(管理者がユーザー編集画面から送るリセットメールのリンクは引き続き有効です)。</p>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="autologin" class="rounded border-gray-300">
                    ログインページに「ログイン状態を保持」チェックボックスを表示する
                </label>
                <p class="mt-1 text-xs text-gray-500">無効の場合、チェックボックス自体が表示されず、ログインは常にセッションクッキー(ブラウザを閉じると失効)のみになります。</p>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="rest_api_enabled" class="rounded border-gray-300">
                    REST APIを有効にする
                </label>
                <p class="mt-1 text-xs text-gray-500">無効の場合、APIキー/OAuth2による認証を試みる前にすべてのAPIリクエストを拒否します(既定は無効、Redmine本家と同じ)。</p>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="jsonp_enabled" class="rounded border-gray-300">
                    JSONPを有効にする
                </label>
                <p class="mt-1 text-xs text-gray-500">GETで<code>callback</code>を付けると、JSONを関数呼び出しにして返します。他サイトのページからAPIキー付きのURLを読めるようになるため、セキュリティ上のリスクがあります(既定は無効)。</p>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="login_required" class="rounded border-gray-300">
                    全ページにログインを必要とする
                </label>
                <p class="mt-1 text-xs text-gray-500">
                    無効にすると、未ログインのユーザーでも公開プロジェクトの課題一覧・課題詳細・Wikiページ・添付ファイルを閲覧できるようになります(それ以外の操作・非公開プロジェクトは引き続きログインが必要です)。既定は有効(本アプリの従来の挙動を維持)。
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">二要素認証</label>
                <select wire:model="twofa" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="0">無効</option>
                    <option value="1">任意(ユーザーが選択可能)</option>
                    <option value="2">全ユーザーに必須</option>
                    <option value="3">管理者のみ必須</option>
                </select>
                @error('twofa') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">
                    「必須」に設定すると、対象ユーザーは二要素認証を設定するまでアカウント設定ページ以外にアクセスできなくなります(この設定が「任意」または「管理者のみ必須」のときは、グループ編集画面で「このグループのメンバーに必須」を指定することもできます)。
                </p>
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">メール通知</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700">通知するイベント</label>
                <div class="mt-1 space-y-1">
                    @foreach (self::NOTIFIED_EVENTS as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" value="{{ $key }}" wire:model="notified_events" class="rounded border-gray-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('notified_events') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">
                    ここで無効にしたイベントは、各ユーザーの通知設定に関わらず一切メール送信されません。お知らせ/Wikiのメール通知は今後の対応予定です。
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">送信元メールアドレス(空欄で環境設定の既定値を使用)</label>
                <input type="email" wire:model="mail_from" placeholder="{{ config('mail.from.address') }}"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('mail_from') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="plain_text_mail" class="rounded border-gray-300">
                    テキスト形式のみで送信する(HTML形式を含めない)
                </label>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="default_users_no_self_notified" class="rounded border-gray-300">
                    新規ユーザーの既定で、自分自身が行った変更については通知メールを送信しない
                </label>
                <p class="mt-1 text-xs text-gray-500">
                    ここでの設定は新規ユーザー作成時の初期値です。各ユーザーはプロフィール画面で個別に変更できます。
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">新規ユーザーの既定のメール通知</label>
                <select wire:model="default_notification_option" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @foreach (MailNotificationOption::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @error('default_notification_option') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">
                    ここでの設定は新規ユーザー作成時の初期値です。各ユーザーはプロフィール画面で個別に変更できます。
                </p>
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="show_status_changes_in_mail_subject" class="rounded border-gray-300">
                通知メールの件名にステータスの変更を含める
            </label>

            <div>
                <label class="block text-sm font-medium text-gray-700">通知メールのヘッダ</label>
                <textarea wire:model="emails_header" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
                @error('emails_header') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">通知メールの署名</label>
                <textarea wire:model="emails_footer" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
                @error('emails_footer') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">工数管理</h2>

            <div>
                <span class="block text-sm font-medium text-gray-700">必須にする項目</span>
                <div class="mt-1 flex gap-4 text-sm text-gray-700">
                    @foreach (\App\Support\TimeLog\TimeLogConstraints::REQUIRABLE_FIELDS as $field => $label)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" value="{{ $field }}" wire:model="timelog_required_fields" class="rounded border-gray-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('timelog_required_fields') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">1日あたりの最大工数(時間、0で無制限)</label>
                <input type="number" step="0.01" min="0" max="1000" wire:model="timelog_max_hours_per_day" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-gray-500">同じユーザーが同じ日に記録できる工数の合計の上限です。</p>
                @error('timelog_max_hours_per_day') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="timelog_accept_0_hours" class="rounded border-gray-300">
                0時間の記録を許可する
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="timelog_accept_future_dates" class="rounded border-gray-300">
                未来の日付への記録を許可する
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="timelog_accept_closed_issues" class="rounded border-gray-300">
                終了した課題への記録を許可する
            </label>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">添付ファイル</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700">最大アップロードサイズ(KB)</label>
                <input type="number" wire:model="attachment_max_size" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('attachment_max_size') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">まとめてダウンロードできる合計サイズ(KB、0で無制限)</label>
                <input type="number" min="0" wire:model="bulk_download_max_size" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('bulk_download_max_size') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">許可する拡張子(カンマ区切り、空欄は制限なし)</label>
                <input type="text" wire:model="attachment_extensions_allowed" placeholder="例: png, jpg, pdf"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('attachment_extensions_allowed') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">禁止する拡張子(カンマ区切り、許可リストが設定されている場合は無視)</label>
                <input type="text" wire:model="attachment_extensions_denied" placeholder="例: exe, sh"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('attachment_extensions_denied') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">メール受信による課題作成</h2>
            <p class="text-xs text-gray-500">
                接続先メールサーバーは環境変数(IMAP_HOST等)で設定します。ここでは課題の作成先を設定します。
            </p>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="incoming_mail_enabled" class="rounded border-gray-300">
                有効にする
            </label>

            <div>
                <label class="block text-sm font-medium text-gray-700">
                    既定のプロジェクト(件名が <code>[識別子]</code> で始まらない場合に使用)
                </label>
                <select wire:model="incoming_mail_default_project_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
                @error('incoming_mail_default_project_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">既定のトラッカー</label>
                <select wire:model="incoming_mail_default_tracker_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->trackers as $tracker)
                        <option value="{{ $tracker->id }}">{{ $tracker->name }}</option>
                    @endforeach
                </select>
                @error('incoming_mail_default_tracker_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">既定のステータス</label>
                <select wire:model="incoming_mail_default_status_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->statuses as $status)
                        <option value="{{ $status->id }}">{{ $status->name }}</option>
                    @endforeach
                </select>
                @error('incoming_mail_default_status_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">本文の取得優先形式</label>
                <select wire:model="mail_handler_preferred_body_part" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="plain">プレーンテキスト優先</option>
                    <option value="html">HTML優先(プレーンテキスト化して使用)</option>
                </select>
                @error('mail_handler_preferred_body_part') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">本文の切り捨て行(1行に1つ、この行に完全一致した箇所以降を切り捨て)</label>
                <textarea wire:model="mail_handler_body_delimiters" rows="2" placeholder="例: -----Original Message-----"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
                @error('mail_handler_body_delimiters') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">除外する添付ファイル名(カンマ区切り、ワイルドカード可)</label>
                <input type="text" wire:model="mail_handler_excluded_filenames" placeholder="例: *.ics, winmail.dat"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('mail_handler_excluded_filenames') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold text-gray-900">リポジトリ</h2>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="autofetch_changesets" class="rounded border-gray-300">
                コミットを定期的に自動取得する(15分ごと)
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="commit_logtime_enabled" class="rounded border-gray-300">
                コミットメッセージの <code>#123 @2h</code> 形式で工数を自動記録する
            </label>

            <div>
                <label class="block text-sm font-medium text-gray-700">自動記録に使う作業分類(未選択の場合は既定の作業分類)</label>
                <select wire:model="commit_logtime_activity_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">選択してください</option>
                    @foreach ($this->activities as $activity)
                        <option value="{{ $activity->id }}">{{ $activity->name }}</option>
                    @endforeach
                </select>
                @error('commit_logtime_activity_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700 mb-2">有効なリポジトリ種別</span>
                <div class="flex gap-4">
                    @foreach (\App\Enums\RepositoryType::cases() as $case)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="enabled_scm_types" value="{{ $case->value }}" class="rounded border-gray-300">
                            {{ $case->value }}
                        </label>
                    @endforeach
                </div>
                @error('enabled_scm_types') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                @error('enabled_scm_types.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="sys_api_enabled" class="rounded border-gray-300">
                    リポジトリ管理用WebサービスのAPIを有効にする
                </label>
                <div class="mt-2 flex items-center gap-2">
                    <input type="text" wire:model="sys_api_key" placeholder="APIキー" autocomplete="off"
                        class="block w-full max-w-md rounded-md border-gray-300 font-mono shadow-sm sm:text-sm">
                    <button type="button" wire:click="generateSysApiKey" class="shrink-0 text-sm text-indigo-600 hover:underline">キーを生成</button>
                </div>
                <p class="mt-1 text-xs text-gray-500"><code>GET /sys/projects</code> と <code>/sys/fetch_changesets?id=&lt;プロジェクト&gt;</code> を <code>key</code> パラメータ付きで呼び出せます(post-receive フック用)。</p>
                @error('sys_api_key') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">履歴に表示するリビジョン数</label>
                <input type="number" min="1" max="1000" wire:model="repository_log_display_limit" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('repository_log_display_limit') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">差分の最大表示行数(0で無制限)</label>
                    <input type="number" min="0" max="100000" wire:model="diff_max_lines_displayed" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @error('diff_max_lines_displayed') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">ファイルの最大表示サイズ(KB、0で無制限)</label>
                    <input type="number" min="0" max="102400" wire:model="file_max_size_displayed" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @error('file_max_size_displayed') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">サムネイルの大きさ(px)</label>
                <input type="number" min="16" max="2000" wire:model="thumbnails_size" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-gray-500">この後にアップロードされる画像から適用されます。</p>
                @error('thumbnails_size') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">ファイル内容のエンコーディング候補(カンマ区切り)</label>
                <input type="text" wire:model="repositories_encodings" placeholder="例: SJIS-win, EUC-JP"
                    class="mt-1 block w-full max-w-md rounded-md border-gray-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-gray-500">UTF-8でないファイルやログを、ここに並べた順に試してUTF-8へ変換して表示します。</p>
                @error('repositories_encodings') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">コミットログのエンコーディング</label>
                <input type="text" wire:model="commit_logs_encoding" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('commit_logs_encoding') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="commit_logs_formatting" class="rounded border-gray-300">
                コミットログをMarkdownで整形して表示する
            </label>

            <div>
                <label class="block text-sm font-medium text-gray-700">課題を参照するキーワード(カンマ区切り)</label>
                <input type="text" wire:model="commit_ref_keywords" placeholder="*"
                    class="mt-1 block w-full max-w-md rounded-md border-gray-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-gray-500"><code>*</code>を含めると、キーワードなしの<code>#123</code>もコミットに関連付けられます(Redmineの既定は refs,references,IssueID)。</p>
                @error('commit_ref_keywords') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="commit_cross_project_ref" class="rounded border-gray-300">
                他のプロジェクトの課題も参照・更新できるようにする
            </label>

            <div>
                <span class="block text-sm font-medium text-gray-700 mb-1">コミットをステータス変更と結び付けるキーワード</span>
                <p class="mb-2 text-xs text-gray-500">
                    各行はキーワード(カンマ区切りで複数指定可)と、コミットメッセージ内でその語の直後に<code>#123</code>があった場合の変更先ステータスの組です。行を削除するとそのキーワードは無効になります。
                </p>
                <div class="space-y-2">
                    @foreach ($commit_fixing_keyword_rules as $index => $rule)
                        <div class="flex items-start gap-2" wire:key="fixing-keyword-rule-{{ $index }}">
                            <div class="flex-1">
                                <input type="text" wire:model="commit_fixing_keyword_rules.{{ $index }}.keywords" placeholder="例: fixes, fix, closes, close"
                                    class="block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                @error("commit_fixing_keyword_rules.{$index}.keywords") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="w-48">
                                <select wire:model="commit_fixing_keyword_rules.{{ $index }}.status_id" class="block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    <option value="">変更先ステータス</option>
                                    @foreach ($this->statuses as $status)
                                        <option value="{{ $status->id }}">{{ $status->name }}</option>
                                    @endforeach
                                </select>
                                @error("commit_fixing_keyword_rules.{$index}.status_id") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="w-24">
                                <input type="number" min="0" max="100" step="10" wire:model="commit_fixing_keyword_rules.{{ $index }}.done_ratio" placeholder="進捗%"
                                    class="block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                @error("commit_fixing_keyword_rules.{$index}.done_ratio") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="w-36">
                                <select wire:model="commit_fixing_keyword_rules.{{ $index }}.if_tracker_id" class="block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    <option value="">全トラッカー</option>
                                    @foreach ($this->trackers as $tracker)
                                        <option value="{{ $tracker->id }}">{{ $tracker->name }}</option>
                                    @endforeach
                                </select>
                                @error("commit_fixing_keyword_rules.{$index}.if_tracker_id") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="removeFixingKeywordRule({{ $index }})" class="mt-1.5 shrink-0 text-sm text-red-600 hover:underline">
                                削除
                            </button>
                        </div>
                    @endforeach
                </div>
                <button type="button" wire:click="addFixingKeywordRule" class="mt-2 text-sm text-indigo-600 hover:underline">
                    + 行を追加
                </button>
            </div>
        </section>

        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            保存
        </button>
    </form>
</div>
