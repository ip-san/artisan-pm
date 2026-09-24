<?php

declare(strict_types=1);

namespace App\Support\Plugins;

use InvalidArgumentException;

/**
 * A plugin folder's `plugin.json` (A12-03), read and checked before any of
 * its code runs — this app's counterpart of Redmine's `plugins/<name>/init.rb`,
 * except that nothing is executed just because the folder exists: the
 * manifest only says which ServiceProvider to register and where its classes
 * live, and PluginLoader loads it only when an administrator enabled it.
 *
 * ```json
 * {
 *     "id": "my_plugin",
 *     "name": "My Plugin",
 *     "version": "1.0.0",
 *     "author": "Someone",
 *     "requires_core_version": "1.0.0",
 *     "provider": "MyPlugin\\MyPluginServiceProvider",
 *     "autoload": {"MyPlugin\\": "src/"}
 * }
 * ```
 *
 * The checks are strict because the manifest decides which files get
 * required: the id must be the folder's name, every autoload directory must
 * resolve (symlinks included) to somewhere inside the plugin's own folder,
 * and the provider must be in one of the namespaces the plugin declares, so
 * a plugin can't make the loader pull in code from elsewhere or claim the
 * app's own namespaces.
 */
final readonly class PluginManifest
{
    public const string FILENAME = 'plugin.json';

    private const int MAX_BYTES = 65536;

    private const string ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    private const string NAMESPACE_PATTERN = '/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$/';

    private const string CLASS_PATTERN = '/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Namespaces a plugin may not declare: the app's and the framework's.
     *
     * @var array<int, string>
     */
    private const array RESERVED_NAMESPACES = ['App\\', 'Database\\', 'Tests\\', 'Illuminate\\', 'Laravel\\', 'Livewire\\', 'Composer\\'];

    /**
     * @param  array<string, string>  $autoload  namespace prefix => absolute, resolved directory
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $author,
        public string $description,
        public string $requiresCoreVersion,
        public string $provider,
        public array $autoload,
        public string $directory,
    ) {}

    /**
     * Reads and validates `<$directory>/plugin.json`. $root is the plugins
     * folder the plugin must live in.
     *
     * @throws InvalidArgumentException with an operator-facing reason when anything is off
     */
    public static function fromDirectory(string $directory, string $root): self
    {
        $folderName = basename($directory);
        $realRoot = realpath($root);
        $realDirectory = realpath($directory);

        if ($realRoot === false || $realDirectory === false || ! is_dir($realDirectory) || ! self::isInside($realDirectory, $realRoot)) {
            throw new InvalidArgumentException('The plugin folder resolves outside the plugins directory.');
        }

        $file = $realDirectory.DIRECTORY_SEPARATOR.self::FILENAME;

        if (is_link($directory.DIRECTORY_SEPARATOR.self::FILENAME) || ! is_file($file)) {
            throw new InvalidArgumentException('plugin.json is missing.');
        }

        if (filesize($file) > self::MAX_BYTES) {
            throw new InvalidArgumentException('plugin.json is too large.');
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('plugin.json is not valid JSON: '.$exception->getMessage());
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('plugin.json must be a JSON object.');
        }

        $id = $data['id'] ?? null;

        if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('"id" must be lowercase letters, digits and underscores, starting with a letter.');
        }

        if ($id !== $folderName) {
            throw new InvalidArgumentException("\"id\" ({$id}) must match the folder name ({$folderName}).");
        }

        foreach (['name', 'version', 'author', 'description', 'requires_core_version'] as $key) {
            if (array_key_exists($key, $data) && ! is_string($data[$key])) {
                throw new InvalidArgumentException("\"{$key}\" must be a string.");
            }
        }

        $requiresCoreVersion = (string) ($data['requires_core_version'] ?? '0');

        if (preg_match('/^\d+(\.\d+){0,3}$/', $requiresCoreVersion) !== 1) {
            throw new InvalidArgumentException('"requires_core_version" must be a version number such as 1.0.0.');
        }

        $autoload = self::autoloadMap($data['autoload'] ?? null, $realDirectory);
        $provider = $data['provider'] ?? null;

        if (! is_string($provider) || preg_match(self::CLASS_PATTERN, ltrim($provider, '\\')) !== 1) {
            throw new InvalidArgumentException('"provider" must be a fully qualified class name.');
        }

        $provider = ltrim($provider, '\\');

        if (! collect(array_keys($autoload))->contains(fn (string $prefix) => str_starts_with($provider, $prefix))) {
            throw new InvalidArgumentException('"provider" must be inside one of the namespaces declared in "autoload".');
        }

        return new self(
            id: $id,
            name: trim((string) ($data['name'] ?? '')) !== '' ? trim((string) $data['name']) : $id,
            version: (string) ($data['version'] ?? ''),
            author: (string) ($data['author'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            requiresCoreVersion: $requiresCoreVersion,
            provider: $provider,
            autoload: $autoload,
            directory: $realDirectory,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function autoloadMap(mixed $autoload, string $pluginDirectory): array
    {
        if (! is_array($autoload) || $autoload === [] || array_is_list($autoload)) {
            throw new InvalidArgumentException('"autoload" must map at least one namespace to a folder, e.g. {"MyPlugin\\\\": "src/"}.');
        }

        $map = [];

        foreach ($autoload as $namespace => $path) {
            $namespace = ltrim((string) $namespace, '\\');

            if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
                throw new InvalidArgumentException("\"autoload\" namespace \"{$namespace}\" must be a namespace ending with a backslash.");
            }

            foreach (self::RESERVED_NAMESPACES as $reserved) {
                if (str_starts_with($namespace, $reserved)) {
                    throw new InvalidArgumentException("\"autoload\" namespace \"{$namespace}\" is reserved for the application.");
                }
            }

            if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
                throw new InvalidArgumentException("\"autoload\" path for \"{$namespace}\" must be a relative folder.");
            }

            $normalized = str_replace('\\', '/', $path);

            if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1 || str_contains($normalized, '://')) {
                throw new InvalidArgumentException("\"autoload\" path \"{$path}\" must be relative to the plugin folder.");
            }

            if (in_array('..', explode('/', $normalized), true)) {
                throw new InvalidArgumentException("\"autoload\" path \"{$path}\" must not contain \"..\".");
            }

            $resolved = realpath($pluginDirectory.DIRECTORY_SEPARATOR.$normalized);

            if ($resolved === false || ! is_dir($resolved)) {
                throw new InvalidArgumentException("\"autoload\" path \"{$path}\" is not a folder.");
            }

            if ($resolved !== $pluginDirectory && ! self::isInside($resolved, $pluginDirectory)) {
                throw new InvalidArgumentException("\"autoload\" path \"{$path}\" resolves outside the plugin folder.");
            }

            $map[$namespace] = $resolved;
        }

        return $map;
    }

    private static function isInside(string $path, string $directory): bool
    {
        return str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
