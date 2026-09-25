<?php

use App\Files\FilesServiceProvider;
use App\Hooks\HooksServiceProvider;
use App\Modules\ModulesServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\DistributionServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Schema\SchemaServiceProvider;
use App\StarDust\StarDustServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    StarDustServiceProvider::class,
    SchemaServiceProvider::class,
    HooksServiceProvider::class,
    ModulesServiceProvider::class,
    FilesServiceProvider::class,
    DistributionServiceProvider::class,
];
