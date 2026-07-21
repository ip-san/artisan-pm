<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which kind of model a CustomField's definitions and values apply to.
 * Only Issue is wired up so far; Project/User/etc custom fields are
 * future-phase scope, but the schema and registry are already
 * discriminator-based so adding a case here is the only step needed later.
 */
enum CustomizableType: string
{
    case Issue = 'issue';
}
