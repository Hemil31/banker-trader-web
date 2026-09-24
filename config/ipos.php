<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IPO provider
    |--------------------------------------------------------------------------
    |
    | Default path read by the JSON-backed IpoProvider (see
    | App\Contracts\Ipo\JsonFileIpoProvider). Swap IpoProvider's binding in
    | AppServiceProvider for a live IPO Ji scraper/API when one is wired.
    |
    */

    'source_file' => env('IPOS_SOURCE_FILE', database_path('data/ipos.json')),

];
