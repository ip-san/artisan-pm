<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One block a user can add to their My Page — registered centrally (see
 * DashboardBlockServiceProvider) the same way ActivityProvider is, with
 * a uniform row shape (title/url/meta) rather than each block rendering
 * its own markup, so the dashboard doesn't need a distinct partial per
 * block type.
 */
interface DashboardBlock
{
    public function key(): string;

    public function label(): string;

    /**
     * @return Collection<int, DashboardBlockRow>
     */
    public function rows(User $user): Collection;
}
