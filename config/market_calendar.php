<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Market Calendar Configuration
    |--------------------------------------------------------------------------
    |
    | Holiday/trading-day data sourced from apptastic-software/trading-calendar
    | (see database/data/NOTICE.md), bundled as a static JSON file and read by
    | pure PHP — the production server runs PHP only, so nothing here ever
    | shells out to Python. JsonTradingCalendarProvider reads this file.
    |
    */

    'mic' => env('MARKET_CALENDAR_MIC', 'XBOM'),

    'source_file' => env(
        'MARKET_CALENDAR_SOURCE_FILE',
        database_path('data/xbom_market_holidays.json')
    ),

];
