<?php

use App\Modules\Calculators\CalculatorsServiceProvider;
use App\Modules\LibraryProtection\LibraryProtectionServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    CalculatorsServiceProvider::class,
    LibraryProtectionServiceProvider::class,
];
