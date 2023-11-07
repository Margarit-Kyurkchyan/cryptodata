<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::group([
    'prefix' => 'coin'
], function () {
    Route::get('history', 'CoinmarketController@historical');
    Route::get('cryptocurrency', 'CoinmarketController@cryptocurrency');
    Route::get('market_pairs', 'CoinmarketController@marketPairs');
    Route::get('map', 'CoinmarketController@mapAll');
    Route::get('config', 'CoinmarketController@config');
    Route::get('time', 'Controller@timeMethod');
    Route::get('symbols', 'CoinmarketController@symbols');
    Route::get('search', 'CoinmarketController@searchMethod');
    Route::get('timescale_marks', 'CoinmarketController@timescaleMarks');
    Route::get('currency', 'CoinmarketController@getCurrency');
    Route::get('ticker', 'CoinmarketController@ticker');
    Route::get('vidjetCurrency', 'CoinmarketController@tickerOld');
    Route::put('allow_unset_currency_pair', 'CoinmarketController@allowUnsetCurrencyPair');
    Route::get('all_pairs', 'CoinmarketController@getAllPairs');
    Route::get('favorite', 'CoinmarketController@getFavoriteCoins');
    Route::get('ticker/coins', 'CoinmarketController@getTickerCoins');
    Route::get('top_market_pairs', 'CoinmarketController@getTopMarketPairs');
});
Route::group([
    'prefix' => 'top'
], function () {
    Route::get('tn_indexes/charts', 'TnIndexesController@getCharts');
});
Route::group([
    'prefix' => 'v2'
], function () {
    Route::get('coin/config', 'CcxtOhlcvController@config');
    Route::get('coin/history', 'CcxtOhlcvController@ohlcvHistory');
    Route::get('coin/symbols', 'CcxtOhlcvController@symbols');
    Route::get('coin/search', 'CcxtOhlcvController@searchMethod');
    Route::get('coin/time', 'Controller@timeMethod');
});

Route::group([
    'prefix' => 'exchange'
], function () {
    Route::get('map', 'ExchangeController@mapAll');
    Route::get('listings', 'ExchangeController@listings');
    Route::post('market_pairs', 'ExchangeController@marketPairs');
});

Route::group([
    'prefix' => 'global-metrics'
], function () {
    Route::get('latest', 'GlobalMetricsController@latest');
    Route::get('history', 'GlobalMetricsController@historical');
    Route::get('config', 'GlobalMetricsController@config');
    Route::get('charts', 'GlobalMetricsController@getChartsData');
});

Route::group([
    'prefix' => 'statistic'
], function () {
    Route::get('requests', 'StatisticController@requests');
});

Route::group([
    'prefix' => 'volatility'
], function () {
    Route::get('config', 'CoefficientController@config');
    Route::get('time', 'Controller@timeMethod');
    Route::get('symbols', 'CoefficientController@symbols');
    Route::get('search', 'CoefficientController@searchMethod');
    Route::get('history', 'CoefficientController@volatilityHistorical');
});

Route::group([
    'prefix' => 'sharpe'
], function () {
    Route::get('config', 'CoefficientController@config');
    Route::get('time', 'Controller@timeMethod');
    Route::get('symbols', 'CoefficientController@symbols');
    Route::get('search', 'CoefficientController@searchMethod');
    Route::get('history', 'CoefficientController@sharpeHistorical');
});
Route::group([
    'prefix' => 'alpha'
], function () {
    Route::get('config', 'CoefficientController@config');
    Route::get('time', 'Controller@timeMethod');
    Route::get('symbols', 'CoefficientController@symbols');
    Route::get('search', 'CoefficientController@searchMethod');
    Route::get('history', 'CoefficientController@alphaHistorical');
});
Route::group([
    'prefix' => 'beta'
], function () {
    Route::get('config', 'CoefficientController@config');
    Route::get('time', 'Controller@timeMethod');
    Route::get('symbols', 'CoefficientController@symbols');
    Route::get('search', 'CoefficientController@searchMethod');
    Route::get('history', 'CoefficientController@betaHistorical');
});

Route::group([
    'prefix' => 'coefficients'
], function () {
    Route::get('charts', 'CoefficientController@getChartsData');
    Route::get('global_charts', 'CoefficientController@getGlobalChartsData');
});

Route::get('widget_cryptomarket', 'CoinmarketController@widgetCryptomarket');

Route::get('currency_history', 'CryptocurrencyController@getCurrentHistory');
Route::get('compare/coins', 'CryptocurrencyController@compareCoins');

Route::group([
    'prefix' => 'charts'
], function () {
    Route::get('currency_cap', 'CapitalizationController@getChartsDataByTicker');
});
