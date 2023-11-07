<?php

namespace App\Services;

use App\CcxtOhlcv;
use App\Cryptocurrency;
use App\DataProviders\CryptoCurrencyDataProvider;
use App\Exceptions\CoinsLimitException;
use App\Exceptions\EmptyEntityListException;
use App\Exchange;
use App\OhlcvQuote;
use App\Platform;
use App\Quote;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\EntityNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class CryptoCurrencyService
{
    const PRICE_SYMBOL = '$';
    const PERCENT_CHANGE_SYMBOL = '%';
    const QUOTES_CURRENCY_SYMBOL = 'USD';
    const MARKET_CAP_ORDER_DEFAULT = 1000000;
    const TOP_LIMIT = 10;
    const DAILY_HISTORY_INTERVAL = 'daily';
    const WEEKLY_HISTORY_INTERVAL = 'weekly';
    const HOURLY_HISTORY_INTERVAL = 'hourly';
    const MONTHLY_HISTORY_INTERVAL = 'monthly';
    const MINUTELY_HISTORY_INTERVAL = 'minutely';
    const DEFAULT_COINS_QUOTES_SYMBOL = 'USD';

    const COMPARE_STEP_MINUTE = 'minute';
    const COMPARE_STEP_HOUR = 'hour';
    const COMPARE_STEP_DAY = 'day';
    const COMPARE_STEP_WEEK = 'week';
    const DAY_DURATION_IN_SECONDS = 86400;
    const CHART_TYPE_SORTINO = 'sortino';
    const CHART_TYPE_SHARPE = 'sharpe';
    const CHART_TYPE_VOLATILITY = 'volatility';
    const CURRENCY_FIAT = 'fiat';
    const CURRENCY_CRYPTO = 'cryptocurrency';

    public function getWidgetCryptoMarketTopData(): array
    {
        $cryptocurrencies = $this->getCryptocurrenyWithQuotes();
        $crypts = $this->combineCurrencyWithQuotesData($cryptocurrencies);
        $exchanges = $this->getExchangesWithQuotes();
        $formattedExchangesData = $this->forrmatExchangesData($exchanges);
        $data = [
            'status' => [
                "error_code" => 0,
                "error_message" => null,
            ],
            'top10_crypto' => $crypts,
            'top10_exchange' => $formattedExchangesData
        ];
        return $data;
    }

    protected function getCryptocurrenyWithQuotes(string $quoteSymbol = self::QUOTES_CURRENCY_SYMBOL, int $limit = self::TOP_LIMIT): Collection
    {
        $cryptocurrencies = Cryptocurrency::query()
            ->select(
                '*',
                DB::raw('IF(market_cap_order IS NOT NULL, market_cap_order, 1000000) market_cap_order')
            )
            ->limit($limit)
            ->offset(0)
            ->orderBy('market_cap_order')
            ->with(['quotes' => function ($q) use ($quoteSymbol) {
                $q
                    ->where('symbol', $quoteSymbol)
                    ->select(
                        'cryptocurrency_id',
                        'symbol',
                        'price',
                        'volume_24h',
                        'percent_change_24h',
                        'percent_change_1h',
                        'percent_change_7d',
                        'market_cap',
                        'last_updated'
                    );
            }])
            ->get();
        return $cryptocurrencies;
    }

    protected function combineCurrencyWithQuotesData(Collection $cryptocurrencies): array
    {
        $crypts = [];
        if ($cryptocurrencies->isEmpty()) {
            return $crypts;
        }
        foreach ($cryptocurrencies as $key => $cryptocurrency) {
            $quote = $cryptocurrency['quotes'][0];
            $crypts[] = [
                'top_number' => $key + 1,
                'symbol' => $cryptocurrency->symbol,
                'name' => $cryptocurrency->name,
                'logo' => URL::to('/') . $cryptocurrency->logo_2,
                'price' => round($quote->price, 2),
                'price_symbol' => self::PRICE_SYMBOL,
                'percent_change_24h' => round($quote->percent_change_24h, 2),
                'percent_change_24h_symbol' => self::PERCENT_CHANGE_SYMBOL,
                'increase' => ($quote->percent_change_24h >= 0) ? 1 : 0
            ];

        }
        return $crypts;
    }

    protected function getExchangesWithQuotes(string $quoteSymbol = self::QUOTES_CURRENCY_SYMBOL, int $limit = self::TOP_LIMIT)
    {
        $exchanges = Exchange::query()
            ->select('exchanges.*', 'exchange_quotes.volume_24h', 'exchange_quotes.percent_change_volume_24h')
            ->leftJoin('exchange_quotes', function ($join) use ($quoteSymbol) {
                $join
                    ->on('exchange_quotes.exchange_id', '=', 'exchanges.exchange_id')
                    ->where('symbol', $quoteSymbol);
            })
            ->orderBy('exchange_quotes.volume_24h', 'DESC')
            ->offset(0)
            ->limit($limit)
            ->get();
        return $exchanges;
    }

    protected function forrmatExchangesData(Collection $exchanges): array
    {
        $formattedData = [];
        foreach ($exchanges as $key => $exchange) {
            $formattedData[] = [
                'rank' => $key + 1,
                'name' => $exchange->name,
                'logo' => URL::to('/') . $exchange->logo_2,
                'volume_24h' => round($exchange->volume_24h, 2),
                'percent_volume_24h' => round($exchange->percent_change_volume_24h, 2),
                'increase' => ($exchange->percent_change_volume_24h >= 0) ? 1 : 0
            ];
        }
        return $formattedData;
    }

    public function getTickerCoinsBySymbols(array $symbols, string $quoteSymbol = self::QUOTES_CURRENCY_SYMBOL): array
    {
        $coins = Cryptocurrency::query()
            ->orderBy('market_cap_order')
            ->with(['quotes' => function ($q) use ($quoteSymbol) {
                $q->where('symbol', $quoteSymbol);
            }])
            ->whereIn('symbol', $symbols)
            ->get();
        $tickerCoinsData = [];
        foreach ($coins as $coin) {
            if (!isset($coin->quotes[0])) {
                continue;
            }
            $quote = $coin->quotes[0];
            $tickerCoinsData[] = [
                'symbol' => $coin->symbol,
                'name' => $coin->name,
                'price' => round($quote->price, 3),
                'price_symbol' => self::PRICE_SYMBOL,
                'percent_change_24h' => round($quote->percent_change_24h, 3),
                'percent_change_24h_symbol' => self::PERCENT_CHANGE_SYMBOL,
                'increase' => ($quote->percent_change_24h >= 0) ? 1 : 0
            ];

        }
        return $tickerCoinsData;
    }

    public function getFavoriteCoinsBySymbols(array $symbols): array
    {
        $coins = Cryptocurrency::query()
            ->whereIn('symbol', $symbols)
            ->with(['quotes' => function ($query) {
                $query->where('symbol', self::DEFAULT_COINS_QUOTES_SYMBOL);
            }])
            ->get();

        $favoriteCoinsData = [];
        foreach ($coins as $coin) {
            if (!isset($coin->quotes[0])) {
                continue;
            }
            $quote = $coin->quotes[0];
            $favoriteCoinsData[] = [
                'symbol' => $coin->symbol,
                'name' => $coin->name,
                'price' => round($quote->price, 3),
                'volume_24' => round($quote->volume_24h, 3)
            ];
        }
        return $favoriteCoinsData;
    }

    public function getCryptoCurrencyHistoryDataBySymbol(string $symbol)
    {
        $cryptoModel = Cryptocurrency::query()->where('symbol', $symbol);
        if ($cryptoModel->count() === 0) {
            return [];
        }
        $historicalData = $this->getCachedCurrencyHistoryData($cryptoModel, $symbol);
        return $historicalData;
    }

    public function getCryptocurrencies($sort, $sortDir, $limit, $autocomplete = '')
    {
        $GLOBALS['autocomplete'] = $autocomplete;
        $cryptocurrencies = Cryptocurrency::leftJoin('quotes', 'quotes.cryptocurrency_id', '=',
            'cryptocurrencies.cryptocurrency_id')
            ->select(
                'cryptocurrencies.name AS name',
                'cryptocurrencies.symbol AS symbol',
                'cryptocurrencies.circulating_supply AS circulating_supply',
                'logo_2 AS logo',
                DB::raw('circulating_supply / max_supply * 100 as circulating_supply_percent'),
                'quotes.price AS price',
                'quotes.volume_24h AS volume_24h',
                'quotes.percent_change_24h AS percent_change_24h',
                'quotes.percent_change_1h AS percent_change_1h',
                'quotes.percent_change_7d AS percent_change_7d',
                'quotes.market_cap AS market_cap'
            )
            ->orderBy($sort, $sortDir)
            ->where('quotes.symbol', 'USD')
            ->where(function ($query) {
                $query->orWhere('cryptocurrencies.name', 'LIKE', $GLOBALS['autocomplete'] . '%')
                    ->orWhere('cryptocurrencies.symbol', 'LIKE', $GLOBALS['autocomplete'] . '%');
            })
            ->paginate($limit);


        $koeficent = $cryptocurrencies->avg('market_cap');
        $cryptocurrencies = $cryptocurrencies->toArray();


        foreach ($cryptocurrencies['data'] as $key => $cryptocurrency) {
            $cryptocurrencies['data'][$key]['rank'] = $key + 1;
            $cryptocurrencies['data'][$key]['circulating_supply'] = floatval($cryptocurrency['circulating_supply']);
            $cryptocurrencies['data'][$key]['logo'] = URL::to('/') . $cryptocurrency['logo'];
            $cryptocurrencies['data'][$key]['circulating_supply_percent'] = floatval($cryptocurrency['circulating_supply_percent']);
            $cryptocurrencies['data'][$key]['price'] = floatval($cryptocurrency['price']);
            $cryptocurrencies['data'][$key]['volume_24h'] = floatval($cryptocurrency['volume_24h']);
            $cryptocurrencies['data'][$key]['percent_change_24h'] = floatval($cryptocurrency['percent_change_24h']);
            $cryptocurrencies['data'][$key]['percent_change_1h'] = floatval($cryptocurrency['percent_change_1h']);
            $cryptocurrencies['data'][$key]['percent_change_7d'] = floatval($cryptocurrency['percent_change_7d']);
            $cryptocurrencies['data'][$key]['market_cap'] = floatval($cryptocurrency['market_cap']);
            $cryptocurrencies['data'][$key]['increase'] = ($cryptocurrency['percent_change_24h'] >= 0) ? 1 : 0;
            $cryptocurrencies['data'][$key]['Wx'] = ($koeficent) ? floatval($cryptocurrency['market_cap'] / $koeficent) : 0;
        }

        return $cryptocurrencies;

    }

    public function getCryptocurrenciesWithFilters($sort, $sortDir, $limit, $autocomplete = '', $market_cap_filter, $price_filter, $volume24_filter) {
        $GLOBALS['autocomplete'] = $autocomplete;
        $market_cap = $this->getMarketCap($market_cap_filter);
        $price = $this->getPrice($price_filter);
        $volume24 = $this->getVolume24($volume24_filter);
        $now_page = (isset($_GET['page'])) ? $_GET['page'] : 1;
        $rank_start= ((int)$now_page - 1) * (int)$limit;
        $cryptocurrencies = Cryptocurrency::leftJoin('quotes', 'quotes.cryptocurrency_id', '=',
            'cryptocurrencies.cryptocurrency_id')
            ->select(
                'cryptocurrencies.name AS name',
                'cryptocurrencies.symbol AS symbol',
                'cryptocurrencies.circulating_supply AS circulating_supply',
                'logo_2 AS logo',
                DB::raw('circulating_supply / max_supply * 100 as circulating_supply_percent'),
                'quotes.price AS price',
                'quotes.volume_24h AS volume_24h',
                'quotes.percent_change_24h AS percent_change_24h',
                'quotes.percent_change_1h AS percent_change_1h',
                'quotes.percent_change_7d AS percent_change_7d',
                'quotes.market_cap AS market_cap'
            )
            ->orderBy($sort, $sortDir)
            ->where('quotes.symbol', 'USD')
            ->whereBetween('market_cap',$market_cap)
            ->whereBetween('price',$price)
            ->whereBetween('volume_24h',$volume24)
            ->where(function($query)
            {   
                $query->orWhere('cryptocurrencies.name', 'LIKE', $GLOBALS['autocomplete'] . '%')
                      ->orWhere('cryptocurrencies.symbol', 'LIKE', $GLOBALS['autocomplete'] . '%');
            })
            ->paginate($limit);


        $koeficent = $cryptocurrencies->avg('market_cap');
        $cryptocurrencies = $cryptocurrencies->toArray();


        foreach ($cryptocurrencies['data'] as $key => $cryptocurrency) {
            $cryptocurrencies['data'][$key]['rank'] = $rank_start + $key + 1;
            $cryptocurrencies['data'][$key]['circulating_supply'] = floatval($cryptocurrency['circulating_supply']);
            $cryptocurrencies['data'][$key]['logo'] = URL::to('/') . $cryptocurrency['logo'];
            $cryptocurrencies['data'][$key]['circulating_supply_percent'] = floatval($cryptocurrency['circulating_supply_percent']);
            $cryptocurrencies['data'][$key]['price'] = floatval($cryptocurrency['price']);
            $cryptocurrencies['data'][$key]['volume_24h'] = floatval($cryptocurrency['volume_24h']);
            $cryptocurrencies['data'][$key]['percent_change_24h'] = floatval($cryptocurrency['percent_change_24h']);
            $cryptocurrencies['data'][$key]['percent_change_1h'] = floatval($cryptocurrency['percent_change_1h']);
            $cryptocurrencies['data'][$key]['percent_change_7d'] = floatval($cryptocurrency['percent_change_7d']);
            $cryptocurrencies['data'][$key]['market_cap'] = floatval($cryptocurrency['market_cap']);
            $cryptocurrencies['data'][$key]['increase'] = ($cryptocurrency['percent_change_24h'] >= 0) ? 1 : 0;
            $cryptocurrencies['data'][$key]['Wx'] = ($koeficent) ? floatval($cryptocurrency['market_cap'] / $koeficent) : 0;
        }

        return $cryptocurrencies;

    }
    public function getCcxtOjlcvData($base, $quote, $timeStart, $timeEnd, $interval)
    {
        $data = CcxtOhlcv::query()->where('base', $base)
            ->where('quote', $quote)
            ->where('timestamp', '>=', $timeStart)
            ->where('timestamp', '<=', $timeEnd)
            ->where('interval', $interval)
            ->orderBy('timestamp')->get();

        if ($data->count() > 0) {
            $returnData = [];
            $returnData['s'] = "ok";
            $returnData['c'] = [];
            $returnData['h'] = [];
            $returnData['l'] = [];
            $returnData['o'] = [];
            $returnData['t'] = [];
            $returnData['v'] = [];

            foreach ($data as $item) {
                $returnData['c'][] = floatval($item->close);
                $returnData['h'][] = floatval($item->high);
                $returnData['l'][] = floatval($item->low);
                $returnData['o'][] = floatval($item->open);
                $returnData['t'][] = strtotime($item->timestamp);
                $returnData['v'][] = floatval($item->volume);
            }

        } else {
            $returnData = [];
            $returnData['s'] = "no_data";
        }
        return $returnData;

    }

    public function getCcxtExchanges()
    {
        $exchangesName = CcxtOhlcv::query()
            ->groupBy('exchange_name')
            ->pluck('exchange_name')->toArray();

        $exchanges = Exchange::query()
            ->select(
                'name',
                'exchanges.name as desc',
                'slug as value'
            )->whereIn('slug', $exchangesName)->get()->toArray();
        $allExchanges = [
            [
                'name' => "All Exchanges",
                'desc' => "",
                'value' => ""
            ]
        ];
        return array_merge($allExchanges, $exchanges);
    }

    public function getSearchData($query, $query2, $limit, $exchange)
    {
        $searchResult = CcxtOhlcv::query()
            ->select(
                DB::raw("CONCAT(base, '/',quote) AS full_name"),
                DB::raw("CONCAT(base, '/',quote) AS symbol"),
                DB::raw("CONCAT(base, '/',quote) AS description"),
                DB::raw("'bitcoin' as type"),
                'exchange_name as exchange'
            )
            ->where('base', 'like', '%' . $query . '%')
            ->where('quote', 'like', '%' . $query2 . '%')
            ->limit($limit)
            ->groupBy('base', 'quote', 'exchange');
        if ($exchange) {
            $searchResult->where('exchange_name', $exchange);
        }
        return $searchResult->get();

    }

    public function getCryptocurrencyWithCoefficients($symbol, $convert, $timeStart, $timeEnd, $interval, $coeffName)
    {
        $cryptocurrencyWithCoefficients = $this->getCurrencyWithCoefficients($symbol, $timeStart, $timeEnd, $interval, $convert);

        if (!$cryptocurrencyWithCoefficients) {
            return false;
        }

        $returnData = [];
        $returnData['s'] = "ok";
        $returnData['c'] = [];
        $returnData['h'] = [];
        $returnData['l'] = [];
        $returnData['o'] = [];
        $returnData['t'] = [];
        $returnData['v'] = [];

        foreach ($cryptocurrencyWithCoefficients->coefficients as $coefficient) {
            $returnData['c'][] = floatval($coefficient[$coeffName]);
            $returnData['h'][] = floatval($coefficient[$coeffName]);
            $returnData['l'][] = floatval($coefficient[$coeffName]);
            $returnData['o'][] = floatval($coefficient[$coeffName]);
            $returnData['t'][] = strtotime($coefficient->c_date);
            $returnData['v'][] = floatval($coefficient[$coeffName]);
        }

        return $returnData;
    }

    public function getCryptocurrencyWithOhlcv($cIds, $dateFrom, $dateTo, $interval)
    {
        $cryptocurrenciesWithQuotes = Cryptocurrency::select([
            '*',
            DB::raw('IF(`market_cap_order` IS NOT NULL, `market_cap_order`, 1000000) `market_cap_order`')
        ])
            ->with([
                'ohlcvQuotes' => function ($q) use ($dateFrom, $dateTo, $interval) {
                    $q->select('cryptocurrency_id', 'convert', 'close', 'timestamp')
                        ->where('time_period', $interval)
                        ->where('interval', $interval)
                        ->where('convert', 'USD')
                        ->whereDate('timestamp', '>=', $dateFrom)
                        ->whereDate('timestamp', '<=', $dateTo)
                        ->orderBy('timestamp');
                }
            ])->whereIn('cryptocurrency_id', $cIds)->limit(200)->orderBy('market_cap_order')->get();
        return $cryptocurrenciesWithQuotes;
    }

    public function getCryptocurrencyWithHourlyOhlcv(array $cIds, string $date)
    {
        $cryptocurrenciesWithQuotes = Cryptocurrency::select([
            '*',
            DB::raw('IF(`market_cap_order` IS NOT NULL, `market_cap_order`, 1000000) `market_cap_order`')
        ])
            ->with([
                'ohlcvQuotes' => function ($q) use ($date) {
                    $q->select('cryptocurrency_id', 'convert', 'open', 'high', 'low', 'close', 'volume', 'time_open',
                        'time_close', 'timestamp')
                        ->where('time_period', 'hourly')
                        ->where('interval', 'hourly')
                        ->where('convert', 'USD')
                        ->whereDate('timestamp', $date)
                        ->orderBy('timestamp');
                }
            ])->whereIn('cryptocurrency_id', $cIds)->orderBy('market_cap_order')->get();

        return $cryptocurrenciesWithQuotes;
    }

    public function getMonthlyQuotes($cryptocurrency, $lastMonth)
    {
        $monthlyQuotes = OhlcvQuote::query()
            ->select('close')
            ->where('time_period', 'daily')
            ->where('interval', 'daily')
            ->where('convert', 'USD')
            ->where('cryptocurrency_id', $cryptocurrency->cryptocurrency_id)
            ->whereMonth('timestamp', '=', date('m', $lastMonth))
            ->whereYear('timestamp', '=', date('Y', $lastMonth))->get();
        return $monthlyQuotes;
    }

    public function getDailyQuotes($cryptocurrency, $date)
    {
        $xDaily = OhlcvQuote::query()
            ->select('close')
            ->where('time_period', 'daily')
            ->where('interval', 'daily')
            ->where('convert', 'USD')
            ->where('cryptocurrency_id', $cryptocurrency->cryptocurrency_id)
            ->whereDate('timestamp', $date)->first();
        return $xDaily;

    }

    public function getChartDataBySymbolAndType($symbol, string $chartType, string $periodStartDate, string $periodEndDate, string $step)
    {
        $data = [];
        $cryptocurrencyWithCoefficients = $this->getCurrencyWithCoefficients($symbol, $periodStartDate, $periodEndDate, $step, self::DEFAULT_COINS_QUOTES_SYMBOL);

        if (!$cryptocurrencyWithCoefficients) {
            return false;
        }

        foreach ($cryptocurrencyWithCoefficients->coefficients as $coefficient) {
            $data[] = [
                'time' => $coefficient->c_date,
                'value' => $coefficient[$chartType]
            ];
        }

        return $data;
    }

    protected function getCachedCurrencyHistoryData(Builder $cryptoModel, string $symbol, bool $cache = false): array
    {
        $cacheKeyPrefix = strtolower($symbol);
        if ($cache) {
            $historicalData = Cache::remember($cacheKeyPrefix . '_cryptocurrency_history', 60, function () use ($cryptoModel) {
                return $this->getRawCurrencyHistoryData($cryptoModel);
            });
        } else {
            $historicalData = $this->getRawCurrencyHistoryData($cryptoModel);
        }
        return $historicalData;

    }

    protected function getRawCurrencyHistoryData(Builder $cryptoModel): array
    {
        /* @var Cryptocurrency $cryptoData */
        $cryptoData = $cryptoModel
            ->with(['ohlcvQuotes' => function ($query) {
                $query
                    ->where('convert', self::QUOTES_CURRENCY_SYMBOL)
                    ->where('interval', self::DAILY_HISTORY_INTERVAL);
            }])->first();
        $historicalData = [];
        $onlcvQuotes = $cryptoData->ohlcvQuotes;
        foreach ($onlcvQuotes as $quote) {
            $historicalData[] = [
                'date' => date('d.m.Y', strtotime($quote->timestamp)),
                'open' => $quote->open,
                'high' => $quote->high,
                'low' => $quote->low,
                'close' => $quote->close,
                'market_cap' => $quote->market_cap
            ];
        }
        return $historicalData;
    }

    public function getCoinsCompareData($coins, string $periodStartDate, string $periodEndDate, string $step, array $data)
    {
        if (empty($coins)) {
            throw new EmptyEntityListException('Coins list can\'t by empty');
        }
        $coins = explode(',', $coins);
        $existCoins = Cryptocurrency::whereIn('symbol', $coins)->get();
        if ($existCoins->isEmpty()) {
            throw new EntityNotFoundException(OhlcvQuote::class, implode(',', $coins));
        }
        if ($existCoins->count() > 10) {
            throw new CoinsLimitException('The number of coins exceeded the limit 10');
        }
        $filtersFront = $this->getFrontCoinFilters($existCoins);
        $data['filters_front']['crypto_items'] = array_values($filtersFront);
        $stepTimestamp = $this->getTimestampStep($step);
        $dateIntervalFormat = $this->getDateIntervalFormatByStep($step);
        $periodStartDate = $this->getStartDateAsTimestamp($periodStartDate, $stepTimestamp);
        $periodEndDate = $this->getEndDateAsTimestamp($periodEndDate);
        $periodEndDate = $periodEndDate + $stepTimestamp;
        $cryptocrrencyIds = array_keys($filtersFront);
        $ohlcvData = $this->getOhlcvDataPeriodByIds($cryptocrrencyIds, $periodStartDate, $periodEndDate, $dateIntervalFormat);
        $dateIntervalFormat = str_replace('%', '' ,$dateIntervalFormat);
        $coinsInformation = [];
        $startFrom = $periodStartDate;
        $groupOhlcvData = $ohlcvData->groupBy('timestamp');
        foreach ($groupOhlcvData as $date => $datum) {
            if ($date >= date($dateIntervalFormat, $startFrom)) {
                for(; $date >= date($dateIntervalFormat, $startFrom); $startFrom += $stepTimestamp) {
                    if ($startFrom > $periodEndDate) {
                        break;
                    }
                    $values = [];
                    foreach ($datum as $coins) {
                        if (array_key_exists($coins->cryptocurrency_id, $values)) {
                            continue;
                        }
                        $values[$coins->cryptocurrency_id] = [
                            'value' => round($coins->close, 2),
                            'crypto_id' => $filtersFront[$coins->cryptocurrency_id]['id']
                        ];
                    }
                    $coinsInformation[] = [
                        'time' => date($dateIntervalFormat, $startFrom),
                        'value' => array_values($values)
                    ];
                }
            }
            continue;
        }

        $data['filters']['period_date_start'] = date('Y-m-d', $periodStartDate);
        $data['filters']['period_date_end'] = date('Y-m-d', $periodEndDate);
        $data['data'] = $coinsInformation;
        return $data;
    }

    protected function getCurrencyWithCoefficients($symbol, $timeStart, $timeEnd, $interval, $convert) {
        $cryptocurrencyWithCoefficients = Cryptocurrency::with(['coefficients' => function ($q) use ($timeStart, $timeEnd, $interval, $convert) {
            $q->select('cryptocurrency_id', 'volatility', 'sharpe', 'alpha', 'beta', 'sortino', 'c_date')
                ->whereBetween('c_date', [$timeStart, $timeEnd])
                ->where('interval', $interval)
                ->where('convert', $convert)
                ->orderBy('c_date');
        }])->where('symbol', $symbol)->first();

        if (!$cryptocurrencyWithCoefficients || $cryptocurrencyWithCoefficients->coefficients->count() === 0) {
            return false;
        }

        return $cryptocurrencyWithCoefficients;
    }

    protected function getFrontCoinFilters(Collection $existCoins): array
    {
        $filtersFront = [];
        $coinsDataWithId = [];
        foreach ($existCoins as $key => $coin) {
            $coinsData = [
                'id' => $key + 1,
                'name' => $coin->symbol
            ];
            $filtersFront[$coin->cryptocurrency_id] = $coinsData;
        }
        return $filtersFront;
    }

    protected function getOhlcvDataPeriodByIds(array $cryptocrrencyIds, int $periodStartDate, int $periodEndDate, string $dateIntervalFormat): Collection
    {
        return OhlcvQuote::whereIn('cryptocurrency_id', $cryptocrrencyIds)
            ->select(
                '*',
                DB::raw("DATE_FORMAT(timestamp, '" . $dateIntervalFormat . "') as timestamp"))
            ->where('convert', 'USD')
            ->whereBetween('timestamp', [date('Y-m-d H:i:s', $periodStartDate), date('Y-m-d H:i:s', $periodEndDate)])
            ->orderBy('timestamp')
            ->get();
    }

    protected function getDateIntervalFormatByStep(string $step)
    {
        switch ($step) {
            case self::COMPARE_STEP_MINUTE:
                return '%Y-%m-%d %H:%i';
            case self::COMPARE_STEP_HOUR:
                return '%Y-%m-%d %H';
            case self::COMPARE_STEP_DAY:
                return '%Y-%m-%d';
            case self::COMPARE_STEP_WEEK:
                return '%Y-%m-%d';
            default:
                return '%Y-%m-%d';
        }
    }

    public static function getPeriodIntervals(): array
    {
        return [
            self::COMPARE_STEP_WEEK,
            self::COMPARE_STEP_HOUR,
            self::COMPARE_STEP_DAY,
            self::COMPARE_STEP_MINUTE,
        ];
    }
    public static function getPeriodIntervals2(): array
    {
        return [
            self::MONTHLY_HISTORY_INTERVAL,
            self::WEEKLY_HISTORY_INTERVAL,
            self::HOURLY_HISTORY_INTERVAL,
            self::DAILY_HISTORY_INTERVAL,
            self::MINUTELY_HISTORY_INTERVAL,
        ];
    }

        public static function getChartTypes(): array
    {
        return [
            self::CHART_TYPE_SORTINO,
            self::CHART_TYPE_SHARPE,
            self::CHART_TYPE_VOLATILITY
        ];
    }


    protected function getStartDateAsTimestamp(string $startAt,int $stepTimestamp): int
    {
        $today = Carbon::now()->startOfDay()->timestamp;
        $startAt = $startAt ? strtotime($startAt) : $today - $stepTimestamp;
        return $startAt;
    }

    protected function getEndDateAsTimestamp($endAt): int
    {
        $today = Carbon::now()->startOfDay()->timestamp;
        $endAt = $endAt ? strtotime($endAt) : $today;
        return $endAt;
    }

    protected function getTimestampStep(string $step): int
    {
        switch ($step) {
            case self::COMPARE_STEP_MINUTE:
                return 60;
            case self::COMPARE_STEP_HOUR:
                return 60 * 60;
            case self::COMPARE_STEP_DAY:
                return self::DAY_DURATION_IN_SECONDS;
            case self::COMPARE_STEP_WEEK:
                return self::DAY_DURATION_IN_SECONDS * 7;
            default:
                return 60;
        }
    }

    public function getCryptoCurrencyApiData()
    {
        $dataProvider = new CryptoCurrencyDataProvider();
        $data = $dataProvider->getData();
        return $data;
    }

    public function updateCryptoCurrencyData(array $data)
    {
        $fiats = config('fiats');
        foreach ($data as $currency) {
            $currencyModel = Cryptocurrency::firstOrNew(['id' => $currency['id']]);
            $currencyModel->id = $currency['id'];
            $currencyModel->name = $currency['name'] ?? null;
            $currencyModel->symbol = $currency['symbol'] ?? null;
            $currencyModel->slug = $currency['slug'] ?? null;
            $currencyModel->circulating_supply = $currency['circulating_supply'] ?? null;
            $currencyModel->max_supply = $currency['max_supply'] ?? null;
            $currencyModel->date_added = $currency['date_added'] ?? null;
            $currencyModel->last_updated = $currency['last_updated'] ?? null;
            $currencyModel->num_market_pairs = $currency['num_market_pairs'] ?? 0;
            $currencyModel->total_supply = $currency['total_supply'] ?? null;
            $currencyModel->cmc_rank = $currency['cmc_rank'] ?? null;

            if (in_array($currency['symbol'], $fiats)) {
                $currencyModel->currency_type = self::CURRENCY_FIAT;
            } else {
                $currencyModel->currency_type = self::CURRENCY_CRYPTO;
            }

            if (!empty($currency['quote'])) {
                $currencyId = $currencyModel->cryptocurrency_id;
                $this->saveQuoteCoinsData($currency['quote'], $currencyId);
            }

            if (!empty($currency['platform'])) {
                $platform = $this->savePlatformData($currency['platform']);
                $currencyModel->platform_id = $platform->platform_id;
            }

            $currencyModel->save();
        }
    }

    public function savePlatformData(array $platform)
    {
        $platform = Platform::firstOrNew(['id' => $platform['id']]);
        $platform->id = $platform['id'];
        $platform->name = $platform['name'] ?? null;
        $platform->symbol = $platform['symbol'] ?? null;
        $platform->slug = $platform['slug'] ?? null;
        $platform->save();
        return $platform;
    }

    public function saveQuoteCoinsData(array $quotes, int $cryptoId)
    {
        foreach ($quotes as $quoteKey => $quoteValue) {
            $quote = Quote::firstOrNew(['symbol' => $quoteKey, 'cryptocurrency_id' => $cryptoId]);
            if (!$quotes) {
                $quotes = new Quote();
            }
            $quote->cryptocurrency_id = $cryptoId;
            $quote->symbol = $quoteKey;
            $quote->price = $quoteValue['price'] ?? null;
            $quote->volume_24h = $quoteValue['volume_24h'] ?? null;
            $quote->percent_change_1h = $quoteValue['percent_change_1h'] ?? null;
            $quote->percent_change_24h = $quoteValue['percent_change_24h'] ?? null;
            $quote->percent_change_7d = $quoteValue['percent_change_7d'] ?? null;
            $quote->market_cap = $quoteValue['market_cap'] ?? null;
            $quote->last_updated = $quoteValue['last_updated'] ?? null;
            $quote->save();
        }
    }
    public function getMarketCapFilters(){
        return [
            '1BP',
            '1B',
            '100M',
            '10M',
            '1M',
            '100K',
        ];
    }
    public function getPriceFilters(){
        return [
            '100P',
            '100',
            '1',
            '0.0001',
        ];
    }
    public function getVolume24Filters(){
        return [
            '10MP',
            '10M',
            '1M',
            '100K',
            '10K',
        ];
    }
    public function getMarketCap($market_cap){
        switch ($market_cap) {
            case '1BP':
                return[1000000000, 10000000000000000];
                break;
            case '1B':
                return[10000000, 100000000];
                break;
            case '100M':
                return[10000000, 100000000];
                break;
            case '10M':
                return[1000000, 10000000];
                break;
            case '1M':
                return[100000, 1000000];
                break;
            case '100K':
                return[0, 100000];
                break;
            
            default:
                return[0, 10000000000000000];
                break;
        }
    }
    public function getPrice($price){
        switch ($price) {
            case '100P':
                return[100, 10000000000000000];
                break;
            case '100':
                return[1, 100];
                break;
            case '1':
                return[0.0001, 1];
                break;
            case '0.0001':
                return[0, 0.0001];
                break;
            
            default:
                return[0, 10000000000000000];
                break;
        }
    }
    public function getVolume24($volume24){
        switch ($volume24) {
            case '10MP':
                return[10000000, 10000000000000000];
                break;
            case '10M':
                return[1000000, 10000000];
                break;
            case '1M':
                return[100000, 1000000];
                break;
            case '100K':
                return[10000, 100000];
                break;
            case '10K':
                return[0, 10000];
                break;
            
            default:
                return[0, 10000000000000000];
                break;
        }
    }
}
