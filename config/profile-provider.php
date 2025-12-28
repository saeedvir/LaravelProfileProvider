<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Threshold
    |--------------------------------------------------------------------------
    |
    | The default threshold in seconds for marking providers as slow.
    |
    */
    'threshold' => 0.01,

    /*
    |--------------------------------------------------------------------------
    | Default Top Providers
    |--------------------------------------------------------------------------
    |
    | The default number of top slowest providers to display.
    |
    */
    'top' => 20,

    /*
    |--------------------------------------------------------------------------
    | Default Sort Field
    |--------------------------------------------------------------------------
    |
    | The default field to sort providers by (total, register, boot, memory).
    |
    */
    'sort' => 'total',

    /*
    |--------------------------------------------------------------------------
    | Cache TTL Hours
    |--------------------------------------------------------------------------
    |
    | How many hours to keep previous run data for comparison.
    |
    */
    'cache_ttl_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Maximum Provider Name Length
    |--------------------------------------------------------------------------
    |
    | Maximum length for provider names in output tables.
    |
    */
    'max_provider_name_length' => 50,
];
