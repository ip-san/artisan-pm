<?php

declare(strict_types=1);

namespace App\Support\Activity;

use Illuminate\Support\Collection;

final class ActivityProviderRegistry
{
    /** @var array<int, ActivityProvider> */
    private array $providers = [];

    public function register(ActivityProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * @return Collection<int, ActivityProvider>
     */
    public function all(): Collection
    {
        return collect($this->providers);
    }
}
