<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\UserStatus;
use App\Models\AuthSource;
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
            ]);
        }

        return null;
    }
}
