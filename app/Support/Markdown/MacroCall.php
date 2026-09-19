<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use App\Models\Project;
use App\Models\WikiPage;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One `{{name(arguments)}}` in a rendered page: the raw comma-separated
 * arguments plus the project, page and attachments it was written on.
 */
final readonly class MacroCall
{
    /**
     * @param  array<int, string>  $arguments
     * @param  MediaCollection<int, Media>|null  $attachments
     */
    public function __construct(
        public string $name,
        public array $arguments,
        public ?Project $project,
        public ?WikiPage $page,
        public ?MediaCollection $attachments,
    ) {}

    /**
     * The arguments that are not `key=value`.
     *
     * @return array<int, string>
     */
    public function positional(): array
    {
        return array_values(array_filter($this->arguments, fn (string $argument) => ! str_contains($argument, '=')));
    }

    /**
     * The `key=value` arguments.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->arguments as $argument) {
            if (str_contains($argument, '=')) {
                [$key, $value] = explode('=', $argument, 2);
                $options[trim($key)] = trim($value);
            }
        }

        return $options;
    }
}
