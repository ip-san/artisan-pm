<?php

declare(strict_types=1);

namespace App\Support\Plugins;

use App\CustomFields\FormatRegistry;
use App\CustomFields\Formats\FormatContract;
use App\Enums\PermissionRequirement;
use App\Enums\ProjectModuleKey;
use App\Models\Setting;
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

    /** @var array<string, Plugin> keyed by Plugin::$id */
    private array $plugins = [];

    /** @var array<string, array<string, mixed>> keyed by Plugin::$id */
    private array $settingsDefaults = [];

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

    /**
     * Matches Redmine::Plugin.register's single-call registration
     * (name/author/version + an optional `settings` block) — a plugin
     * calls this once from boot() rather than the app needing a separate
     * discovery pass. $settingsDefaults is empty for a plugin with no
     * configurable settings, in which case it never appears on the admin
     * settings screen (matching Redmine's own "no settings block, no
     * Configure link" behavior), only on the installed-plugins list.
     *
     * @param  array<string, mixed>  $settingsDefaults
     *
     * @throws PluginRequirementException if the running core version is
     *                                    older than $plugin->requiresCoreVersion — matches Redmine's
     *                                    Plugin.requires_redmine(version_or_higher: '...') raising
     *                                    PluginRequirementError, a hard failure rather than a
     *                                    silently-skipped registration.
     */
    public function registerPlugin(Plugin $plugin, array $settingsDefaults = []): void
    {
        $coreVersion = (string) config('plugins.core_version');

        if (version_compare($coreVersion, $plugin->requiresCoreVersion, '<')) {
            throw new PluginRequirementException(
                "Plugin \"{$plugin->id}\" requires core version {$plugin->requiresCoreVersion} or higher, but the running core version is {$coreVersion}."
            );
        }

        $this->plugins[$plugin->id] = $plugin;

        if ($settingsDefaults !== []) {
            $this->settingsDefaults[$plugin->id] = $settingsDefaults;
        }
    }

    /**
     * @return array<int, Plugin>
     */
    public function plugins(): array
    {
        return array_values($this->plugins);
    }

    public function plugin(string $id): ?Plugin
    {
        return $this->plugins[$id] ?? null;
    }

    public function hasSettings(string $id): bool
    {
        return array_key_exists($id, $this->settingsDefaults);
    }

    /**
     * The stored value only ever overrides keys the plugin still declares
     * a default for — a key a plugin removed in a later version can't
     * resurrect itself from stale stored data.
     *
     * @return array<string, mixed>
     */
    public function settings(string $id): array
    {
        $defaults = $this->settingsDefaults[$id] ?? [];
        $stored = Setting::get(self::settingsKey($id), []);

        return [...$defaults, ...array_intersect_key($stored, $defaults)];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function saveSettings(string $id, array $values): void
    {
        $defaults = $this->settingsDefaults[$id] ?? [];

        Setting::set(self::settingsKey($id), array_intersect_key($values, $defaults));
    }

    private static function settingsKey(string $id): string
    {
        return "plugin_{$id}";
    }
}
