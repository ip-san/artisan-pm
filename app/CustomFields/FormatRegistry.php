<?php

declare(strict_types=1);

namespace App\CustomFields;

use App\CustomFields\Formats\FormatContract;
use App\Enums\CustomFieldFormat;
use InvalidArgumentException;

/**
 * Boot-time catalog of custom field formats, analogous to Redmine's
 * Redmine::FieldFormat registry. A future plugin system registers
 * additional formats here the same way core ones are registered.
 */
final class FormatRegistry
{
    /** @var array<string, FormatContract> */
    private array $formats = [];

    public function register(FormatContract $format): void
    {
        $this->formats[$format->key()->value] = $format;
    }

    public function get(CustomFieldFormat $key): FormatContract
    {
        return $this->formats[$key->value]
            ?? throw new InvalidArgumentException("No format registered for [{$key->value}].");
    }

    /**
     * @return array<string, FormatContract>
     */
    public function all(): array
    {
        return $this->formats;
    }
}
