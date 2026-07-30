<?php

return [

    /*
    |--------------------------------------------------------------------
    | Core Version
    |--------------------------------------------------------------------
    |
    | Compared against a plugin's Plugin::$requiresCoreVersion when it
    | registers (PluginManager::registerPlugin()) — matches Redmine's
    | Plugin.requires_redmine(version_or_higher: '...'), which raises
    | PluginRequirementError at boot when the running core is older than
    | what the plugin declares it needs. This app has no release/tag
    | history yet, so this starts at '1.0.0' as an arbitrary baseline for
    | that comparison rather than a real shipped version number.
    |
    */

    'core_version' => env('APP_CORE_VERSION', '1.0.0'),

];
