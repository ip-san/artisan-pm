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

    /**
     * Issue ids (issue_id, parent_id, child_id, the relation filters):
     * "is" and the tree/relation operators take a comma separated list,
     * as in Redmine; the comparisons take a single number.
     */
    case IdList = 'id_list';

    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';
}
