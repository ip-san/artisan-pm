<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Redmine's My Page layout groups (Redmine::MyPage::CORE_GROUPS: 'top',
 * 'left', 'right' — in that order, which is also the order a newly added
 * block is offered: UserPreference#add_block unshifts into the first
 * group).
 */
enum DashboardArea: string
{
    case Top = 'top';
    case Left = 'left';
    case Right = 'right';
}
