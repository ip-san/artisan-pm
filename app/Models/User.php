<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasCustomFields;
use App\CustomFields\Formats\AttachmentFormat;
use App\Enums\CustomizableType;
use App\Enums\MailNotificationOption;
use App\Enums\UserStatus;
use App\Support\Authorization\AuthorizationService;
use App\Support\Locale\SupportedLocales;
use App\Support\Preferences\UserPreferences;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * is_admin is deliberately excluded from Fillable — every current
 * User::create()/update() call site already passes an explicit attribute
 * array rather than raw request input, so mass-assigning it here wouldn't
 * be exploitable today, but keeping a privilege-granting column out of
 * the mass-assignable set entirely means a future call site that isn't as
 * careful can't turn into a privilege-escalation path. The admin user
 * form (resources/views/livewire/users/form.blade.php) sets it via a
 * direct property assignment instead.
 */
#[Fillable(['name', 'firstname', 'lastname', 'email', 'password', 'language', 'time_zone', 'auth_source_id', 'login', 'status', 'mail_notification', 'no_self_notified'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'api_key', 'atom_key'])]
final class User extends Authenticatable implements HasLocalePreference, HasMedia, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasCustomFields, HasFactory, InteractsWithMedia, Notifiable, TwoFactorAuthenticatable;

    /**
     * Matches Redmine's User::LOGIN_LENGTH_LIMIT and login format
     * validation (`/\A[a-z0-9_\-@.]*\z/i`, user.rb) — the single source of
     * truth for all three login-writing paths (self-registration, admin
     * form, on-the-fly LDAP provisioning), so a directory uid can't slip in
     * a value the two user-facing forms would have rejected.
     */
    public const LOGIN_LENGTH_LIMIT = 60;

    // The D modifier makes `$` behave like Redmine's `\z` (rejects a
    // trailing newline) instead of PHP's default `$`, which would
    // otherwise allow one.
    public const LOGIN_FORMAT_REGEX = '/^[a-zA-Z0-9_\-@.]+$/D';

    /**
     * Eloquent doesn't read back server-side column defaults on a freshly
     * created (unrefreshed) model — same issue Tracker::$attributes
     * already works around for its own defaulted columns — so a
     * just-created User's in-memory no_self_notified would otherwise be
     * null even though the `users` table default is true. mail_notification
     * is deliberately NOT defaulted here — see booted()'s creating() hook,
     * which seeds it from the admin-configurable default_notification_option
     * setting instead of a hardcoded value.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'no_self_notified' => true,
        'must_change_passwd' => false,
    ];

    /**
     * Matches Redmine's User#set_mail_notification (a before_create
     * callback): every creation path gets the site's configured default
     * unless the caller explicitly set one, rather than requiring each of
     * this app's several User::create() call sites (self-registration,
     * admin-created, on-the-fly LDAP provisioning, factories) to remember
     * to pass it — the exact class of gap an admin default was found
     * missing from previously (no_self_notified's seeding).
     */
    protected static function booted(): void
    {
        // Redmine's salt_password: any change of the password starts its age.
        self::saving(function (User $user): void {
            if ($user->isDirty('password')) {
                $user->passwd_changed_on = now();
            }
        });

        // Option A of docs/design/gap-A4-10b.md: `name` stays the stored name
        // every reader (lists, search, sort, API, CSV) uses, so when both
        // parts are entered it follows them in Redmine's default format.
        self::saving(function (User $user): void {
            if ($user->hasNameParts()) {
                $user->name = $user->displayName('firstname_lastname');
            }
        });

        self::creating(function (User $user): void {
            if (! array_key_exists('mail_notification', $user->getAttributes())) {
                $user->mail_notification = Setting::get('default_notification_option', 'only_assigned');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'status' => UserStatus::class,
            'mail_notification' => MailNotificationOption::class,
            'no_self_notified' => 'boolean',
            'passwd_changed_on' => 'datetime',
            'must_change_passwd' => 'boolean',
            'preferences' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AuthSource, $this>
     */
    public function authSource(): BelongsTo
    {
        return $this->belongsTo(AuthSource::class);
    }

    /**
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    /**
     * The extra addresses the user added (the primary one is `email`).
     *
     * @return HasMany<EmailAddress, $this>
     */
    public function additionalEmails(): HasMany
    {
        return $this->hasMany(EmailAddress::class)->orderBy('id');
    }

    /**
     * Where a notification mail goes: the primary address plus every
     * additional one left switched on, like Redmine's `user.mails`.
     *
     * @return array<int, string>
     */
    public function routeNotificationForMail(): array
    {
        return [
            $this->email,
            ...$this->additionalEmails()->where('notify', true)->pluck('address')->all(),
        ];
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'members')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function bookmarkedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_bookmarks')
            ->withTimestamps();
    }

    /**
     * The projects this user chose to hear about when their mail
     * notification setting is `selected` (Redmine's notified_project_ids).
     *
     * @return array<int, int>
     */
    public function notifiedProjectIds(): array
    {
        return $this->memberships()->where('mail_notification', true)->pluck('project_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Replaces the chosen projects; only the user's own memberships can be
     * chosen, and an empty list clears every one.
     *
     * @param  array<int, int|string>  $projectIds
     */
    public function setNotifiedProjectIds(array $projectIds): void
    {
        $projectIds = array_map('intval', $projectIds);

        $this->memberships()->update(['mail_notification' => false]);

        if ($projectIds !== []) {
            $this->memberships()->whereIn('project_id', $projectIds)->update(['mail_notification' => true]);
        }
    }

    /**
     * The `user_format` values: this app's `name` (the default) and
     * `name_login`, then Redmine's User::USER_FORMATS in its setting order
     * (`username` is this app's `login`).
     */
    public const USER_FORMATS = [
        'name', 'name_login', 'firstname_lastname', 'firstname_lastinitial', 'firstinitial_lastname',
        'firstname', 'lastname_firstname', 'lastnamefirstname', 'lastname_comma_firstname', 'lastname', 'login',
    ];

    /**
     * The settings screen's label for each `user_format`.
     *
     * @return array<string, string>
     */
    public static function userFormatLabels(): array
    {
        return [
            'name' => __('名前'),
            'name_login' => __('名前 (ログインID)'),
            'firstname_lastname' => __('名 姓'),
            'firstname_lastinitial' => __('名 姓の頭文字.'),
            'firstinitial_lastname' => __('名の頭文字. 姓'),
            'firstname' => __('名'),
            'lastname_firstname' => __('姓 名'),
            'lastnamefirstname' => __('姓名'),
            'lastname_comma_firstname' => __('姓, 名'),
            'lastname' => __('姓'),
            'login' => __('ログインID'),
        ];
    }

    /**
     * Validation of the name inputs shared by the admin form, the account
     * page, registration, the REST API and the CSV import: `name` is needed
     * unless both parts are given (then it is derived), and a part needs the
     * other (Redmine requires both; here both or neither). For a partial
     * update (`$nameRequired` false, the REST API's PUT) every field is
     * optional and a part may change alone.
     *
     * @return array<string, array<int, string>>
     */
    public static function nameRules(bool $nameRequired = true): array
    {
        return [
            'name' => [$nameRequired ? 'required_without_all:firstname,lastname' : 'sometimes', 'nullable', 'string', 'max:255'],
            'firstname' => $nameRequired ? ['nullable', 'string', 'max:30', 'required_with:lastname'] : ['sometimes', 'nullable', 'string', 'max:30'],
            'lastname' => $nameRequired ? ['nullable', 'string', 'max:255', 'required_with:firstname'] : ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Blank name parts are stored as null, and a blank `name` is left out so
     * the saving hook derives it from the parts.
     *
     * @param  array<string, mixed>  $data
     * @return array<model-property<User>, mixed>
     */
    public static function normalizeNameInput(array $data): array
    {
        foreach (['firstname', 'lastname'] as $part) {
            if (array_key_exists($part, $data)) {
                $data[$part] = filled($data[$part]) ? trim((string) $data[$part]) : null;
            }
        }

        if (array_key_exists('name', $data) && blank($data['name'])) {
            unset($data['name']);
        }

        return $data;
    }

    /**
     * The name columns users are ordered by in the site's `user_format`,
     * like Redmine's User.fields_for_order_statement (`lastname_*` formats:
     * last name then first name). The first/last name formats need both
     * parts ({@see self::hasNameParts()}), so a user without them sorts by
     * `name`, the same fallback {@see self::displayName()} applies.
     *
     * @return list<string>
     */
    public static function orderFieldsForFormat(?string $format = null): array
    {
        $format ??= (string) Setting::get('user_format', 'name');

        return match ($format) {
            'firstname_lastname', 'firstname_lastinitial', 'firstinitial_lastname' => ['firstname', 'lastname'],
            'firstname' => ['firstname'],
            'lastname_firstname', 'lastnamefirstname', 'lastname_comma_firstname' => ['lastname', 'firstname'],
            'lastname' => ['lastname'],
            'login' => ['login'],
            default => ['name'],
        };
    }

    /**
     * Users whose name, login or an email address contains $term, or whose
     * first or last name contains each of its words — Redmine's
     * Principal.like, the `name` filter of GET /users. Case-insensitive.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMatchingName(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = fn (string $value) => '%'.addcslashes(mb_strtolower($value), '\\%_').'%';
        $pattern = $like($term);
        $tokens = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $query->where(function (Builder $query) use ($pattern, $tokens, $like): void {
            $query->whereRaw('LOWER(users.name) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(users.login) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(users.email) LIKE ?', [$pattern])
                ->orWhereIn('users.id', EmailAddress::query()->select('user_id')->whereRaw('LOWER(address) LIKE ?', [$pattern]))
                ->orWhere(function (Builder $query) use ($tokens, $like): void {
                    foreach ($tokens as $token) {
                        $query->where(fn (Builder $query) => $query
                            ->whereRaw('LOWER(users.firstname) LIKE ?', [$like($token)])
                            ->orWhereRaw('LOWER(users.lastname) LIKE ?', [$like($token)]));
                    }
                });
        });
    }

    /**
     * Orders users by {@see self::orderFieldsForFormat()} (case-insensitively),
     * then id.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSortedByFormat(Builder $query): Builder
    {
        $table = $this->getTable();
        $hasParts = "(COALESCE({$table}.firstname, '') <> '' AND COALESCE({$table}.lastname, '') <> '')";

        foreach (self::orderFieldsForFormat() as $position => $field) {
            $expression = match ($field) {
                'name' => "{$table}.name",
                'login' => "COALESCE(NULLIF({$table}.login, ''), {$table}.name)",
                // Without both parts the user is named (and sorted) by
                // `name`; only the first field falls back, the second is empty.
                default => $position === 0
                    ? "CASE WHEN {$hasParts} THEN {$table}.{$field} ELSE {$table}.name END"
                    : "CASE WHEN {$hasParts} THEN {$table}.{$field} ELSE '' END",
            };

            $query->orderByRaw("LOWER({$expression})");
        }

        return $query->orderBy("{$table}.id");
    }

    /**
     * $users in the same order as {@see self::scopeSortedByFormat()}, for
     * lists assembled in memory (Redmine's assignable_users are sorted by
     * their displayed name as well).
     *
     * @template TKey of array-key
     *
     * @param  iterable<TKey, User>  $users
     * @return Collection<int, User>
     */
    public static function sortByFormat(iterable $users): Collection
    {
        $fields = self::orderFieldsForFormat();

        return collect($users)
            ->sortBy(fn (User $user) => [...array_map(fn (string $field, int $position) => $user->sortValue($field, $position), $fields, array_keys($fields)), $user->id])
            ->values();
    }

    private function sortValue(string $field, int $position): string
    {
        $value = match ($field) {
            'name' => (string) $this->name,
            'login' => filled($this->login) ? (string) $this->login : (string) $this->name,
            default => $this->hasNameParts() ? (string) $this->{$field} : ($position === 0 ? (string) $this->name : ''),
        };

        return mb_strtolower($value);
    }

    /**
     * Select options for users, labelled and ordered in the site's
     * `user_format` (Redmine's principals_options_for_select uses User#name
     * too, over lists sorted by it).
     *
     * @param  iterable<User>  $users
     * @return array<int, string>
     */
    public static function nameOptions(iterable $users): array
    {
        $options = [];

        foreach (self::sortByFormat($users) as $user) {
            $options[$user->id] = $user->displayName();
        }

        return $options;
    }

    /**
     * Whether both name parts are entered — only then do the first/last
     * name formats apply (docs/design/gap-A4-10b.md, option A).
     */
    public function hasNameParts(): bool
    {
        return filled($this->firstname) && filled($this->lastname);
    }

    /**
     * How the user is named on screen, per the site's `user_format` (Redmine's
     * User#name), or per `$format` when given. The first/last name formats
     * fall back to `name` for a user without both parts; the login formats
     * fall back to it without a login.
     */
    public function displayName(?string $format = null): string
    {
        $format ??= (string) Setting::get('user_format', 'name');

        if (! in_array($format, ['name', 'name_login', 'login'], true) && ! $this->hasNameParts()) {
            $format = 'name';
        }

        $first = (string) $this->firstname;
        $last = (string) $this->lastname;

        return match ($format) {
            'name_login' => filled($this->login) ? "{$this->name} ({$this->login})" : (string) $this->name,
            'login' => filled($this->login) ? $this->login : (string) $this->name,
            'firstname_lastname' => "{$first} {$last}",
            'firstname_lastinitial' => $first.' '.mb_substr($last, 0, 1).'.',
            'firstinitial_lastname' => preg_replace('/(\p{L})\p{L}*\.?/u', '$1.', $first).' '.$last,
            'firstname' => $first,
            'lastname_firstname' => "{$last} {$first}",
            'lastnamefirstname' => $last.$first,
            'lastname_comma_firstname' => "{$last}, {$first}",
            'lastname' => $last,
            default => (string) $this->name,
        };
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Redmine only sends a lost-password mail to an active user whose
     * password is managed locally. Skipping silently (rather than
     * failing) gives a locked or LDAP-backed account the same response as
     * an ordinary one, so the endpoint doesn't additionally reveal an
     * account's state (an unregistered address still gets Fortify's
     * "no such user" error, as it does in Redmine).
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if (! $this->isActive() || $this->auth_source_id !== null) {
            return;
        }

        $this->notify(new ResetPassword($token));
    }

    /**
     * Matches Redmine's Principal.visible scope: restricts $query to users
     * $viewer is actually allowed to search/see (per
     * Role.users_visibility), unless $viewer holds site-wide visibility —
     * see AuthorizationService::hasSiteWideUserVisibility()/
     * visibleProjectIds() for the underlying rule. This is the single
     * enforcement point for that restriction; every read path that lets a
     * non-admin resolve an arbitrary user by id (not just the ones that
     * render a search dropdown) must go through it, not just the query
     * that populates the dropdown — a dropdown filter alone doesn't stop
     * a handler that accepts a raw id from echoing back a name/email
     * outside the visible set.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?self $viewer): Builder
    {
        $authorization = app(AuthorizationService::class);

        if ($authorization->hasSiteWideUserVisibility($viewer)) {
            return $query;
        }

        $visibleProjectIds = $authorization->visibleProjectIds($viewer);

        return $query->where(function ($q) use ($viewer, $visibleProjectIds) {
            if ($viewer !== null) {
                $q->where('id', $viewer->id);
            }

            $q->orWhereHas('memberships', fn ($m) => $m->whereIn('project_id', $visibleProjectIds));
        });
    }

    /**
     * Matches Redmine's Principal#visible? (`Principal.visible(user).find_by(id:) == self`,
     * principal.rb) — the single point of truth for the public profile
     * page's own visibility check (UsersController#show renders a 404
     * when this is false, rather than gating via a policy ability).
     * Deliberately not built on scopeVisibleTo() alone: that scope leaves
     * status filtering to the caller (by design — see its own doc), and
     * Redmine's admin branch of Principal.visible skips the `active` scope
     * entirely (`all` vs `active`), so an admin must still be able to view
     * a locked/deleted user's profile while a non-admin viewer must not.
     */
    public function isVisibleTo(?self $viewer): bool
    {
        if ($viewer?->is_admin) {
            return true;
        }

        if ($this->status !== UserStatus::Active) {
            return false;
        }

        return self::query()->whereKey($this->id)->visibleTo($viewer)->exists();
    }

    /**
     * Matches Redmine's User#own_account_deletable? (user.rb): the
     * `unsubscribe` setting must be enabled, and if this user is an admin
     * there must be at least one *other* active admin — a locked admin
     * doesn't count as the safety net, matching Redmine's
     * `User.active.admin.where("id <> ?", id)` scope exactly.
     */
    public function deletable(): bool
    {
        if (! Setting::get('unsubscribe', true)) {
            return false;
        }

        if (! $this->is_admin) {
            return true;
        }

        return self::query()
            ->where('status', UserStatus::Active)
            ->where('is_admin', true)
            ->whereKeyNot($this->getKey())
            ->exists();
    }

    /**
     * Redmine's User#password_expired?: the site's password_max_age (days, 0
     * = never) has passed since the password last changed.
     */
    public function passwordExpired(): bool
    {
        $days = (int) Setting::get('password_max_age', 0);

        if ($days <= 0) {
            return false;
        }

        return ($this->passwd_changed_on ?? now()->setTimestamp(0))->lt(now()->subDays($days));
    }

    /**
     * Redmine's User#must_change_password?: flagged by an administrator, or
     * expired — and only for accounts whose password this app manages.
     */
    public function mustChangePassword(): bool
    {
        return $this->auth_source_id === null && ($this->must_change_passwd || $this->passwordExpired());
    }

    /**
     * Matches Redmine's User#must_activate_twofa?: whether this user must
     * set up two-factor authentication before being allowed to use the
     * application further, per the Setting.twofa admin toggle
     * ('0' disabled, '1' optional, '2' required for everyone, '3' required
     * for administrators only). Under the two "optional" tiers ('1'/'3'),
     * membership in a Group that itself has twofa_required also forces it.
     */
    public function mustActivateTwoFactor(): bool
    {
        if ($this->hasEnabledTwoFactorAuthentication()) {
            return false;
        }

        $tier = Setting::get('twofa', '0');

        if ($tier === '2' || ($tier === '3' && $this->is_admin)) {
            return true;
        }

        return in_array($tier, ['1', '3'], true)
            && $this->groups()->where('groups.twofa_required', true)->exists();
    }

    /**
     * A lightweight alternative to Passport's OAuth2 authorization-code
     * flow for scripts/cron — matches Redmine's own 40-hex-char REST API
     * key (Redmine::Utils.random_hex(20)). Not mass-assignable (see
     * is_admin's doc-comment above for the same reasoning); only ever set
     * here, from the account settings page.
     */
    public function regenerateApiKey(): string
    {
        $this->api_key = bin2hex(random_bytes(20));
        $this->save();

        return $this->api_key;
    }

    /**
     * The key that authenticates this user's Atom feeds (`?key=`), created on
     * first use like Redmine's rss_key token.
     */
    /**
     * One personal option (Redmine's UserPreference), falling back to its
     * default — see UserPreferences for the keys.
     */
    public function preference(string $key): mixed
    {
        return UserPreferences::get($this, $key);
    }

    public function atomKey(): string
    {
        if ($this->atom_key === null) {
            return $this->regenerateAtomKey();
        }

        return $this->atom_key;
    }

    public function regenerateAtomKey(): string
    {
        $this->atom_key = bin2hex(random_bytes(20));
        $this->save();

        return $this->atom_key;
    }

    public static function customizableType(): CustomizableType
    {
        return CustomizableType::User;
    }

    /**
     * Unlike Issue/Project/Version, a user has no project/role to scope
     * visibility by — user administration is a site-wide resource managed
     * exclusively by admins (UserPolicy denies everyone else), so every
     * User custom field is simply relevant to every user, matching
     * Group::relevantCustomFields()'s identical reasoning.
     *
     * @return Collection<int, CustomField>
     */
    public function relevantCustomFields(): Collection
    {
        return CustomField::query()
            ->where('customized_type', CustomizableType::User)
            ->orderBy('position')
            ->get();
    }

    /**
     * The language of the notifications and mail sent to this user
     * (Laravel sends a notification in its notifiable's preferred locale).
     */
    public function preferredLocale(): string
    {
        return SupportedLocales::forUser($this);
    }

    /**
     * Files of attachment custom fields (B'-02b); the model has no other
     * media.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(AttachmentFormat::COLLECTION);
    }
}
