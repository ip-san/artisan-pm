<?php

declare(strict_types=1);

namespace App\Support\Activity;

/**
 * Marks an ActivityProvider whose type starts unchecked on the activity
 * screens — Redmine's `activity.register ..., default: false`. Its entries
 * are still there when the viewer ticks the box.
 */
interface OffByDefault {}
