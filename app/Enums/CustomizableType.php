<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which kind of model a CustomField's definitions and values apply to.
 * Each case's value must also be registered as that model's morph map
 * alias in MorphMapServiceProvider — CustomField's own scoping uses this
 * enum directly, while CustomFieldValue's polymorphic relation resolves
 * its customized_type through the morph map, so the two must agree.
 */
enum CustomizableType: string
{
    case Issue = 'issue';
    case Project = 'project';
    case Version = 'version';
    case Group = 'group';
    case TimeEntryActivity = 'time_entry_activity';
    case Document = 'document';
    case DocumentCategory = 'document_category';
}
