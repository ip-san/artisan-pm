<?php

declare(strict_types=1);

namespace App\Support\Scm;

use App\Enums\ScmCapability;

/**
 * Redmine's Filesystem "SCM" (lib/redmine/scm/adapters/filesystem_adapter.rb):
 * a plain directory under scm.repositories_root, browsed as it is right now.
 * There are no revisions, so only tree()/fileContentAt() mean anything —
 * $revision is ignored, and log/diff/blame report nothing (supports()).
 *
 * Unlike the other adapters this reads the filesystem directly, so it has
 * to confine every access itself: WithinRepositoriesRoot only validated
 * the repository's own directory. A path with a ".." segment is refused
 * (as Redmine does), and every target is realpath()-resolved and must stay
 * inside the repository directory, so a symlink pointing outside it is
 * neither listed nor readable.
 */
final readonly class FilesystemAdapter implements ScmAdapter
{
    public function __construct(
        private string $path,
    ) {}

    public function isAvailable(): bool
    {
        return $this->root() !== null && is_readable((string) $this->root());
    }

    public function supports(ScmCapability $capability): bool
    {
        return false;
    }

    public function log(?string $sinceRevision = null): array
    {
        return [];
    }

    public function diff(string $revision, ?string $fromRevision = null, ?string $path = null): string
    {
        return '';
    }

    public function tree(string $revision, string $path = ''): array
    {
        $path = trim($path, '/');
        $directory = $this->resolve($path);

        if ($directory === null || ! is_dir($directory)) {
            return [];
        }

        $names = @scandir($directory);

        if ($names === false) {
            return [];
        }

        $entries = [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $target = $this->resolve($path === '' ? $name : "{$path}/{$name}");

            // Skips symlinks leading outside the repository and special
            // files (sockets, devices, FIFOs), as Redmine does.
            if ($target === null || (! is_dir($target) && ! is_file($target))) {
                continue;
            }

            $entries[] = new ScmTreeEntry(
                name: $name,
                path: $path === '' ? $name : "{$path}/{$name}",
                isDirectory: is_dir($target),
            );
        }

        usort($entries, fn (ScmTreeEntry $a, ScmTreeEntry $b) => [! $a->isDirectory, $a->name] <=> [! $b->isDirectory, $b->name]);

        return $entries;
    }

    public function fileContentAt(string $revision, string $path): string
    {
        $file = $this->resolve(trim($path, '/'));

        if ($file === null || ! is_file($file) || ! is_readable($file)) {
            return '';
        }

        return (string) file_get_contents($file);
    }

    public function blame(string $revision, string $path): array
    {
        return [];
    }

    private function root(): ?string
    {
        $root = realpath($this->path);

        return $root === false || ! is_dir($root) ? null : $root;
    }

    /**
     * The real path of $relativePath inside the repository, or null when
     * it doesn't exist or resolves outside the repository directory.
     */
    private function resolve(string $relativePath): ?string
    {
        $root = $this->root();

        if ($root === null || str_contains($relativePath, "\0") || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $relativePath) === 1) {
            return null;
        }

        if ($relativePath === '') {
            return $root;
        }

        $target = realpath($root.DIRECTORY_SEPARATOR.$relativePath);

        if ($target === false || ($target !== $root && ! str_starts_with($target, $root.DIRECTORY_SEPARATOR))) {
            return null;
        }

        return $target;
    }
}
