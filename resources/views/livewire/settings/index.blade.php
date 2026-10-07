<?php

use App\Enums\EnumerationType;
use App\Enums\MailNotificationOption;
use App\Enums\ProjectModuleKey;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Enums\RepositoryType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Support\Attachments\AttachmentArchive;
use App\Support\Avatar\UserAvatar;
use App\Support\Format\Hours;
use App\Support\Issues\SubprojectScope;
use App\Support\Mail\PublicUrl;
use App\Support\Pagination\PageSize;
use App\Support\Preferences\UserPreferences;
use App\Support\Query\ListDefaults;
use App\Support\Query\ProjectFilterFieldRegistry;
use App\Support\Scm\CodesetConverter;
use App\Support\Scm\DisplayLimits;
use App\Support\TimeLog\TimeLogConstraints;
use App\Rules\RequiredPasswordCharacterClasses;
use App\Support\Calendar\WorkingDays;
use App\Support\Export\ExportLimit;
use App\Support\Issues\AssigneeChoice;
use App\Support\Issues\CopyOptions;
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
     * Columns selectable as the issue list's default display columns: the
     * issue list's own columns (issues/index.blade.php displayColumns(),
     * kept as a copy here) and every issue custom field as `cf_<id>` —
     * Redmine's issue_list_default_columns allows both. A custom field that
     * does not apply to a project, or that the viewer may not see, is left
     * out of that list when it is shown.
     *
     * @return array<string, string>
     */
    public static function issueListColumns(): array
    {
        $native = [
            'tracker_id' => __('トラッカー'),
            'status_id' => __('ステータス'),
            'priority_id' => __('優先度'),
            'subject' => __('題名'),
            'category_id' => __('カテゴリ'),
            'assigned_to_id' => __('担当者'),
            'author_id' => __('作成者'),
            'fixed_version_id' => __('対象バージョン'),
            'start_date' => __('開始日'),
            'due_date' => __('期日'),
            'created_at' => __('作成日'),
            'done_ratio' => __('進捗率'),
            'relations' => __('関連するチケット'),
            'attachments' => __('添付ファイル'),
            'watchers' => __('ウォッチャー'),
            'estimated_hours' => __('予定工数'),
            'total_estimated_hours' => __('合計予定工数'),
            'estimated_remaining_hours' => __('残り工数'),
            'spent_hours' => __('作業時間'),
            'total_spent_hours' => __('合計作業時間'),
            'project_id' => __('プロジェクト'),
            'parent_id' => __('親課題'),
            'updated_at' => __('更新日'),
            'closed_on' => __('終了日'),
            'last_updated_by' => __('最終更新者'),
            'is_private' => __('非公開'),
            'description' => __('説明'),
            'last_notes' => __('最新のコメント'),
        ];

        $customFields = CustomField::query()
            ->where('customized_type', \App\Enums\CustomizableType::Issue)
            ->orderBy('position')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id) => ["cf_{$id}" => $name])
            ->all();

        return [...$native, ...$customFields];
    }

    /**
     * Moves one chosen column of a column setting one place earlier
     * (negative $delta) or later — the order the list shows them in.
     */
    public function moveSettingColumn(string $setting, string $key, int $delta): void
    {
        if (! in_array($setting, ['issue_list_default_columns', 'related_issues_default_columns'], true)) {
            return;
        }

        $columns = array_values($this->{$setting});
        $index = array_search($key, $columns, true);

        if ($index === false) {
            return;
        }

        $target = $index + ($delta < 0 ? -1 : 1);

        if ($target < 0 || $target >= count($columns)) {
            return;
        }

        [$columns[$index], $columns[$target]] = [$columns[$target], $columns[$index]];
        $this->{$setting} = $columns;
    }

    /**
     * The events an administrator can switch mail on for; each key is checked
     * by NotificationRecipients before anything is sent. Add a key here in the
     * same commit that wires its listener.
     *
     * @return array<string, string>
     */
    public static function notifiedEvents(): array
    {
        return [
            'issue_added' => __('課題が作成されたとき'),
            'issue_updated' => __('課題が更新されたとき'),
            'issue_note_added' => __('課題にコメントが追加されたとき(「更新」を選んでいなくても通知)'),
            'issue_status_updated' => __('課題のステータスが変更されたとき(「更新」を選んでいなくても通知)'),
            'issue_assigned_to_updated' => __('課題の担当者が変更されたとき(「更新」を選んでいなくても通知)'),
            'issue_priority_updated' => __('課題の優先度が変更されたとき(「更新」を選んでいなくても通知)'),
            'issue_fixed_version_updated' => __('課題の対象バージョンが変更されたとき(「更新」を選んでいなくても通知)'),
            'issue_attachment_added' => __('課題に添付ファイルが追加されたとき(「更新」を選んでいなくても通知)'),
            'wiki_content_added' => __('Wikiページが追加されたとき'),
            'wiki_content_updated' => __('Wikiページが更新されたとき'),
            'news_added' => __('お知らせが投稿されたとき'),
            'news_comment_added' => __('お知らせにコメントが投稿されたとき'),
            'message_posted' => __('フォーラムにメッセージが投稿されたとき'),
            'document_added' => __('文書が追加されたとき'),
            'file_added' => __('ファイルが追加されたとき'),
        ];
    }

    public string $app_title = '';

    public string $default_language = '';

    public bool $force_default_language_for_anonymous = false;

    public bool $force_default_language_for_loggedin = false;

    public string $welcome_text = '';

    public int $default_issues_per_page = 25;

    // Redmine's own default is 10 — kept at 7 here to match this app's
    // pre-existing hardcoded activity-feed window (activity/index.blade.php,
    // activity/global-index.blade.php), so introducing this setting doesn't
    // silently change the default view for existing installs.
    public int $activity_days_default = 10;

    public int $feeds_limit = 15;

    public bool $webhooks_enabled = false;

    public int $issues_export_limit = 500;

    public string $per_page_options = '25,50,100';

    public int $search_results_per_page = 10;

    public bool $cache_formatted_text = false;

    public bool $wiki_tablesort_enabled = false;

    public string $new_item_menu_tab = '2';

    public bool $display_subprojects_issues = true;

    public ?int $default_issue_query = null;

    public bool $default_users_hide_mail = true;

    public string $default_users_time_zone = '';

    /** @var array<int, string> */
    public array $default_users_auto_watch_on = [];

    public string $timespan_format = 'minutes';

    public string $ui_theme = 'light';

    public string $date_format = '';

    public string $time_format = '';

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

    public string $mail_handler_allow_override = '';

    public string $mail_handler_project_from_subaddress = '';

    public string $mail_handler_preferred_body_part = 'plain';

    public bool $autofetch_changesets = false;

    public bool $mail_handler_api_enabled = false;

    public bool $mail_handler_no_notification = false;

    public string $mail_handler_api_key = '';

    public bool $mail_handler_enable_regex_delimiters = false;

    public bool $mail_handler_enable_regex_excluded_filenames = false;

    public bool $sys_api_enabled = false;

    public string $sys_api_key = '';

    public int $repository_log_display_limit = 100;

    public int $diff_max_lines_displayed = 1500;

    public int $file_max_size_displayed = 512;

    public bool $thumbnails_enabled = true;

    public int $thumbnails_size = 100;

    public string $repositories_encodings = '';

    public string $commit_logs_encoding = 'UTF-8';

    public bool $commit_logs_formatting = true;

    public string $commit_ref_keywords = 'refs,references,IssueID';

    public bool $commit_cross_project_ref = false;

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

    public bool $issue_group_assignment = false;

    public string $assignee_dropdown_display_format = 'users_then_groups';

    public bool $parent_issue_priority = true;

    public bool $parent_issue_dates = true;

    public bool $parent_issue_done_ratio = true;

    public bool $cross_project_issue_relations = false;

    /** @var array<int, string> ISO weekday numbers, 1 = Monday … 7 = Sunday */
    public array $non_working_week_days = [];

    public string $link_copied_issue = 'ask';

    public string $copy_attachments_on_issue_copy = 'ask';

    public bool $default_issue_start_date_to_creation_date = false;

    public bool $default_issue_start_date_for_api_and_mail = true;

    public ?int $default_issue_due_date_offset = null;

    /** @var array<int, string> */
    public array $issue_list_default_columns = [];

    /** @var array<int, string> */
    public array $related_issues_default_columns = [];

    public bool $display_related_issues_table_headers = false;

    public int $start_of_week = 0;

    public string $self_registration = 'automatic';

    public string $user_format = 'firstname_lastname';

    public bool $show_custom_fields_on_registration = true;

    public bool $unsubscribe = true;

    public int $session_timeout = 0;

    public int $session_lifetime = 0;

    public string $email_domains_allowed = '';

    public string $email_domains_denied = '';

    public int $max_additional_emails = 5;

    public int $password_min_length = 8;

    public int $password_max_age = 0;

    /** @var array<int, string> */
    public array $password_required_char_classes = [];

    public int $autologin = 0;

    public bool $lost_password = true;

    public bool $rest_api_enabled = false;

    public bool $jsonp_enabled = false;

    public bool $login_required = true;

    public string $twofa = '1';

    public bool $default_projects_public = true;

    /** @var array<string> */
    public array $default_projects_modules = [];

    /** @var array<int> */
    public array $default_projects_tracker_ids = [];

    public bool $sequential_project_identifiers = false;

    public ?int $new_project_user_role_id = null;

    public ?int $default_project_query = null;

    public string $project_list_display_type = 'board';

    /** @var array<int, string> */
    public array $project_list_default_columns = [];

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
        $this->user_format = (string) Setting::get('user_format', 'firstname_lastname');
        $this->show_custom_fields_on_registration = (bool) Setting::get('show_custom_fields_on_registration', true);
        $this->unsubscribe = Setting::get('unsubscribe', true);
        $this->session_timeout = Setting::get('session_timeout', 0);
        $this->session_lifetime = Setting::get('session_lifetime', 0);
        $this->email_domains_allowed = Setting::get('email_domains_allowed', '');
        $this->email_domains_denied = Setting::get('email_domains_denied', '');
        $this->password_max_age = (int) Setting::get('password_max_age', 0);
        $this->max_additional_emails = (int) Setting::get('max_additional_emails', 5);
        $this->password_min_length = Setting::get('password_min_length', 8);
        $this->password_required_char_classes = RequiredPasswordCharacterClasses::required();
        $this->autologin = (int) Setting::get('autologin', 0);
        $this->lost_password = Setting::get('lost_password', true);
        $this->rest_api_enabled = Setting::get('rest_api_enabled', false);
        $this->jsonp_enabled = Setting::get('jsonp_enabled', false);
        $this->login_required = Setting::get('login_required', true);
        $this->twofa = Setting::get('twofa', '1');
        $this->app_title = Setting::get('app_title', config('app.name'));
        $this->default_language = \App\Support\Locale\SupportedLocales::default();
        $this->force_default_language_for_anonymous = (bool) Setting::get('force_default_language_for_anonymous', false);
        $this->force_default_language_for_loggedin = (bool) Setting::get('force_default_language_for_loggedin', false);
        $this->welcome_text = Setting::get('welcome_text', '');
        $this->default_issues_per_page = Setting::get('default_issues_per_page', \App\Support\Pagination\PageSize::defaultSize());
        $this->activity_days_default = Setting::get('activity_days_default', 10);
        $this->feeds_limit = Setting::get('feeds_limit', 15);
        $this->webhooks_enabled = (bool) Setting::get('webhooks_enabled', false);
        $this->issues_export_limit = ExportLimit::issues();
        $this->per_page_options = Setting::get('per_page_options', PageSize::DEFAULT_OPTIONS);
        $this->search_results_per_page = Setting::get('search_results_per_page', PageSize::DEFAULT_SEARCH_RESULTS);
        $this->cache_formatted_text = Setting::get('cache_formatted_text', false);
        $this->wiki_tablesort_enabled = (bool) Setting::get('wiki_tablesort_enabled', false);
        $this->new_item_menu_tab = (string) Setting::get('new_item_menu_tab', '2');
        $this->display_subprojects_issues = SubprojectScope::enabled();
        $this->default_issue_query = filled(Setting::get('default_issue_query')) ? (int) Setting::get('default_issue_query') : null;
        $this->default_users_hide_mail = (bool) Setting::get('default_users_hide_mail', true);
        $this->default_users_time_zone = \App\Support\Locale\TimeZones::isSupported(Setting::get('default_users_time_zone')) ? Setting::get('default_users_time_zone') : '';
        $this->default_users_auto_watch_on = UserPreferences::defaults()['auto_watch_on'];
        $this->timespan_format = Hours::timespanFormat();
        $this->ui_theme = UserPreferences::siteTheme();
        $this->date_format = array_key_exists((string) Setting::get('date_format', ''), \App\Support\Format\DateTimes::DATE_FORMATS) ? Setting::get('date_format') : '';
        $this->time_format = array_key_exists((string) Setting::get('time_format', ''), \App\Support\Format\DateTimes::TIME_FORMATS) ? Setting::get('time_format') : '';
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
        $this->issue_group_assignment = (bool) Setting::get('issue_group_assignment', false);
        $this->assignee_dropdown_display_format = AssigneeChoice::displayFormat();
        $this->parent_issue_priority = Setting::get('parent_issue_priority', true);
        $this->parent_issue_dates = Setting::get('parent_issue_dates', true);
        $this->parent_issue_done_ratio = Setting::get('parent_issue_done_ratio', true);
        $this->cross_project_issue_relations = Setting::get('cross_project_issue_relations', false);
        $this->non_working_week_days = array_map('strval', WorkingDays::nonWorkingWeekDays());
        $this->link_copied_issue = CopyOptions::linkMode();
        $this->copy_attachments_on_issue_copy = CopyOptions::attachmentsMode();
        $this->default_issue_start_date_to_creation_date = Setting::get('default_issue_start_date_to_creation_date', false);
        $this->default_issue_start_date_for_api_and_mail = (bool) Setting::get('default_issue_start_date_for_api_and_mail', true);
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
        $this->mail_handler_allow_override = Setting::get('mail_handler_allow_override', '');
        $this->mail_handler_project_from_subaddress = Setting::get('mail_handler_project_from_subaddress', '');
        $this->mail_handler_preferred_body_part = Setting::get('mail_handler_preferred_body_part', 'plain');
        $this->autofetch_changesets = Setting::get('autofetch_changesets', false);
        $this->repository_log_display_limit = Setting::get('repository_log_display_limit', PageSize::DEFAULT_REPOSITORY_LOG_LIMIT);
        $this->mail_handler_api_enabled = (bool) Setting::get('mail_handler_api_enabled', false);
        $this->mail_handler_no_notification = (bool) Setting::get('mail_handler_no_notification', false);
        $this->mail_handler_api_key = (string) Setting::get('mail_handler_api_key', '');
        $this->mail_handler_enable_regex_delimiters = (bool) Setting::get('mail_handler_enable_regex_delimiters', false);
        $this->mail_handler_enable_regex_excluded_filenames = (bool) Setting::get('mail_handler_enable_regex_excluded_filenames', false);
        $this->sys_api_enabled = Setting::get('sys_api_enabled', false);
        $this->sys_api_key = Setting::get('sys_api_key', '');
        $this->diff_max_lines_displayed = DisplayLimits::maxDiffLines();
        $this->file_max_size_displayed = DisplayLimits::maxFileSizeKb();
        $this->thumbnails_enabled = (bool) Setting::get('thumbnails_enabled', true);
        $this->thumbnails_size = Setting::get('thumbnails_size', 100);
        $this->repositories_encodings = Setting::get('repositories_encodings', '');
        $this->commit_logs_encoding = Setting::get('commit_logs_encoding', 'UTF-8');
        $this->commit_logs_formatting = Setting::get('commit_logs_formatting', true);
        $this->commit_ref_keywords = Setting::get('commit_ref_keywords', 'refs,references,IssueID');
        $this->commit_cross_project_ref = Setting::get('commit_cross_project_ref', false);
        $this->commit_logtime_enabled = Setting::get('commit_logtime_enabled', false);
        $this->commit_logtime_activity_id = Setting::get('commit_logtime_activity_id');
        $this->enabled_scm_types = Setting::get('enabled_scm_types', RepositoryType::defaultEnabledValues());
        // No rule until one is added (Redmine's commit_update_keywords default).
        $this->commit_fixing_keyword_rules = Setting::get('commit_fixing_keyword_rules', []);
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
        $this->default_project_query = filled(Setting::get('default_project_query')) ? (int) Setting::get('default_project_query') : null;
        $this->project_list_display_type = ProjectFilterFieldRegistry::defaultDisplayType();
        $this->project_list_default_columns = ProjectFilterFieldRegistry::defaultColumns();
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
    public function generateMailHandlerApiKey(): void
    {
        $this->mail_handler_api_key = Str::random(40);
    }

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
        $encodingName = fn (string $attribute, mixed $value, \Closure $fail) => $isKnownEncoding((string) $value) ? null : $fail(__('「:value」は未対応のエンコーディングです。', ['value' => $value]));
        $encodingList = function (string $attribute, mixed $value, \Closure $fail) use ($isKnownEncoding): void {
            foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $name) {
                if (! $isKnownEncoding($name)) {
                    $fail(__('「:value」は未対応のエンコーディングです。', ['value' => $name]));
                }
            }
        };

        $data = $this->validate([
            ...$ruleChangeRules,
            'app_title' => ['required', 'string', 'max:255'],
            'default_language' => ['required', Rule::in(array_keys(\App\Support\Locale\SupportedLocales::all()))],
            'force_default_language_for_anonymous' => ['boolean'],
            'force_default_language_for_loggedin' => ['boolean'],
            'welcome_text' => ['nullable', 'string', 'max:5000'],
            'default_issues_per_page' => ['required', 'integer', 'min:5', 'max:200'],
            'activity_days_default' => ['required', 'integer', 'min:1', 'max:365'],
            'feeds_limit' => ['required', 'integer', 'min:1', 'max:500'],
            'webhooks_enabled' => ['boolean'],
            'issues_export_limit' => ['required', 'integer', 'min:1', 'max:'.ExportLimit::MAXIMUM],
            'per_page_options' => ['required', 'string', 'max:100', 'regex:/^\s*[1-9]\d{0,3}([\s,]+[1-9]\d{0,3})*\s*$/'],
            'search_results_per_page' => ['required', 'integer', 'min:1', 'max:200'],
            'cache_formatted_text' => ['boolean'],
            'wiki_tablesort_enabled' => ['boolean'],
            'new_item_menu_tab' => ['required', Rule::in(['0', '1', '2'])],
            'display_subprojects_issues' => ['boolean'],
            'default_issue_query' => ['nullable', Rule::exists('queries', 'id')->where('type', QueryType::Issue->value)->where('visibility', QueryVisibility::Public->value)->whereNull('project_id')],
            'default_users_hide_mail' => ['boolean'],
            'default_users_time_zone' => ['nullable', 'string', 'timezone:all'],
            'default_users_auto_watch_on' => ['array'],
            'default_users_auto_watch_on.*' => [Rule::in(array_keys(UserPreferences::AUTO_WATCH_ON))],
            'timespan_format' => ['required', Rule::in(array_keys(Hours::FORMATS))],
            'ui_theme' => ['required', Rule::in(UserPreferences::THEMES)],
            'date_format' => ['nullable', Rule::in(array_keys(\App\Support\Format\DateTimes::DATE_FORMATS))],
            'time_format' => ['nullable', Rule::in(array_keys(\App\Support\Format\DateTimes::TIME_FORMATS))],
            'issue_list_default_totals' => ['array'],
            'issue_list_default_totals.*' => [Rule::in(array_keys(ListDefaults::issueTotalLabels()))],
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
            'mail_handler_allow_override' => ['nullable', 'string', 'max:1000'],
            'mail_handler_project_from_subaddress' => ['nullable', 'string', 'max:255', 'regex:/^[^@\s]+@[^@\s]+$/'],
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
            'mail_handler_api_enabled' => ['boolean'],
            'mail_handler_no_notification' => ['boolean'],
            'mail_handler_api_key' => ['nullable', 'string', 'max:255'],
            'mail_handler_enable_regex_delimiters' => ['boolean'],
            'mail_handler_enable_regex_excluded_filenames' => ['boolean'],
            'sys_api_enabled' => ['boolean'],
            'sys_api_key' => ['nullable', 'string', 'max:255'],
            'diff_max_lines_displayed' => ['required', 'integer', 'min:0', 'max:100000'],
            'file_max_size_displayed' => ['required', 'integer', 'min:0', 'max:102400'],
            'thumbnails_enabled' => ['boolean'],
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
            'issue_group_assignment' => ['boolean'],
            'assignee_dropdown_display_format' => ['required', Rule::in(array_keys(AssigneeChoice::displayFormats()))],
            'parent_issue_priority' => ['boolean'],
            'parent_issue_dates' => ['boolean'],
            'parent_issue_done_ratio' => ['boolean'],
            'cross_project_issue_relations' => ['boolean'],
            'non_working_week_days' => ['array'],
            'non_working_week_days.*' => ['in:1,2,3,4,5,6,7'],
            'link_copied_issue' => ['required', 'in:yes,no,ask'],
            'copy_attachments_on_issue_copy' => ['required', 'in:yes,no,ask'],
            'default_issue_start_date_to_creation_date' => ['boolean'],
            'default_issue_start_date_for_api_and_mail' => ['boolean'],
            'default_issue_due_date_offset' => ['nullable', 'integer', 'min:0'],
            'related_issues_default_columns' => ['array'],
            'related_issues_default_columns.*' => [Rule::in(array_keys(RelatedIssueColumns::available()))],
            'display_related_issues_table_headers' => ['boolean'],
            'issue_list_default_columns' => ['array', 'min:1'],
            'issue_list_default_columns.*' => [Rule::in(array_keys(self::issueListColumns()))],
            'start_of_week' => ['required', Rule::in([0, 1, 6])],
            'self_registration' => ['required', 'in:disabled,manual,email,automatic'],
            'user_format' => ['required', Rule::in(\App\Models\User::USER_FORMATS)],
            'show_custom_fields_on_registration' => ['boolean'],
            'unsubscribe' => ['boolean'],
            'session_timeout' => ['required', Rule::in([0, 60, 120, 240, 480, 720, 1440, 2880])],
            'session_lifetime' => ['required', Rule::in([0, 240, 480, 720, 1440, 10080, 43200, 86400, 525600])],
            'email_domains_allowed' => ['nullable', 'string', 'max:1000'],
            'email_domains_denied' => ['nullable', 'string', 'max:1000'],
            'max_additional_emails' => ['required', 'integer', 'min:0', 'max:50'],
            'password_max_age' => ['required', 'integer', Rule::in([0, 7, 30, 60, 90, 180, 365])],
            'password_min_length' => ['required', 'integer', 'min:1', 'max:255'],
            'password_required_char_classes' => ['array'],
            'password_required_char_classes.*' => [Rule::in(array_keys(RequiredPasswordCharacterClasses::CLASSES))],
            'autologin' => ['required', 'integer', Rule::in([0, 1, 7, 30, 365])],
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
            'project_list_display_type' => ['required', Rule::in(ProjectFilterFieldRegistry::DISPLAY_TYPES)],
            'project_list_default_columns' => ['array', 'min:1'],
            'project_list_default_columns.*' => [Rule::in(array_keys(ProjectFilterFieldRegistry::nativeColumns()))],
            'default_project_query' => ['nullable', Rule::exists('queries', 'id')->where('type', QueryType::Project->value)->where('visibility', QueryVisibility::Public->value)->whereNull('project_id')],
            'notified_events' => ['array'],
            'notified_events.*' => [Rule::in(array_keys(self::notifiedEvents()))],
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
        $data['default_users_time_zone'] = (string) ($data['default_users_time_zone'] ?? '');
        $data['date_format'] = (string) ($data['date_format'] ?? '');
        $data['time_format'] = (string) ($data['time_format'] ?? '');
        $data['sys_api_key'] = trim((string) ($data['sys_api_key'] ?? ''));
        $data['mail_handler_api_key'] = trim((string) ($data['mail_handler_api_key'] ?? ''));
        $data['repositories_encodings'] = trim((string) ($data['repositories_encodings'] ?? ''));

        // Stored the way Redmine's time_entry_list_defaults is.
        Setting::set('time_entry_list_defaults', [
            'column_names' => array_values($data['time_entry_list_default_columns']),
            'totalable_names' => $data['time_entry_list_show_total'] ? ['hours'] : [],
        ]);
        unset($data['time_entry_list_default_columns'], $data['time_entry_list_show_total']);

        // Stored the way Redmine's project_list_defaults is.
        Setting::set('project_list_defaults', ['column_names' => array_values($data['project_list_default_columns'])]);
        unset($data['project_list_default_columns']);

        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        $this->commit_fixing_keyword_rules = $data['commit_fixing_keyword_rules'];

        session()->flash('status', __('設定を保存しました。'));
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">{{ __('設定') }}</h1>

    <form wire:submit="save" class="space-y-8">
        <section class="space-y-4">
            <div>
                <label for="field-app_title" class="block text-sm font-medium text-neutral-700">{{ __('アプリケーション名') }}</label>
                <input id="field-app_title" type="text" wire:model="app_title" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('app_title') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-default_language" class="block text-sm font-medium text-neutral-700">{{ __('既定の言語') }}</label>
                <select id="field-default_language" wire:model="default_language" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Locale\SupportedLocales::all() as $code => $languageName)
                        <option value="{{ $code }}">{{ $languageName }}</option>
                    @endforeach
                </select>
                @error('default_language') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <label class="mt-2 flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="force_default_language_for_anonymous" class="rounded border-neutral-300">
                    {{ __('ログインしていない利用者には常に既定の言語を使う') }}
                </label>
                <label class="mt-1 flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="force_default_language_for_loggedin" class="rounded border-neutral-300">
                    {{ __('ログインしている利用者にも常に既定の言語を使う') }}
                </label>
            </div>

            <div>
                <label for="field-welcome_text" class="block text-sm font-medium text-neutral-700">{{ __('ウェルカムメッセージ') }}</label>
                <textarea id="field-welcome_text" wire:model="welcome_text" rows="5"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm"></textarea>
                <p class="mt-1 text-xs text-neutral-500">{{ __('プロジェクト一覧(ホーム)画面の先頭に表示されます。Markdown記法が使えます。空欄の場合は何も表示されません。') }}</p>
                @error('welcome_text') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-default_issues_per_page" class="block text-sm font-medium text-neutral-700">{{ __('課題一覧の1ページあたりの件数') }}</label>
                <input id="field-default_issues_per_page" type="number" wire:model="default_issues_per_page" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('default_issues_per_page') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="field-host_name" class="block text-sm font-medium text-neutral-700">{{ __('ホスト名(メール内リンク用)') }}</label>
                    <input id="field-host_name" type="text" wire:model="host_name" placeholder="{{ __('例: pm.example.com または pm.example.com/redmine') }}"
                        class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <p class="mt-1 text-xs text-neutral-500">{{ __('空欄のときは APP_URL を使います。メールやキューで生成するリンクに使われます。') }}</p>
                    @error('host_name') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="field-protocol" class="block text-sm font-medium text-neutral-700">{{ __('プロトコル') }}</label>
                    <select id="field-protocol" wire:model="protocol" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                        <option value="http">HTTP</option>
                        <option value="https">HTTPS</option>
                    </select>
                    @error('protocol') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="field-activity_days_default" class="block text-sm font-medium text-neutral-700">{{ __('活動画面の既定の表示期間(日数)') }}</label>
                <input id="field-activity_days_default" type="number" min="1" max="365" wire:model="activity_days_default" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('activity_days_default') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-per_page_options" class="block text-sm font-medium text-neutral-700">{{ __('一覧の表示件数の選択肢') }}</label>
                <input id="field-per_page_options" type="text" wire:model="per_page_options" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{{ __('カンマまたは空白区切り(例: 25,50,100)。課題・プロジェクト・お知らせ一覧の「表示件数」に出る選択肢です。') }}</p>
                @error('per_page_options') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-search_results_per_page" class="block text-sm font-medium text-neutral-700">{{ __('検索結果の1ページあたりの件数') }}</label>
                <input id="field-search_results_per_page" type="number" min="1" max="200" wire:model="search_results_per_page" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('search_results_per_page') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-feeds_limit" class="block text-sm font-medium text-neutral-700">{{ __('Atomフィードの最大エントリ数') }}</label>
                <input id="field-feeds_limit" type="number" min="1" max="500" wire:model="feeds_limit" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{{ __('活動・課題・お知らせ・フォーラムの各Atomフィードに共通で適用されます。') }}</p>
                @error('feeds_limit') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="webhooks_enabled" class="rounded border-neutral-300">
                {{ __('Webhookを有効にする(無効にすると、登録済みのWebhookも送信されません)') }}
            </label>

            <div>
                <label for="field-issues_export_limit" class="block text-sm font-medium text-neutral-700">{{ __('課題一覧のエクスポート件数の上限') }}</label>
                <input id="field-issues_export_limit" type="number" min="1" max="{{ ExportLimit::MAXIMUM }}" wire:model="issues_export_limit" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{{ __('課題一覧のCSV・PDFエクスポートに含める最大件数です(上限:max)。', ['max' => ExportLimit::MAXIMUM]) }}</p>
                @error('issues_export_limit') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="wiki_tablesort_enabled" class="rounded border-neutral-300">
                {{ __('Wikiの表(見出し行と2行以上の本体を持つもの)を、見出しのクリックで並べ替えられるようにする') }}
            </label>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="cache_formatted_text" class="rounded border-neutral-300">
                    {{ __('2KBを超えるMarkdown本文の描画結果をキャッシュする') }}
                </label>
                <p class="mt-1 text-xs text-neutral-500">{{ __('大きなWikiページの表示を速くします。他ページの取り込み(:include)や子ページ一覧(:child_pages)を含む本文は対象外で、キャッシュは1時間で失効します。#123やページリンクの参照先が作成・削除された直後は、最大1時間古い表示が残ることがあります。', ['include' => '{'.'{include}'.'}', 'child_pages' => '{'.'{child_pages}'.'}']) }}</p>
            </div>

            <div>
                <label for="field-user_format" class="block text-sm font-medium text-neutral-700">{{ __('ユーザー名の表示形式') }}</label>
                <select id="field-user_format" wire:model="user_format" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (\App\Models\User::userFormatLabels() as $format => $label)
                        <option value="{{ $format }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('user_format') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">{{ __('課題・コメント・Wiki・お知らせ・活動・工数・選択肢・メール・APIの作成者/担当者などでユーザーを表示するときの形式です(管理画面のユーザー一覧とAPIのユーザー自身の name は変わりません)。') }}</p>
                <p class="mt-1 text-xs text-neutral-500">{{ __('姓・名を使う形式は、姓と名の両方が入力されたユーザーだけに適用され、それ以外は名前で表示されます。') }}</p>
            </div>

            <div>
                <label for="field-issue_done_ratio" class="block text-sm font-medium text-neutral-700">{{ __('課題の進捗率') }}</label>
                <select id="field-issue_done_ratio" wire:model="issue_done_ratio" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="issue_field">{{ __('課題ごとに手動入力') }}</option>
                    <option value="issue_status">{{ __('ステータスから算出') }}</option>
                </select>
                @error('issue_done_ratio') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-issue_done_ratio_interval" class="block text-sm font-medium text-neutral-700">{{ __('進捗率の選択肢の刻み') }}</label>
                <select id="field-issue_done_ratio_interval" wire:model="issue_done_ratio_interval" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (DoneRatioSteps::INTERVALS as $interval)
                        <option value="{{ $interval }}">{{ $interval }} %</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-neutral-500">{{ __('課題フォーム・一括編集・ステータスの既定進捗率・進捗率型カスタムフィールドの選択肢の刻み幅です(保存済みの値の妥当性には影響しません)。') }}</p>
                @error('issue_done_ratio_interval') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="issue_group_assignment" class="rounded border-neutral-300">
                    {{ __('グループへの課題の割り当てを許可') }}
                </label>
                <p class="mt-1 text-xs text-neutral-500">{{ __('オンにすると、プロジェクトのメンバーで割り当て可能なロールを持つグループを担当者に選べます。グループのメンバー全員が担当者として扱われます。オフにしても既存の割り当ては残ります。') }}</p>
                <label class="mt-2 block text-sm font-medium text-neutral-700" for="assignee_dropdown_display_format">{{ __('担当者ドロップダウンの表示形式') }}</label>
                <select id="assignee_dropdown_display_format" wire:model="assignee_dropdown_display_format" x-bind:disabled="! $wire.issue_group_assignment"
                    class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm disabled:opacity-50 sm:text-sm">
                    @foreach (AssigneeChoice::displayFormats() as $format => $formatLabel)
                        <option value="{{ $format }}">{{ $formatLabel }}</option>
                    @endforeach
                </select>
                @error('assignee_dropdown_display_format') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="close_duplicate_issues" class="rounded border-neutral-300">
                {{ __('重複課題を自動的にクローズする(この課題を複製とする課題がクローズされたとき)') }}
            </label>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="parent_issue_priority" class="rounded border-neutral-300">
                {{ __('親課題の優先度を子課題から算出する(未クローズの子課題のうち最高優先度)') }}
            </label>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="parent_issue_dates" class="rounded border-neutral-300">
                {{ __('親課題の開始日/期日を子課題から算出する(最も早い開始日〜最も遅い期日)') }}
            </label>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="parent_issue_done_ratio" class="rounded border-neutral-300">
                {{ __('親課題の進捗率を子課題から算出する(予定工数で重み付けした平均)') }}
            </label>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="cross_project_issue_relations" class="rounded border-neutral-300">
                {{ __('プロジェクトをまたいだ課題関連を許可する') }}
            </label>

            <div>
                <span class="block text-sm font-medium text-neutral-700">{{ __('非稼働日(曜日)') }}</span>
                <div class="mt-1 flex flex-wrap gap-3">
                    @foreach (collect(range(1, 7))->mapWithKeys(fn (int $day): array => [(string) $day => \App\Support\Locale\SupportedLocales::weekdayName($day)]) as $weekday => $weekdayLabel)
                        <label class="flex items-center gap-1 text-sm text-neutral-700" wire:key="non-working-{{ $weekday }}">
                            <input type="checkbox" wire:model="non_working_week_days" value="{{ $weekday }}" class="rounded border-neutral-300">
                            {{ $weekdayLabel }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-neutral-500">{{ __('先行/後続の関連による日付の自動調整と遅延日数は、ここで選んだ曜日を飛ばして数えます。何も選ばなければ暦日で数えます(全曜日を選んだ場合も暦日)。') }}</p>
                @error('non_working_week_days.*') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="field-link_copied_issue" class="block text-sm font-medium text-neutral-700">{{ __('課題をコピーしたとき、コピー元との関連(コピー元)を作る') }}</label>
                    <select id="field-link_copied_issue" wire:model="link_copied_issue" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                        <option value="ask">{{ __('コピーするときに選ぶ') }}</option>
                        <option value="yes">{{ __('常に作る') }}</option>
                        <option value="no">{{ __('作らない') }}</option>
                    </select>
                    @error('link_copied_issue') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="field-copy_attachments_on_issue_copy" class="block text-sm font-medium text-neutral-700">{{ __('課題をコピーしたとき、添付ファイルをコピーする') }}</label>
                    <select id="field-copy_attachments_on_issue_copy" wire:model="copy_attachments_on_issue_copy" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                        <option value="ask">{{ __('コピーするときに選ぶ') }}</option>
                        <option value="yes">{{ __('常にコピーする') }}</option>
                        <option value="no">{{ __('コピーしない') }}</option>
                    </select>
                    @error('copy_attachments_on_issue_copy') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="reactions_enabled" class="rounded border-neutral-300">
                {{ __('リアクション(いいね)機能を有効にする') }}
            </label>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="default_issue_start_date_to_creation_date" class="rounded border-neutral-300">
                    {{ __('新規課題の開始日を作成日にする') }}
                </label>
                <p class="mt-1 text-xs text-neutral-500">{{ __('無効の場合、開始日は自動設定されません(コピー元の課題がある場合はその開始日を引き継ぎます)。') }}</p>
                <label class="mt-2 flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="default_issue_start_date_for_api_and_mail" class="rounded border-neutral-300">
                    {{ __('REST APIと受信メールで作る課題にも適用する') }}
                </label>
                <p class="mt-1 text-xs text-neutral-500">{{ __('既定はオンです(Redmine と同じ)。オフにすると、REST API と受信メールで作る課題には開始日を補いません。') }}</p>
            </div>

            <div>
                <label for="field-default_issue_due_date_offset" class="block text-sm font-medium text-neutral-700">{{ __('新規課題の期日の既定値(作成日からの日数)') }}</label>
                <input id="field-default_issue_due_date_offset" type="number" min="0" wire:model="default_issue_due_date_offset"
                    placeholder="{{ __('未設定(既定値なし)') }}"
                    class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{{ __('空欄の場合、期日は自動設定されません。') }}</p>
                @error('default_issue_due_date_offset') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('課題一覧の既定表示列') }}</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (self::issueListColumns() as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-neutral-700">
                            <input type="checkbox" wire:model="issue_list_default_columns" value="{{ $key }}" class="rounded border-neutral-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <div class="mt-2"><x-column-order :columns="$issue_list_default_columns" :labels="self::issueListColumns()" setting="issue_list_default_columns" /></div>
                @error('issue_list_default_columns') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                @error('issue_list_default_columns.*') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('関連課題・サブタスクの表示列') }}</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (RelatedIssueColumns::available() as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-neutral-700">
                            <input type="checkbox" wire:model="related_issues_default_columns" value="{{ $key }}" class="rounded border-neutral-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <div class="mt-2"><x-column-order :columns="$related_issues_default_columns" :labels="RelatedIssueColumns::available()" setting="related_issues_default_columns" /></div>
                <p class="mt-1 text-xs text-neutral-500">{{ __('課題の詳細画面で、サブタスクと関連課題の表に題名と一緒に表示する列です。') }}</p>
                @error('related_issues_default_columns.*') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <label class="mt-2 flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="display_related_issues_table_headers" class="rounded border-neutral-300">
                    {{ __('表に見出し行を表示する') }}
                </label>
            </div>
        </section>

        <section class="space-y-4 border-t border-neutral-200 pt-6">
            <h2 class="text-sm font-semibold text-neutral-900">{{ __('表示') }}</h2>

            <div>
                <label for="field-ui_theme" class="block text-sm font-medium text-neutral-700">{{ __('テーマ') }}</label>
                <select id="field-ui_theme" wire:model="ui_theme" data-ui-theme class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Preferences\UserPreferences::themeLabels() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('ui_theme') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">{{ __('個人設定でテーマを選んでいないユーザーとログインしていない利用者に適用されます。') }}</p>
            </div>

            <div>
                <label for="field-start_of_week" class="block text-sm font-medium text-neutral-700">{{ __('週の始まり') }}</label>
                <select id="field-start_of_week" wire:model="start_of_week" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="0">{{ __('日曜日') }}</option>
                    <option value="1">{{ __('月曜日') }}</option>
                    <option value="6">{{ __('土曜日') }}</option>
                </select>
                @error('start_of_week') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">{{ __('カレンダー画面(プロジェクト内/全プロジェクト共通)の週始まりに反映されます。') }}</p>
            </div>

            <div>
                <label for="field-new_item_menu_tab" class="block text-sm font-medium text-neutral-700">{{ __('新規作成メニュー(プロジェクト内)') }}</label>
                <select id="field-new_item_menu_tab" wire:model="new_item_menu_tab" class="mt-1 block w-full max-w-md rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="0">{{ __('なし') }}</option>
                    <option value="1">{{ __('「新しい課題」リンクのみ') }}</option>
                    <option value="2">{{ __('「+」ドロップダウン(課題・バージョン・お知らせなど)') }}</option>
                </select>
                @error('new_item_menu_tab') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="display_subprojects_issues" class="rounded border-neutral-300">
                {{ __('親プロジェクトの課題一覧・工数合計にサブプロジェクトも含める') }}
            </label>

            <div>
                <label for="field-default_issue_query" class="block text-sm font-medium text-neutral-700">{{ __('課題一覧の既定クエリ(全体)') }}</label>
                <select id="field-default_issue_query" wire:model="default_issue_query" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">{{ __('指定しない') }}</option>
                    @foreach (\App\Models\Query::query()->where('type', \App\Enums\QueryType::Issue->value)->where('visibility', \App\Enums\QueryVisibility::Public->value)->whereNull('project_id')->orderBy('name')->get() as $query)
                        <option value="{{ $query->id }}">{{ $query->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-neutral-500">{{ __('個人設定・プロジェクトの既定がないときに使われます。公開クエリのみ選べます。') }}</p>
                @error('default_issue_query') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-neutral-700">{{ __('新規ユーザーの既定の個人設定') }}</span>
                <label class="mt-1 flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="default_users_hide_mail" class="rounded border-neutral-300">
                    {{ __('メールアドレスを他のユーザーに表示しない') }}
                </label>
                <div class="mt-1 flex flex-wrap gap-4 text-sm text-neutral-700">
                    @foreach (\App\Support\Preferences\UserPreferences::autoWatchOnLabels() as $value => $label)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" value="{{ $value }}" wire:model="default_users_auto_watch_on" class="rounded border-neutral-300">
                            {{ __(':itemをウォッチ', ['item' => $label]) }}
                        </label>
                    @endforeach
                </div>
                <label for="field-default_users_time_zone" class="mt-2 block text-sm text-neutral-700">{{ __('タイムゾーン') }}</label>
                <select id="field-default_users_time_zone" wire:model="default_users_time_zone" data-default-users-time-zone class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">{{ __('サーバーの設定(:zone)', ['zone' => config('app.timezone')]) }}</option>
                    @foreach (\App\Support\Locale\TimeZones::options() as $identifier => $label)
                        <option value="{{ $identifier }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('default_users_time_zone') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">{{ __('個人設定を変更していないユーザーに適用されます。') }}</p>
            </div>

            <div>
                <label for="field-timespan_format" class="block text-sm font-medium text-neutral-700">{{ __('時間の表示形式') }}</label>
                <select id="field-timespan_format" wire:model="timespan_format" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Format\Hours::formatLabels() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('timespan_format') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-date_format" class="block text-sm font-medium text-neutral-700">{{ __('日付の形式') }}</label>
                <select id="field-date_format" wire:model="date_format" data-date-format class="mt-1 block w-full max-w-md rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Format\DateTimes::dateFormatOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('date_format') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-time_format" class="block text-sm font-medium text-neutral-700">{{ __('時刻の形式') }}</label>
                <select id="field-time_format" wire:model="time_format" data-time-format class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Format\DateTimes::timeFormatOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('time_format') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">{{ __('画面・CSV・PDF・メールの日付と時刻に使います。REST API は常に ISO 8601 です。') }}</p>
            </div>

            <div>
                <span class="block text-sm font-medium text-neutral-700">{{ __('課題一覧で合計する項目') }}</span>
                <div class="mt-1 flex flex-wrap gap-4 text-sm text-neutral-700">
                    @foreach (\App\Support\Query\ListDefaults::issueTotalLabels() as $key => $label)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" value="{{ $key }}" wire:model="issue_list_default_totals" class="rounded border-neutral-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('issue_list_default_totals.*') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-neutral-700">{{ __('工数一覧の初期表示列') }}</span>
                <div class="mt-1 flex flex-wrap gap-4 text-sm text-neutral-700">
                    @foreach (\App\Support\Query\ListDefaults::timeEntryColumnLabels() as $key => $label)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" value="{{ $key }}" wire:model="time_entry_list_default_columns" class="rounded border-neutral-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <label class="mt-2 flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="time_entry_list_show_total" class="rounded border-neutral-300">
                    {{ __('工数一覧に時間の合計を表示する') }}
                </label>
                @error('time_entry_list_default_columns') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="gravatar_enabled" class="rounded border-neutral-300">
                    {{ __('Gravatarを使う') }}
                </label>
                <p class="mt-1 text-xs text-neutral-500">{{ __('有効にすると、ユーザーのメールアドレスのハッシュが gravatar.com に送られ、閲覧者のブラウザが画像を直接取得します。無効のときはイニシャルのアイコンを表示します。') }}</p>
                <select wire:model="gravatar_default" aria-label="{{ __('Gravatarの既定の画像') }}" class="mt-2 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (\App\Support\Avatar\UserAvatar::defaultStyleLabels() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('gravatar_default') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="field-gantt_items_limit" class="block text-sm font-medium text-neutral-700">{{ __('ガントチャートの最大表示課題数(0で無制限)') }}</label>
                    <input id="field-gantt_items_limit" type="number" min="0" max="100000" wire:model="gantt_items_limit" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @error('gantt_items_limit') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="field-gantt_months_limit" class="block text-sm font-medium text-neutral-700">{{ __('ガントチャートの最大表示月数(0で無制限)') }}</label>
                    <input id="field-gantt_months_limit" type="number" min="0" max="1200" wire:model="gantt_months_limit" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @error('gantt_months_limit') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="space-y-4 border-t border-neutral-200 pt-6">
            <h2 class="text-sm font-semibold text-neutral-900">{{ __('プロジェクト') }}</h2>
            <p class="text-xs text-neutral-500">{{ __('新規プロジェクト作成フォームの初期値です。作成時にプロジェクトごと変更できます。') }}</p>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="default_projects_public" class="rounded border-neutral-300">
                {{ __('既定で公開プロジェクトにする') }}
            </label>

            <div>
                <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('既定で有効なモジュール') }}</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (\App\Enums\ProjectModuleKey::cases() as $module)
                        <label class="flex items-center gap-2 text-sm text-neutral-700">
                            <input type="checkbox" wire:model="default_projects_modules" value="{{ $module->value }}" class="rounded border-neutral-300">
                            {{ $module->value }}
                        </label>
                    @endforeach
                </div>
                @error('default_projects_modules.*') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('既定で使用するトラッカー(未選択の場合は全トラッカー)') }}</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($this->trackers as $tracker)
                        <label class="flex items-center gap-2 text-sm text-neutral-700">
                            <input type="checkbox" wire:model="default_projects_tracker_ids" value="{{ $tracker->id }}" class="rounded border-neutral-300">
                            {{ $tracker->name }}
                        </label>
                    @endforeach
                </div>
                @error('default_projects_tracker_ids.*') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="sequential_project_identifiers" class="rounded border-neutral-300">
                {{ __('識別子を自動的に連番採番する(識別子を空欄のまま保存した場合のみ)') }}
            </label>

            <div>
                <label for="field-new_project_user_role_id" class="block text-sm font-medium text-neutral-700">{{ __('新規プロジェクトの既定ロール') }}</label>
                <p class="text-xs text-neutral-500">{{ __('管理者以外がプロジェクト(サブプロジェクト)を作成した際に、作成者へ自動的に付与されるロールです。') }}</p>
                <select id="field-new_project_user_role_id" wire:model="new_project_user_role_id" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">---</option>
                    @foreach ($this->roles as $role)
                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                    @endforeach
                </select>
                @error('new_project_user_role_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <h3 class="border-t border-neutral-100 pt-4 text-sm font-semibold text-neutral-900">{{ __('プロジェクト一覧の既定') }}</h3>

            <div>
                <span class="block text-sm font-medium text-neutral-700">{{ __('プロジェクト一覧の表示形式') }}</span>
                <div class="mt-1 flex flex-wrap gap-4 text-sm text-neutral-700">
                    <label class="flex items-center gap-1.5">
                        <input type="radio" value="board" wire:model="project_list_display_type" class="border-neutral-300">
                        {{ __('ボード') }}
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input type="radio" value="list" wire:model="project_list_display_type" class="border-neutral-300">
                        {{ __('一覧') }}
                    </label>
                </div>
                @error('project_list_display_type') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-neutral-700">{{ __('プロジェクト一覧の初期表示列') }}</span>
                <div class="mt-1 flex flex-wrap gap-4 text-sm text-neutral-700">
                    @foreach (\App\Support\Query\ProjectFilterFieldRegistry::nativeColumns() as $key => $label)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" value="{{ $key }}" wire:model="project_list_default_columns" class="rounded border-neutral-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('project_list_default_columns') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-default_project_query" class="block text-sm font-medium text-neutral-700">{{ __('プロジェクト一覧の既定クエリ') }}</label>
                <select id="field-default_project_query" wire:model="default_project_query" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">{{ __('指定しない') }}</option>
                    @foreach (\App\Models\Query::query()->where('type', \App\Enums\QueryType::Project->value)->where('visibility', \App\Enums\QueryVisibility::Public->value)->whereNull('project_id')->orderBy('name')->get() as $query)
                        <option value="{{ $query->id }}">{{ $query->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-neutral-500">{{ __('個人設定の既定がないときに使われます。公開クエリのみ選べます。') }}</p>
                @error('default_project_query') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-neutral-200 pt-6">
            <h2 class="text-sm font-semibold text-neutral-900">{{ __('認証') }}</h2>

            <div>
                <label for="field-self_registration" class="block text-sm font-medium text-neutral-700">{{ __('アカウント登録') }}</label>
                <select id="field-self_registration" wire:model="self_registration" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="disabled">{{ __('無効(登録ページを表示しない)') }}</option>
                    <option value="manual">{{ __('管理者の承認が必要') }}</option>
                    <option value="email">{{ __('メールでの確認が必要') }}</option>
                    <option value="automatic">{{ __('自動的に有効化') }}</option>
                </select>
                @error('self_registration') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <label class="mt-2 flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="show_custom_fields_on_registration" @disabled($self_registration === 'disabled') class="rounded border-neutral-300">
                    {{ __('登録フォームにユーザーのカスタムフィールドを表示する(必須の項目は常に表示)') }}
                </label>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="unsubscribe" class="rounded border-neutral-300">
                    {{ __('ユーザーが自分自身でアカウントを削除できるようにする') }}
                </label>
                @error('unsubscribe') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-email_domains_allowed" class="block text-sm font-medium text-neutral-700">{{ __('登録を許可するメールドメイン(カンマ区切り、空欄は制限なし)') }}</label>
                <input id="field-email_domains_allowed" type="text" wire:model="email_domains_allowed" placeholder="{{ __('例: example.com, .example.org') }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('email_domains_allowed') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-email_domains_denied" class="block text-sm font-medium text-neutral-700">{{ __('登録を拒否するメールドメイン(カンマ区切り、許可リストより優先)') }}</label>
                <input id="field-email_domains_denied" type="text" wire:model="email_domains_denied" placeholder="{{ __('例: example.com, .example.org') }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('email_domains_denied') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('先頭に「.」を付けると、そのドメインとサブドメインすべてに一致します(例: .example.org)。自己登録時のみ適用され、管理者による直接のユーザー作成には適用されません。') }}
                </p>
            </div>

            <div>
                <label for="field-max_additional_emails" class="block text-sm font-medium text-neutral-700">{{ __('追加メールアドレスの上限数(1人あたり)') }}</label>
                <input id="field-max_additional_emails" type="number" min="0" max="50" wire:model="max_additional_emails" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{{ __('プロフィールで追加できるメールアドレスの数です。0にすると追加できません。追加したアドレスにも通知メールが届きます。') }}</p>
                @error('max_additional_emails') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-session_timeout" class="block text-sm font-medium text-neutral-700">{{ __('セッションタイムアウト') }}</label>
                <select id="field-session_timeout" wire:model="session_timeout" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="0">{{ __('無効') }}</option>
                    <option value="60">{{ __('1時間') }}</option>
                    <option value="120">{{ __('2時間') }}</option>
                    <option value="240">{{ __('4時間') }}</option>
                    <option value="480">{{ __('8時間') }}</option>
                    <option value="720">{{ __('12時間') }}</option>
                    <option value="1440">{{ __('24時間') }}</option>
                    <option value="2880">{{ __('48時間') }}</option>
                </select>
                @error('session_timeout') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">{{ __('この時間操作が無かったセッションは無効になり、再ログインが必要になります。') }}</p>
            </div>

            <div>
                <label for="field-session_lifetime" class="block text-sm font-medium text-neutral-700">{{ __('セッションの最大有効期間') }}</label>
                <select id="field-session_lifetime" wire:model="session_lifetime" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="0">{{ __('無効') }}</option>
                    <option value="240">{{ __('4時間') }}</option>
                    <option value="480">{{ __('8時間') }}</option>
                    <option value="720">{{ __('12時間') }}</option>
                    <option value="1440">{{ __('1日') }}</option>
                    <option value="10080">{{ __('7日') }}</option>
                    <option value="43200">{{ __('30日') }}</option>
                    <option value="86400">{{ __('60日') }}</option>
                    <option value="525600">{{ __('365日') }}</option>
                </select>
                @error('session_lifetime') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">{{ __('操作の有無にかかわらず、ログインからこの時間が経過したセッションは無効になり、再ログインが必要になります。') }}</p>
            </div>

            <div>
                <label for="field-password_min_length" class="block text-sm font-medium text-neutral-700">{{ __('パスワードの最小文字数') }}</label>
                <input id="field-password_min_length" type="number" wire:model="password_min_length" min="1" max="255"
                    class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('password_min_length') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('新規登録・管理者によるユーザー作成・パスワード変更のすべてに適用されます。') }}
                </p>
            </div>

            <div>
                <label for="field-password_max_age" class="block text-sm font-medium text-neutral-700">{{ __('パスワードの有効期限') }}</label>
                <select id="field-password_max_age" wire:model="password_max_age" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="0">{{ __('無効') }}</option>
                    @foreach ([7, 30, 60, 90, 180, 365] as $days)
                        <option value="{{ $days }}">{{ __(':days日', ['days' => $days]) }}</option>
                    @endforeach
                </select>
                @error('password_max_age') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">{{ __('最後にパスワードを変更してからこの日数が過ぎたローカルアカウントは、パスワードを変更するまでプロフィール以外のページを開けません。LDAPなど外部認証のアカウントは対象外です。') }}</p>
            </div>

            <div>
                <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('パスワードに必ず含める文字種') }}</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (RequiredPasswordCharacterClasses::labels() as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-neutral-700">
                            <input type="checkbox" wire:model="password_required_char_classes" value="{{ $key }}" class="rounded border-neutral-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-neutral-500">{{ __('選んだ文字種は、それぞれ1文字以上必要です。既存のパスワードは、次に変更するときから対象になります。') }}</p>
                @error('password_required_char_classes.*') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="lost_password" class="rounded border-neutral-300">
                    {{ __('ログインページに「パスワードをお忘れの場合」のリンクを表示し、本人によるパスワード再設定を許可する') }}
                </label>
                <p class="mt-1 text-xs text-neutral-500">{{ __('無効の場合、本人からの再設定リクエストは受け付けません(管理者がユーザー編集画面から送るリセットメールのリンクは引き続き有効です)。') }}</p>
            </div>

            <div>
                <label for="field-autologin" class="block text-sm font-medium text-neutral-700">{{ __('ログイン状態の保持') }}</label>
                <select id="field-autologin" wire:model="autologin" data-testid="autologin" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="0">{{ __('無効') }}</option>
                    <option value="1">{{ __(':days日', ['days' => 1]) }}</option>
                    <option value="7">{{ __(':days日', ['days' => 7]) }}</option>
                    <option value="30">{{ __(':days日', ['days' => 30]) }}</option>
                    <option value="365">{{ __(':days日', ['days' => 365]) }}</option>
                </select>
                @error('autologin') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">{{ __('無効の場合、ログインページに「ログイン状態を保持」チェックボックス自体が表示されず、ログインは常にセッションクッキー(ブラウザを閉じると失効)のみになります。有効の場合、保持したログインは選んだ日数が経つと失効します。') }}</p>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="rest_api_enabled" class="rounded border-neutral-300">
                    {{ __('REST APIを有効にする') }}
                </label>
                <p class="mt-1 text-xs text-neutral-500">{{ __('無効の場合、APIキー/OAuth2による認証を試みる前にすべてのAPIリクエストを拒否します(既定は無効、Redmine本家と同じ)。') }}</p>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="jsonp_enabled" class="rounded border-neutral-300">
                    {{ __('JSONPを有効にする') }}
                </label>
                <p class="mt-1 text-xs text-neutral-500">{!! __('GETで:callbackを付けると、JSONを関数呼び出しにして返します。他サイトのページからAPIキー付きのURLを読めるようになるため、セキュリティ上のリスクがあります(既定は無効)。', ['callback' => '<code>callback</code>']) !!}</p>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="login_required" class="rounded border-neutral-300">
                    {{ __('全ページにログインを必要とする') }}
                </label>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('無効にすると、未ログインのユーザーでも公開プロジェクトの課題一覧・課題詳細・Wikiページ・添付ファイルを閲覧できるようになります(それ以外の操作・非公開プロジェクトは引き続きログインが必要です)。既定は有効(本アプリの従来の挙動を維持)。') }}
                </p>
            </div>

            <div>
                <label for="field-twofa" class="block text-sm font-medium text-neutral-700">{{ __('二要素認証') }}</label>
                <select id="field-twofa" wire:model="twofa" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="0">{{ __('無効') }}</option>
                    <option value="1">{{ __('任意(ユーザーが選択可能)') }}</option>
                    <option value="2">{{ __('全ユーザーに必須') }}</option>
                    <option value="3">{{ __('管理者のみ必須') }}</option>
                </select>
                @error('twofa') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('「必須」に設定すると、対象ユーザーは二要素認証を設定するまでアカウント設定ページ以外にアクセスできなくなります(この設定が「任意」または「管理者のみ必須」のときは、グループ編集画面で「このグループのメンバーに必須」を指定することもできます)。') }}
                </p>
            </div>
        </section>

        <section class="space-y-4 border-t border-neutral-200 pt-6">
            <h2 class="text-sm font-semibold text-neutral-900">{{ __('メール通知') }}</h2>

            <div>
                <label class="block text-sm font-medium text-neutral-700">{{ __('通知するイベント') }}</label>
                <div class="mt-1 space-y-1">
                    @foreach (self::notifiedEvents() as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-neutral-700">
                            <input type="checkbox" value="{{ $key }}" wire:model="notified_events" class="rounded border-neutral-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('notified_events') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('ここで無効にしたイベントは、各ユーザーの通知設定に関わらず一切メール送信されません。お知らせ/Wikiのメール通知は今後の対応予定です。') }}
                </p>
            </div>

            <div>
                <label for="field-mail_from" class="block text-sm font-medium text-neutral-700">{{ __('送信元メールアドレス(空欄で環境設定の既定値を使用)') }}</label>
                <input id="field-mail_from" type="email" wire:model="mail_from" placeholder="{{ config('mail.from.address') }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('mail_from') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="plain_text_mail" class="rounded border-neutral-300">
                    {{ __('テキスト形式のみで送信する(HTML形式を含めない)') }}
                </label>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="default_users_no_self_notified" class="rounded border-neutral-300">
                    {{ __('新規ユーザーの既定で、自分自身が行った変更については通知メールを送信しない') }}
                </label>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('ここでの設定は新規ユーザー作成時の初期値です。各ユーザーはプロフィール画面で個別に変更できます。') }}
                </p>
            </div>

            <div>
                <label for="field-default_notification_option" class="block text-sm font-medium text-neutral-700">{{ __('新規ユーザーの既定のメール通知') }}</label>
                <select id="field-default_notification_option" wire:model="default_notification_option" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @foreach (MailNotificationOption::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @error('default_notification_option') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('ここでの設定は新規ユーザー作成時の初期値です。各ユーザーはプロフィール画面で個別に変更できます。') }}
                </p>
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="show_status_changes_in_mail_subject" class="rounded border-neutral-300">
                {{ __('通知メールの件名にステータスの変更を含める') }}
            </label>

            <div>
                <label for="field-emails_header" class="block text-sm font-medium text-neutral-700">{{ __('通知メールのヘッダ') }}</label>
                <textarea id="field-emails_header" wire:model="emails_header" rows="2" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm"></textarea>
                @error('emails_header') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-emails_footer" class="block text-sm font-medium text-neutral-700">{{ __('通知メールの署名') }}</label>
                <textarea id="field-emails_footer" wire:model="emails_footer" rows="2" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm"></textarea>
                @error('emails_footer') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-neutral-200 pt-6">
            <h2 class="text-sm font-semibold text-neutral-900">{{ __('工数管理') }}</h2>

            <div>
                <span class="block text-sm font-medium text-neutral-700">{{ __('必須にする項目') }}</span>
                <div class="mt-1 flex gap-4 text-sm text-neutral-700">
                    @foreach (\App\Support\TimeLog\TimeLogConstraints::requirableFieldLabels() as $field => $label)
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" value="{{ $field }}" wire:model="timelog_required_fields" class="rounded border-neutral-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('timelog_required_fields') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-timelog_max_hours_per_day" class="block text-sm font-medium text-neutral-700">{{ __('1日あたりの最大工数(時間、0で無制限)') }}</label>
                <input id="field-timelog_max_hours_per_day" type="number" step="0.01" min="0" max="1000" wire:model="timelog_max_hours_per_day" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{{ __('同じユーザーが同じ日に記録できる工数の合計の上限です。') }}</p>
                @error('timelog_max_hours_per_day') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="timelog_accept_0_hours" class="rounded border-neutral-300">
                {{ __('0時間の記録を許可する') }}
            </label>
            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="timelog_accept_future_dates" class="rounded border-neutral-300">
                {{ __('未来の日付への記録を許可する') }}
            </label>
            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="timelog_accept_closed_issues" class="rounded border-neutral-300">
                {{ __('終了した課題への記録を許可する') }}
            </label>
        </section>

        <section class="space-y-4 border-t border-neutral-200 pt-6">
            <h2 class="text-sm font-semibold text-neutral-900">{{ __('添付ファイル') }}</h2>

            <div>
                <label for="field-attachment_max_size" class="block text-sm font-medium text-neutral-700">{{ __('最大アップロードサイズ(KB)') }}</label>
                <input id="field-attachment_max_size" type="number" wire:model="attachment_max_size" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('attachment_max_size') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-bulk_download_max_size" class="block text-sm font-medium text-neutral-700">{{ __('まとめてダウンロードできる合計サイズ(KB、0で無制限)') }}</label>
                <input id="field-bulk_download_max_size" type="number" min="0" wire:model="bulk_download_max_size" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('bulk_download_max_size') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-attachment_extensions_allowed" class="block text-sm font-medium text-neutral-700">{{ __('許可する拡張子(カンマ区切り、空欄は制限なし)') }}</label>
                <input id="field-attachment_extensions_allowed" type="text" wire:model="attachment_extensions_allowed" placeholder="{{ __('例: png, jpg, pdf') }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('attachment_extensions_allowed') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-attachment_extensions_denied" class="block text-sm font-medium text-neutral-700">{{ __('禁止する拡張子(カンマ区切り、許可リストが設定されている場合は無視)') }}</label>
                <input id="field-attachment_extensions_denied" type="text" wire:model="attachment_extensions_denied" placeholder="{{ __('例: exe, sh') }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('attachment_extensions_denied') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-neutral-200 pt-6">
            <h2 class="text-sm font-semibold text-neutral-900">{{ __('メール受信による課題作成') }}</h2>
            <p class="text-xs text-neutral-500">
                {{ __('接続先メールサーバーは環境変数(IMAP_HOST等)で設定します。ここでは課題の作成先を設定します。') }}
            </p>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="incoming_mail_enabled" class="rounded border-neutral-300">
                {{ __('有効にする') }}
            </label>

            <div>
                <label for="field-incoming_mail_default_project_id" class="block text-sm font-medium text-neutral-700">
                    {!! __('既定のプロジェクト(件名が :identifier で始まらない場合に使用)', ['identifier' => '<code>['.e(__('識別子')).']</code>']) !!}
                </label>
                <select id="field-incoming_mail_default_project_id" wire:model="incoming_mail_default_project_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">{{ __('選択してください') }}</option>
                    @foreach ($this->projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
                @error('incoming_mail_default_project_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-incoming_mail_default_tracker_id" class="block text-sm font-medium text-neutral-700">{{ __('既定のトラッカー') }}</label>
                <select id="field-incoming_mail_default_tracker_id" wire:model="incoming_mail_default_tracker_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">{{ __('選択してください') }}</option>
                    @foreach ($this->trackers as $tracker)
                        <option value="{{ $tracker->id }}">{{ $tracker->name }}</option>
                    @endforeach
                </select>
                @error('incoming_mail_default_tracker_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-incoming_mail_default_status_id" class="block text-sm font-medium text-neutral-700">{{ __('既定のステータス') }}</label>
                <select id="field-incoming_mail_default_status_id" wire:model="incoming_mail_default_status_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">{{ __('選択してください') }}</option>
                    @foreach ($this->statuses as $status)
                        <option value="{{ $status->id }}">{{ $status->name }}</option>
                    @endforeach
                </select>
                @error('incoming_mail_default_status_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-mail_handler_preferred_body_part" class="block text-sm font-medium text-neutral-700">{{ __('本文の取得優先形式') }}</label>
                <select id="field-mail_handler_preferred_body_part" wire:model="mail_handler_preferred_body_part" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="plain">{{ __('プレーンテキスト優先') }}</option>
                    <option value="html">{{ __('HTML優先(プレーンテキスト化して使用)') }}</option>
                </select>
                @error('mail_handler_preferred_body_part') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-mail_handler_body_delimiters" class="block text-sm font-medium text-neutral-700">{{ __('本文の切り捨て行(1行に1つ、この行に完全一致した箇所以降を切り捨て)') }}</label>
                <textarea id="field-mail_handler_body_delimiters" wire:model="mail_handler_body_delimiters" rows="2" placeholder="{{ __('例: -----Original Message-----') }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm"></textarea>
                @error('mail_handler_body_delimiters') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="mail_handler_enable_regex_delimiters" class="rounded border-neutral-300">
                {{ __('切り捨て行を正規表現として扱う') }}
            </label>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="mail_handler_enable_regex_excluded_filenames" class="rounded border-neutral-300">
                {{ __('除外する添付ファイル名を正規表現として扱う(オフのときはワイルドカード)') }}
            </label>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="mail_handler_api_enabled" class="rounded border-neutral-300">
                    {{ __('メール受信用のWebサービスを有効にする') }}
                </label>
                <div class="mt-2 flex items-center gap-2">
                    <input type="text" wire:model="mail_handler_api_key" placeholder="{{ __('APIキー') }}" autocomplete="off"
                        class="block w-full max-w-md rounded-md border-neutral-300 font-mono shadow-sm sm:text-sm">
                    <button type="button" wire:click="generateMailHandlerApiKey" class="shrink-0 text-sm text-brand-bold hover:underline">{{ __('キーを生成') }}</button>
                </div>
                <p class="mt-1 text-xs text-neutral-500">{!! __(':endpoint に :key と生メール本文 :email を送ると、IMAP/POP の受信と同じ処理をします(メールサーバーのパイプ用)。', ['endpoint' => '<code>POST /mail_handler</code>', 'key' => '<code>key</code>', 'email' => '<code>email</code>']) !!}</p>
                @error('mail_handler_api_key') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-mail_handler_excluded_filenames" class="block text-sm font-medium text-neutral-700">{{ __('除外する添付ファイル名(カンマ区切り、ワイルドカード可)') }}</label>
                <input id="field-mail_handler_excluded_filenames" type="text" wire:model="mail_handler_excluded_filenames" placeholder="{{ __('例: *.ics, winmail.dat') }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('mail_handler_excluded_filenames') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="mail_handler_no_notification" class="rounded border-neutral-300">
                {{ __('受信メールで作成・更新した課題は通知メールを送らない') }}
            </label>

            <div>
                <label for="field-mail_handler_allow_override" class="block text-sm font-medium text-neutral-700">{{ __('本文のキーワードで上書きを許す項目(カンマ区切り)') }}</label>
                <input id="field-mail_handler_allow_override" type="text" wire:model="mail_handler_allow_override" placeholder="all"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{!! __(':all はすべて許可。例: :example(項目: status, priority, assigned_to, done_ratio, tracker, category, fixed_version, start_date, due_date, estimated_hours, private, parent_issue)。カスタムフィールドは名前で指定します(小文字、空白は _)。', ['all' => '<code>all</code>', 'example' => '<code>status, priority, assigned_to</code>']) !!}</p>
                @error('mail_handler_allow_override') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-mail_handler_project_from_subaddress" class="block text-sm font-medium text-neutral-700">{{ __('サブアドレスからプロジェクトを決める(受信アドレス)') }}</label>
                <input id="field-mail_handler_project_from_subaddress" type="text" wire:model="mail_handler_project_from_subaddress" placeholder="redmine@example.net"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{!! __('設定すると :address 宛のメールがその識別子のプロジェクトの課題になります(件名の :subject より優先)。', ['address' => '<code>redmine+'.e(__('識別子')).'@example.net</code>', 'subject' => '<code>['.e(__('識別子')).']</code>']) !!}</p>
                @error('mail_handler_project_from_subaddress') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 border-t border-neutral-200 pt-6">
            <h2 class="text-sm font-semibold text-neutral-900">{{ __('リポジトリ') }}</h2>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="autofetch_changesets" class="rounded border-neutral-300">
                {{ __('コミットを定期的に自動取得する(15分ごと)') }}
            </label>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="commit_logtime_enabled" class="rounded border-neutral-300">
                {!! __('コミットメッセージの :syntax 形式で工数を自動記録する', ['syntax' => '<code>#123 @2h</code>']) !!}
            </label>

            <div>
                <label for="field-commit_logtime_activity_id" class="block text-sm font-medium text-neutral-700">{{ __('自動記録に使う作業分類(未選択の場合は既定の作業分類)') }}</label>
                <select id="field-commit_logtime_activity_id" wire:model="commit_logtime_activity_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">{{ __('選択してください') }}</option>
                    @foreach ($this->activities as $activity)
                        <option value="{{ $activity->id }}">{{ $activity->name }}</option>
                    @endforeach
                </select>
                @error('commit_logtime_activity_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-neutral-700 mb-2">{{ __('有効なリポジトリ種別') }}</span>
                <div class="flex gap-4">
                    @foreach (\App\Enums\RepositoryType::cases() as $case)
                        <label class="flex items-center gap-2 text-sm text-neutral-700">
                            <input type="checkbox" wire:model="enabled_scm_types" value="{{ $case->value }}" class="rounded border-neutral-300">
                            {{ $case->value }}
                        </label>
                    @endforeach
                </div>
                @error('enabled_scm_types') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                @error('enabled_scm_types.*') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="sys_api_enabled" class="rounded border-neutral-300">
                    {{ __('リポジトリ管理用WebサービスのAPIを有効にする') }}
                </label>
                <div class="mt-2 flex items-center gap-2">
                    <input type="text" wire:model="sys_api_key" placeholder="{{ __('APIキー') }}" autocomplete="off"
                        class="block w-full max-w-md rounded-md border-neutral-300 font-mono shadow-sm sm:text-sm">
                    <button type="button" wire:click="generateSysApiKey" class="shrink-0 text-sm text-brand-bold hover:underline">{{ __('キーを生成') }}</button>
                </div>
                <p class="mt-1 text-xs text-neutral-500">{!! __(':projects と :fetch を :key パラメータ付きで呼び出せます(post-receive フック用)。', ['projects' => '<code>GET /sys/projects</code>', 'fetch' => '<code>/sys/fetch_changesets?id=&lt;'.e(__('プロジェクト')).'&gt;</code>', 'key' => '<code>key</code>']) !!}</p>
                @error('sys_api_key') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-repository_log_display_limit" class="block text-sm font-medium text-neutral-700">{{ __('履歴に表示するリビジョン数') }}</label>
                <input id="field-repository_log_display_limit" type="number" min="1" max="1000" wire:model="repository_log_display_limit" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('repository_log_display_limit') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="field-diff_max_lines_displayed" class="block text-sm font-medium text-neutral-700">{{ __('差分の最大表示行数(0で無制限)') }}</label>
                    <input id="field-diff_max_lines_displayed" type="number" min="0" max="100000" wire:model="diff_max_lines_displayed" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @error('diff_max_lines_displayed') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="field-file_max_size_displayed" class="block text-sm font-medium text-neutral-700">{{ __('ファイルの最大表示サイズ(KB、0で無制限)') }}</label>
                    <input id="field-file_max_size_displayed" type="number" min="0" max="102400" wire:model="file_max_size_displayed" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @error('file_max_size_displayed') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-neutral-700">
                    <input type="checkbox" wire:model="thumbnails_enabled" data-testid="thumbnails-enabled" class="rounded border-neutral-300">
                    {{ __('添付ファイルの一覧・Wikiなどにサムネイルを表示する') }}
                </label>
                @error('thumbnails_enabled') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-thumbnails_size" class="block text-sm font-medium text-neutral-700">{{ __('サムネイルの大きさ(px)') }}</label>
                <input id="field-thumbnails_size" type="number" min="16" max="2000" wire:model="thumbnails_size" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{{ __('この後にアップロードされる画像から適用されます。') }}</p>
                @error('thumbnails_size') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-repositories_encodings" class="block text-sm font-medium text-neutral-700">{{ __('ファイル内容のエンコーディング候補(カンマ区切り)') }}</label>
                <input id="field-repositories_encodings" type="text" wire:model="repositories_encodings" placeholder="{{ __('例: SJIS-win, EUC-JP') }}"
                    class="mt-1 block w-full max-w-md rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{{ __('UTF-8でないファイルやログを、ここに並べた順に試してUTF-8へ変換して表示します。') }}</p>
                @error('repositories_encodings') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="field-commit_logs_encoding" class="block text-sm font-medium text-neutral-700">{{ __('コミットログのエンコーディング') }}</label>
                <input id="field-commit_logs_encoding" type="text" wire:model="commit_logs_encoding" class="mt-1 block w-full max-w-xs rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('commit_logs_encoding') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="commit_logs_formatting" class="rounded border-neutral-300">
                {{ __('コミットログをMarkdownで整形して表示する') }}
            </label>

            <div>
                <label for="field-commit_ref_keywords" class="block text-sm font-medium text-neutral-700">{{ __('課題を参照するキーワード(カンマ区切り)') }}</label>
                <input id="field-commit_ref_keywords" type="text" wire:model="commit_ref_keywords" placeholder="*"
                    class="mt-1 block w-full max-w-md rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <p class="mt-1 text-xs text-neutral-500">{!! __(':wildcardを含めると、キーワードなしの:issueもコミットに関連付けられます(Redmineの既定は refs,references,IssueID)。', ['wildcard' => '<code>*</code>', 'issue' => '<code>#123</code>']) !!}</p>
                @error('commit_ref_keywords') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="commit_cross_project_ref" class="rounded border-neutral-300">
                {{ __('他のプロジェクトの課題も参照・更新できるようにする') }}
            </label>

            <div>
                <span class="block text-sm font-medium text-neutral-700 mb-1">{{ __('コミットをステータス変更と結び付けるキーワード') }}</span>
                <p class="mb-2 text-xs text-neutral-500">
                    {!! __('各行はキーワード(カンマ区切りで複数指定可)と、コミットメッセージ内でその語の直後に:issueがあった場合の変更先ステータスの組です。行を削除するとそのキーワードは無効になります。', ['issue' => '<code>#123</code>']) !!}
                </p>
                <div class="space-y-2">
                    @foreach ($commit_fixing_keyword_rules as $index => $rule)
                        <div class="flex items-start gap-2" wire:key="fixing-keyword-rule-{{ $index }}">
                            <div class="flex-1">
                                <input type="text" wire:model="commit_fixing_keyword_rules.{{ $index }}.keywords" placeholder="{{ __('例: fixes, fix, closes, close') }}"
                                    class="block w-full rounded-md border-neutral-300 text-sm shadow-sm">
                                @error("commit_fixing_keyword_rules.{$index}.keywords") <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                            </div>
                            <div class="w-48">
                                <select wire:model="commit_fixing_keyword_rules.{{ $index }}.status_id" class="block w-full rounded-md border-neutral-300 text-sm shadow-sm">
                                    <option value="">{{ __('変更先ステータス') }}</option>
                                    @foreach ($this->statuses as $status)
                                        <option value="{{ $status->id }}">{{ $status->name }}</option>
                                    @endforeach
                                </select>
                                @error("commit_fixing_keyword_rules.{$index}.status_id") <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                            </div>
                            <div class="w-24">
                                <input type="number" min="0" max="100" step="10" wire:model="commit_fixing_keyword_rules.{{ $index }}.done_ratio" placeholder="{{ __('進捗%') }}"
                                    class="block w-full rounded-md border-neutral-300 text-sm shadow-sm">
                                @error("commit_fixing_keyword_rules.{$index}.done_ratio") <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                            </div>
                            <div class="w-36">
                                <select wire:model="commit_fixing_keyword_rules.{{ $index }}.if_tracker_id" class="block w-full rounded-md border-neutral-300 text-sm shadow-sm">
                                    <option value="">{{ __('全トラッカー') }}</option>
                                    @foreach ($this->trackers as $tracker)
                                        <option value="{{ $tracker->id }}">{{ $tracker->name }}</option>
                                    @endforeach
                                </select>
                                @error("commit_fixing_keyword_rules.{$index}.if_tracker_id") <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="removeFixingKeywordRule({{ $index }})" class="mt-1.5 shrink-0 text-sm text-danger-bolder hover:underline">
                                {{ __('削除') }}
                            </button>
                        </div>
                    @endforeach
                </div>
                <button type="button" wire:click="addFixingKeywordRule" class="mt-2 text-sm text-brand-bold hover:underline">
                    {{ __('+ 行を追加') }}
                </button>
            </div>
        </section>

        <button type="submit" class="btn btn-primary">
            {{ __('保存') }}
        </button>
    </form>
</div>
