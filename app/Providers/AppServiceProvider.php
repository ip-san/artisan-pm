<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Policies\CalendarPolicy;
use App\Policies\GanttPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(fn (User $user, string $ability) => $user->is_admin ? true : null);

        $this->registerApiKeyGuard();

        // Calendar and Gantt have no backing Eloquent model for Gate's
        // usual class-name-based policy auto-discovery to key off of, so
        // their abilities are registered explicitly here instead.
        Gate::define('viewCalendar', [CalendarPolicy::class, 'view']);
        Gate::define('viewGantt', [GanttPolicy::class, 'view']);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Matches Redmine's three API-key acceptance paths (checked in this
     * same precedence order in ApplicationController#find_current_user):
     * a `key=` query parameter, an `X-Redmine-API-Key` header, or the
     * username slot of HTTP Basic auth (`curl -u APIKEY:` with any/blank
     * password) — the third lets existing HTTP-Basic-aware tooling use
     * the key without knowing about Redmine's own header convention.
     *
     * Unlike Sanctum-style hashed-token lookups, this is a plain indexed
     * WHERE on a random 160-bit value — not constant-time, but the same
     * tradeoff every other unique-index credential lookup in this app
     * (password reset tokens, etc.) already makes; a timing attack against
     * a keyspace this large isn't practical.
     */
    private function registerApiKeyGuard(): void
    {
        Auth::viaRequest('api-key', function (Request $request) {
            $key = $request->query('key') ?? $request->header('X-Redmine-API-Key') ?? $request->getUser();

            if (! is_string($key) || $key === '') {
                return null;
            }

            return User::query()->where('api_key', $key)->first();
        });
    }
}
