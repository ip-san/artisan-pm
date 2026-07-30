<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Matches Redmine's require_sudo_mode (used on sensitive actions like
 * account deletion or 2FA changes) and Illuminate\Auth\Middleware\
 * RequirePassword's own freshness check — reimplemented here rather than
 * routed through, since Livewire actions are invoked directly rather than
 * through an HTTP route those middleware could sit in front of.
 */
trait RequiresPasswordConfirmation
{
    private function requirePasswordConfirmation(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        if (now()->unix() - $confirmedAt > (int) config('auth.password_timeout', 10800)) {
            $this->redirect(route('password.confirm'));

            return false;
        }

        return true;
    }
}
