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

    /*
    |--------------------------------------------------------------------
    | Plugins Path
    |--------------------------------------------------------------------
    |
    | Where PluginLoader looks for plugin folders (A12-03), Redmine's
    | plugins/ directory: each `<path>/<id>/plugin.json` describes one
    | plugin. Operators place plugins here on disk (there is no upload from
    | the admin screen); a plugin is loaded only after an administrator
    | enables it on the plugins screen (setting `plugins_enabled`).
    |
    */

    'path' => env('PLUGINS_PATH', base_path('plugins')),

];
