<?php

use App\Providers\ActivityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CustomFieldServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\MorphMapServiceProvider;
use App\Providers\PermissionServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    PermissionServiceProvider::class,
    CustomFieldServiceProvider::class,
    MorphMapServiceProvider::class,
    ActivityServiceProvider::class,
    FortifyServiceProvider::class,
    VoltServiceProvider::class,
];
