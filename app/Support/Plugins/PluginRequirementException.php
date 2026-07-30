<?php

declare(strict_types=1);

namespace App\Support\Plugins;

use RuntimeException;

/**
 * Matches Redmine's Redmine::PluginRequirementError: thrown at
 * registration time (from a plugin's ServiceProvider::boot(), same as
 * Redmine raises it from a plugin's init.rb) when the running core
 * version doesn't satisfy the plugin's declared requirement — this is a
 * hard failure, not a warning, since a plugin built against a newer core
 * may rely on APIs this version doesn't have.
 */
final class PluginRequirementException extends RuntimeException {}
