<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Registered = 'registered';
    case Locked = 'locked';
}
