<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use Illuminate\Support\Collection;

final class DashboardBlockRegistry
{
    /** @var array<int, DashboardBlock> */
    private array $blocks = [];

    public function register(DashboardBlock $block): void
    {
        $this->blocks[] = $block;
    }

    /**
     * @return Collection<int, DashboardBlock>
     */
    public function all(): Collection
    {
        return collect($this->blocks);
    }

    public function find(string $key): ?DashboardBlock
    {
        return $this->all()->first(fn (DashboardBlock $block) => $block->key() === $key);
    }
}
