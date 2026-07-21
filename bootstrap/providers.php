<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PermissionServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    PermissionServiceProvider::class,
    FortifyServiceProvider::class,
    VoltServiceProvider::class,
];
