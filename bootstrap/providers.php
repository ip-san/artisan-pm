<?php

use App\Providers\AppServiceProvider;
use App\Providers\CustomFieldServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PermissionServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    PermissionServiceProvider::class,
    CustomFieldServiceProvider::class,
    FortifyServiceProvider::class,
    VoltServiceProvider::class,
];
