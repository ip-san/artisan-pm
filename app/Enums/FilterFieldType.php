<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The value-shape of a filterable field, driving both which operators
 * make sense for it (App\Support\Query\FilterableField::operators()) and
 * how the filter-builder UI renders its value input.
 */
enum FilterFieldType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';
}
