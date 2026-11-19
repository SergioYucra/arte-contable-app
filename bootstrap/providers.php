<?php

use App\Modules\Calculators\CalculatorsServiceProvider;
use App\Modules\TaxRelief\TaxReliefServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TaxReliefServiceProvider::class,
    CalculatorsServiceProvider::class,
];
