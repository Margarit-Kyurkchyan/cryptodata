<?php

return [
    'coinmarketcup' => [
        'cryptocurrency' => [
            'base_url' => env('API_COIN') . 'cryptocurrency/listings/latest',
            'limit' => 100
        ],
        'cryptocurrency_ohlcv' => [
            'base_url' => env('API_COIN') . 'cryptocurrency/ohlcv/historical'
        ],
        'global_metrics_historical' => [
            'base_url' => env('API_COIN') . 'global-metrics/quotes/historical'
        ],
        'exchanges' => [
            'base_url' => env('API_COIN') . 'exchange/listings/latest',
            'limit' => 100
        ],
        'api_key' => env('API_COIN_KEY'),
    ]
];
