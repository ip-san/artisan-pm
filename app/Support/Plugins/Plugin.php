<?php

declare(strict_types=1);

namespace App\Support\Plugins;

/**
 * A plugin's metadata — the app doesn't read this at boot (there's no
 * runtime plugin discovery in this first stage; a plugin's own
 * ServiceProvider is added to bootstrap/providers.php by hand, same as any
 * other Composer package), but every plugin should expose one so an
 * admin — or a future discovery mechanism — can identify what's installed.
 */
final readonly class Plugin
{
    public function __construct(
        public string $name,
        public string $author,
        public string $version,
        public string $requiresCoreVersion,
    ) {}
}
