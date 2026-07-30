<?php

declare(strict_types=1);

namespace App\Support\Plugins;

/**
 * A plugin's metadata. There's still no runtime plugin *discovery* (a
 * plugin's own ServiceProvider is added to bootstrap/providers.php by
 * hand, same as any other Composer package), but a plugin's boot() is
 * now expected to hand this to PluginManager::registerPlugin() so the
 * admin "installed plugins" screen (§拡張性) has something to list — $id
 * is the stable key used for that listing and for namespacing the
 * plugin's persisted settings (independent of $name, which is a
 * free-text display label a plugin could change without breaking its
 * stored settings).
 */
final readonly class Plugin
{
    public function __construct(
        public string $id,
        public string $name,
        public string $author,
        public string $version,
        public string $requiresCoreVersion,
    ) {}
}
