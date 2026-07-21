<?php

namespace App\Enums;

enum WorkflowFieldRuleType: string
{
    case Required = 'required';
    case ReadOnly = 'read_only';
}
