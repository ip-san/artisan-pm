<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkflowFieldRuleType: string
{
    case Required = 'required';
    case ReadOnly = 'read_only';
}
