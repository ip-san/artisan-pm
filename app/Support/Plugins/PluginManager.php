<?php

declare(strict_types=1);

namespace App\Support\Plugins;

use App\CustomFields\FormatRegistry;
use App\CustomFields\Formats\FormatContract;
use App\Enums\PermissionRequirement;
use App\Enums\ProjectModuleKey;
use App\Support\Activity\ActivityProvider;
use App\Support\Activity\ActivityProviderRegistry;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRegistry;
use App\Support\Permissions\PermissionRegistry;

/**
 * The single entry point a plugin's ServiceProvider registers against,
 * kept deliberately separate from the registries it delegates to
 * (PermissionRegistry, ActivityProviderRegistry, ...) so those internal
 * registries can keep evolving without breaking the plugin-facing surface.
 *
 * Menu items and view hooks live here directly rather than delegating,
 * since — unlike the other four — nothing in the core app needed them
 * before plugins did, so there was no pre-existing registry to unify with.
 *
 * Deliberately excluded from this first stage (see the plan's
 * plugin-architecture section): project modules and query filter
 * operators. Both are real gaps — ProjectModuleKey is a compile-time PHP
 * enum, and the query filter "registries" are stateless static factories
 * with no register() method and no call sites yet — but turning either
 * into a genuine runtime registry is a separate, non-trivial change
 * unrelated to standing up the plugin system itself.
 */
final class PluginManager
{
    /** @var array<string, array<int, MenuItem>> */
    private array $menuItems = [];

    /** @var array<string, array<int, callable>> */
    private array $viewHooks = [];

    public function __construct(
        private readonly PermissionRegistry $permissions,
        private readonly ActivityProviderRegistry $activityProviders,
        private readonly DashboardBlockRegistry $dashboardBlocks,
        private readonly FormatRegistry $customFieldFormats,
    ) {}

    public function registerPermission(string $key, ?ProjectModuleKey $module = null, PermissionRequirement $requirement = PermissionRequirement::Member): void
    {
        $this->permissions->register($key, $module, $requirement);
    }

    public function registerActivityProvider(ActivityProvider $provider): void
    {
        $this->activityProviders->register($provider);
    }

    public function registerDashboardBlock(DashboardBlock $block): void
    {
        $this->dashboardBlocks->register($block);
    }

    public function registerCustomFieldFormat(FormatContract $format): void
    {
        $this->customFieldFormats->register($format);
    }

    /**
     * $slot identifies where in the UI this item should appear — the only
     * slot the core app currently renders is 'nav', matching the main
     * navigation bar.
     */
    public function registerMenuItem(string $slot, MenuItem $item): void
    {
        $this->menuItems[$slot][] = $item;
    }

    /**
     * @return array<int, MenuItem>
     */
    public function menuItems(string $slot): array
    {
        return array_values(array_filter(
            $this->menuItems[$slot] ?? [],
            fn (MenuItem $item) => $item->isVisible(),
        ));
    }

    /**
     * $renderer receives the hook's $data array and must return a string
     * (or something string-castable) — typically a rendered Blade view.
     */
    public function registerViewHook(string $name, callable $renderer): void
    {
        $this->viewHooks[$name][] = $renderer;
    }

    /**
     * Renders every renderer registered for $name in registration order,
     * concatenated — matching the plan's "多重登録可能なビューフック" design
     * (<x-hook> calls this once per named slot in a core view; zero, one,
     * or many plugins may have something to render there).
     *
     * @param  array<string, mixed>  $data
     */
    public function renderHook(string $name, array $data = []): string
    {
        return collect($this->viewHooks[$name] ?? [])
            ->map(fn (callable $renderer) => (string) $renderer($data))
            ->implode('');
    }
}
