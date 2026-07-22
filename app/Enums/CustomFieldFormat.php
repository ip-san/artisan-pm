<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomFieldFormat: string
{
    case String = 'string';
    case Text = 'text';
    case Int = 'int';
    case Float = 'float';
    case Date = 'date';
    case Bool = 'bool';
    case List = 'list';
    case Enumeration = 'enumeration';
}
