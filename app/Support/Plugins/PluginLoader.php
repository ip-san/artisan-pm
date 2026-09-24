<?php

declare(strict_types=1);

namespace App\Support\Plugins;

use App\Models\Setting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Throwable;

/**
 * Runtime plugin loading (A12-03), Redmine's Redmine::PluginLoader: every
 * folder under config('plugins.path') with a plugin.json is a plugin, and
 * the ones an administrator enabled (setting `plugins_enabled`, none by
 * default) get their classes autoloaded and their ServiceProvider
 * registered. The provider then registers itself with PluginManager the
 * same way a hand-registered one in bootstrap/providers.php does.
 *
 * A broken plugin (invalid manifest, unmet core version, missing provider
 * class, or a provider that throws while registering or booting) is logged,
 * listed with its reason on the admin screen, and skipped — it never takes
 * the app down. Disabled plugins aren't loaded at all, so their permissions
 * and registrations simply don't exist; permission keys already granted to
 * roles stay stored but grant nothing (AuthorizationService denies keys the
 * PermissionRegistry doesn't know).
 */
final class PluginLoader
{
    /**
     * Autoload prefixes already registered in this process, so loading the
     * same plugin again (a second app instance, as in tests) doesn't stack
     * autoloaders.
     *
     * @var array<string, string> namespace prefix => directory
     */
    private static array $registeredPrefixes = [];

    /** @var array<string, PluginManifest>|null keyed by plugin id */
    private ?array $manifests = null;

    /** @var array<string, string> folder name => why it was not loaded */
    private array $failures = [];

    /** @var array<string, true> ids whose provider was registered */
    private array $loaded = [];

    public function __construct(private readonly Application $app) {}

    public static function pluginsPath(): string
    {
        return (string) config('plugins.path');
    }

    /**
     * The valid plugins on disk, keyed by id (manifest failures go to
     * failures()).
     *
     * @return array<string, PluginManifest>
     */
    public function discovered(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $this->manifests = [];
        $root = self::pluginsPath();

        if ($root === '' || ! is_dir($root)) {
            return $this->manifests;
        }

        $folders = glob(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];
        sort($folders);

        foreach ($folders as $folder) {
            try {
                $manifest = PluginManifest::fromDirectory($folder, $root);
                $this->manifests[$manifest->id] = $manifest;
            } catch (InvalidArgumentException $exception) {
                $this->fail(basename($folder), $exception->getMessage());
            }
        }

        return $this->manifests;
    }

    /**
     * Why each plugin folder that failed validation or loading was skipped.
     *
     * @return array<string, string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function isLoaded(string $id): bool
    {
        return isset($this->loaded[$id]);
    }

    /**
     * The ids enabled in the settings — an unreadable settings table (before
     * migrations, e.g. during package:discover) means none.
     *
     * @return array<int, string>
     */
    public static function enabledIds(): array
    {
        try {
            $ids = Setting::get('plugins_enabled', []);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(is_array($ids) ? $ids : [], 'is_string'));
    }

    /**
     * Loads every enabled plugin found on disk. Called once the app has
     * booted, so each provider's register() and boot() run inside
     * Application::register() and a throwing plugin is caught here.
     */
    public function loadEnabled(): void
    {
        if ($this->discovered() === []) {
            return;
        }

        foreach (self::enabledIds() as $id) {
            if (isset($this->manifests[$id])) {
                $this->load($this->manifests[$id]);
            }
        }
    }

    /**
     * Loads one plugin; false (with the reason in failures()) when it
     * could not be.
     */
    public function load(PluginManifest $manifest): bool
    {
        if (isset($this->loaded[$manifest->id])) {
            return true;
        }

        $coreVersion = (string) config('plugins.core_version');

        if (version_compare($coreVersion, $manifest->requiresCoreVersion, '<')) {
            return $this->fail($manifest->id, "Requires core version {$manifest->requiresCoreVersion} or higher, but the running core version is {$coreVersion}.");
        }

        foreach ($manifest->autoload as $prefix => $directory) {
            if (isset(self::$registeredPrefixes[$prefix]) && self::$registeredPrefixes[$prefix] !== $directory) {
                return $this->fail($manifest->id, "The namespace {$prefix} is already used by another plugin.");
            }
        }

        foreach ($manifest->autoload as $prefix => $directory) {
            self::registerAutoload($prefix, $directory);
        }

        try {
            if (! class_exists($manifest->provider) || ! is_subclass_of($manifest->provider, ServiceProvider::class)) {
                return $this->fail($manifest->id, "The provider {$manifest->provider} is not a ServiceProvider class in the plugin's folder.");
            }

            $this->app->register($manifest->provider);
        } catch (Throwable $exception) {
            return $this->fail($manifest->id, $exception::class.': '.$exception->getMessage());
        }

        $this->loaded[$manifest->id] = true;
        unset($this->failures[$manifest->id]);

        return true;
    }

    private function fail(string $id, string $reason): bool
    {
        $this->failures[$id] = $reason;

        Log::warning("Plugin \"{$id}\" was not loaded: {$reason}");

        return false;
    }

    /**
     * A PSR-4 autoloader for one prefix, appended after Composer's (so the
     * app's own classes always win) and refusing files that resolve outside
     * the directory through a symlink.
     */
    private static function registerAutoload(string $prefix, string $directory): void
    {
        if (isset(self::$registeredPrefixes[$prefix])) {
            return;
        }

        self::$registeredPrefixes[$prefix] = $directory;

        spl_autoload_register(static function (string $class) use ($prefix, $directory): void {
            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = realpath($directory.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php');

            if ($file !== false && str_starts_with($file, $directory.DIRECTORY_SEPARATOR)) {
                require_once $file;
            }
        });
    }
}
