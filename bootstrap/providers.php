<?php

use App\Providers\ActivityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CustomFieldServiceProvider;
use App\Providers\DashboardBlockServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\MailNotificationServiceProvider;
use App\Providers\MorphMapServiceProvider;
use App\Providers\PermissionServiceProvider;
use App\Providers\PluginServiceProvider;
use App\Providers\VoltServiceProvider;
use App\Providers\WebhookServiceProvider;

return [
    ActivityServiceProvider::class,
    AppServiceProvider::class,
    CustomFieldServiceProvider::class,
    DashboardBlockServiceProvider::class,
    FortifyServiceProvider::class,
    MailNotificationServiceProvider::class,
    MorphMapServiceProvider::class,
    PermissionServiceProvider::class,
    PluginServiceProvider::class,
    VoltServiceProvider::class,
    WebhookServiceProvider::class,
];
