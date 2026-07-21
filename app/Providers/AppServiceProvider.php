<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Policies\CalendarPolicy;
use App\Policies\GanttPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(fn (User $user, string $ability) => $user->is_admin ? true : null);

        // Calendar and Gantt have no backing Eloquent model for Gate's
        // usual class-name-based policy auto-discovery to key off of, so
        // their abilities are registered explicitly here instead.
        Gate::define('viewCalendar', [CalendarPolicy::class, 'view']);
        Gate::define('viewGantt', [GanttPolicy::class, 'view']);
    }
}
