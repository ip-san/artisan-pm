<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ldap\LdapAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * Authenticates a login attempt against whichever source applies: an
 * existing account's linked LDAP AuthSource, an existing account's local
 * password, or — for a login matching no local account — every
 * on-the-fly-registration-enabled AuthSource in turn, auto-provisioning a
 * local account on the first one that accepts the credentials. Mirrors
 * Redmine's AuthSourceLdap login flow.
 */
final class AuthenticateUser
{
    public function __construct(
        private readonly LdapAuthenticator $ldap,
    ) {}

    public function __invoke(Request $request): ?User
    {
        $login = (string) $request->input(Fortify::username());
        $password = (string) $request->input('password');

        if ($login === '' || $password === '') {
            return null;
        }

        $user = $this->findExistingUser($login);

        if ($user !== null && ! $user->isActive()) {
            return null;
        }

        if ($user?->auth_source_id !== null) {
            return $this->reauthenticate($user, $password);
        }

        if ($user !== null) {
            return Hash::check($password, $user->password) ? $user : null;
        }

        return $this->provisionFromDirectory($login, $password);
    }

    /**
     * A local (password) account is found by email — the field the login
     * form actually collects. An LDAP-linked account is additionally found
     * by its stored `login` (the directory uid it was provisioned with),
     * since on a later visit that generally won't match its `email` column,
     * which was populated from the directory's mail attribute instead.
     */
    private function findExistingUser(string $login): ?User
    {
        return User::query()
            ->where('email', $login)
            ->orWhere(fn ($query) => $query->whereNotNull('auth_source_id')->where('login', $login))
            ->first();
    }

    private function reauthenticate(User $user, string $password): ?User
    {
        $source = $user->authSource;

        if ($source === null) {
            return null;
        }

        $attributes = $this->ldap->attempt($source, $user->login ?? $user->email, $password);

        if ($attributes === null) {
            return null;
        }

        if ($attributes['name'] !== null) {
            $user->update(['name' => $attributes['name']]);
        }

        return $user;
    }

    private function provisionFromDirectory(string $login, string $password): ?User
    {
        // Matches Redmine's on-the-fly provisioning implicitly failing when
        // the directory-supplied login can't pass User's own validation:
        // this is the raw text typed into the login form, not sanitized by
        // any form request, so it must be checked against the same
        // format/length constraint the two user-facing forms enforce
        // before it's trusted as a new account's login.
        if (strlen($login) > User::LOGIN_LENGTH_LIMIT || preg_match(User::LOGIN_FORMAT_REGEX, $login) !== 1) {
            return null;
        }

        foreach (AuthSource::query()->where('onthefly_register', true)->get() as $source) {
            $attributes = $this->ldap->attempt($source, $login, $password);

            if ($attributes === null || $attributes['mail'] === null) {
                continue;
            }

            return User::create([
                'auth_source_id' => $source->id,
                'login' => $login,
                'name' => $attributes['name'] ?? $login,
                'email' => $attributes['mail'],
                // Never checked for LDAP-linked accounts (reauthenticate()
                // always defers to the directory) — just satisfies the
                // NOT NULL column with an unguessable, unused value.
                'password' => Hash::make(Str::random(40)),
                'status' => UserStatus::Active->value,
                // Every User::create() call site seeds this from the admin
                // default explicitly (Redmine's UserPreference seeds it
                // lazily on first access instead, but this app has no
                // preference object to defer to) — on-the-fly LDAP
                // provisioning is a real account-creation path just like
                // self-registration and admin creation, so it needs the
                // same seed or it would silently fall back to the model's
                // hardcoded $attributes default instead.
                'no_self_notified' => Setting::get('default_users_no_self_notified', true),
            ]);
        }

        return null;
    }
}
