<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasCustomFields;
use App\Enums\CustomizableType;
use App\Enums\MailNotificationOption;
use App\Enums\UserStatus;
use App\Support\Authorization\AuthorizationService;
use Database\Factories\UserFactory;
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
#[Fillable(['name', 'email', 'password', 'language', 'auth_source_id', 'login', 'status', 'mail_notification', 'no_self_notified'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'api_key'])]
final class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasCustomFields, HasFactory, Notifiable, TwoFactorAuthenticatable;

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

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
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
     * Matches Redmine's User#must_activate_twofa?: whether this user must
     * set up two-factor authentication before being allowed to use the
     * application further, per the Setting.twofa admin toggle
     * ('0' disabled, '1' optional, '2' required for everyone, '3' required
     * for administrators only). Redmine also has a fourth trigger —
     * Setting.twofa_optional? (tiers '1'/'3') combined with membership in a
     * Group that itself has twofa_required — which this app's Group model
     * has no equivalent attribute for and is intentionally out of scope.
     */
    public function mustActivateTwoFactor(): bool
    {
        if ($this->hasEnabledTwoFactorAuthentication()) {
            return false;
        }

        return match (Setting::get('twofa', '0')) {
            '2' => true,
            '3' => $this->is_admin,
            default => false,
        };
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
}
