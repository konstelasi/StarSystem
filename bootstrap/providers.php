<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Schema\SchemaServiceProvider;
use App\StarDust\StarDustServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    StarDustServiceProvider::class,
    SchemaServiceProvider::class,
];
