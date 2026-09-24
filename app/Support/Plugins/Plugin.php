<?php

declare(strict_types=1);

namespace App\Support\Plugins;

/**
 * A plugin's metadata. A plugin's ServiceProvider is either added to
 * bootstrap/providers.php by hand or loaded at runtime from its folder
 * under plugins/ once enabled (PluginLoader, A12-03); either way its boot()
 * is expected to hand this to PluginManager::registerPlugin() so the
 * admin "installed plugins" screen (§拡張性) has something to list — $id
 * is the stable key used for that listing and for namespacing the
 * plugin's persisted settings (independent of $name, which is a
 * free-text display label a plugin could change without breaking its
 * stored settings). $settingsView optionally names a Blade view (Redmine's
 * `settings :partial => '...'`) that replaces the generic key/value editor
 * on the plugin's settings page; it receives $values and $pluginId and binds
 * inputs with wire:model="values.<key>".
 */
final readonly class Plugin
{
    public function __construct(
        public string $id,
        public string $name,
        public string $author,
        public string $version,
        public string $requiresCoreVersion,
        public ?string $settingsView = null,
    ) {}
}
