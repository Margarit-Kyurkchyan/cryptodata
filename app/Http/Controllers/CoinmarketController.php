<?php

namespace App\Http\Controllers;

use App\MarketPair;
use App\GlobalMetric;
use App\GlobalMetricsHistorical;
use App\OhlcvQuote;
use App\Services\CryptoCurrencyService;
use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Validator;
use Illuminate\Http\Request;
use App\Cryptocurrency;
use App\OhlcvRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use App\Services\ExchangePairQuoteService;


class CoinmarketController extends Controller
{
    /**
     * used cryptocurrency/ohlcv/historical
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function historical(Request $request)
    {
        $timePeriod = "daily";
        $symbol = null;
        $timeStart = null;
        $timeEnd = null;
        $count = 10;
        $interval = "daily";
        $convert = "USD";
        $curlString = env('API_COIN') . "cryptocurrency/ohlcv/historical";
        $query = [];

        $validator = Validator::make($request->all(), [
            'from' => 'required',
            'symbol' => 'required',
            'resolution' => 'string'
        ]);

        $resolutions = [
            'H' => 'hourly',
            '60' => 'hourly',
            "D" => 'daily',
            "1W" => 'weekly',
            "W" => 'weekly',
            "1M" => 'monthly',
            "M" => 'monthly',
            "365D" => 'yearly',
            "120" => '2h',
            "180" => '3h',
            "240" => '4h',
            "360" => '6h',
            "720" => '12h',
            "2D" => '2d',
            "3D" => '3d',
            "15D" => '15d',
            "90D" => '90d',
            "365D" => '365d',
            "14D" => '14d',
        ];

        if ($validator->fails()) {
            $this->saveRequest(400);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => '400'
            ], 400);
        }

        if ($request->get('symbol')) {
            $symbolArr = explode('/', $request['symbol']);
            $symbol = $symbolArr[0];

            if (!empty($symbolArr[1])) {
                $convert = $symbolArr[1];
            }

            $query['symbol'] = $symbol;
        }

        $timeStartStr = $request->get('from');
        $timeEndStr = $request->get('to');
        $timeStart = $timeStartStr ? date('Y-m-d h:00:00', $timeStartStr) : null;

        if ($timeEndStr) {
            $timeEnd = date('Y-m-d h:00:00', $timeEndStr);
            $query['time_end'] = $timeEnd;
        }

        $date1 = new DateTime($timeStart);
        $date2 = new DateTime($timeEnd);
        $diff = $date2->diff($date1);

        if ($diff->m >= 1) {
            $timeStart = date('Y-m-d h:00', strtotime($timeEnd . "-30 days"));
        }

        $resolution = $request->get('resolution');

        if ($resolution && !empty($resolutions[$resolution]) && $resolutions[$resolution] == 'hourly') {
            $interval = $resolutions[$request['resolution']];
            $timePeriod = 'hourly';
        } else {
            if (!empty($request['interval'])) {
                $interval = $request['interval'];
            }
        }

        $query['time_period'] = $timePeriod;
        $query['time_start'] = $timeStart;
        $query['count'] = $count;
        $query['interval'] = $interval;
        $query['convert'] = $convert;

        // get convert cryptocurrency
//        $convertCurrency = Cryptocurrency::where('symbol', $convert)->first();

//        $oldQuotes = OhlcvQuote::where('updated_at', '<', date('Y-m-d'));
//        $oldQuotes->delete();
        // todo remove remove old requests
//        $oldRequests = OhlcvRequest::where('updated_at', '<', date('Y-m-d'));
//        $oldRequests->delete();

        // get from ohlcv_requests table if there where such a request
        $ohlcvRequest = OhlcvRequest::where('symbol', $symbol)
            ->where('convert', $convert)
            ->where('time_start', '<=', $timeStart)
            ->where('time_end', '>=', $timeEnd)
            ->where('count', $count)
            ->where('interval', $interval)
            ->where('time_period', $timePeriod)
            ->first();

        $cryptocurrencyWithQuotes = Cryptocurrency::with([
            'ohlcvQuotes' => function ($q) use ($timeStart, $timeEnd, $timePeriod, $interval, $convert) {
                $q->select('cryptocurrency_id', 'convert', 'open', 'high', 'low', 'close', 'volume', 'time_open',
                    'time_close', 'timestamp')
                    ->where('timestamp', '>=', $timeStart)
                    ->where('timestamp', '<=', $timeEnd)
                    ->where('time_period', $timePeriod)
                    ->where('interval', $interval)
                    ->where('convert', $convert)
                    ->orderBy('timestamp');
            }
        ])->where('symbol', $symbol)->first([
            'cryptocurrency_id',
            'id',
            'symbol',
            'name'
        ]);

        if (empty($ohlcvRequest) || empty($cryptocurrencyWithQuotes) || count($cryptocurrencyWithQuotes['ohlcvQuotes']) === 0) {
            // get from the coinmarketcap api and save in the db result

            try {
                $client = new Client();

                $response = $client->get($curlString,
                    [
                        'headers' => ['X-CMC_PRO_API_KEY' => env('API_COIN_KEY')],
                        'query' => $query,
                    ]);
                $body = $response->getBody();
                $result = json_decode($body, true);

                if (!empty($result['data'])) {
                    $cryptocurrency = Cryptocurrency::where('id', $result['data']['id'])->first();

                    if (!$cryptocurrency) {
                        $cryptocurrency = new Cryptocurrency();
                    }

                    $cryptocurrency->id = $result['data']['id'];
                    $cryptocurrency->name = !empty($result['data']['name']) ? $result['data']['name'] : null;
                    $cryptocurrency->symbol = !empty($result['data']['symbol']) ? $result['data']['symbol'] : null;
                    $cryptocurrency->save();

                    $returnData = [];
                    $returnData['s'] = "ok";
                    $returnData['c'] = [];
                    $returnData['h'] = [];
                    $returnData['l'] = [];
                    $returnData['o'] = [];
                    $returnData['t'] = [];
                    $returnData['v'] = [];
                    $returnData['query'] = $query;
                    $returnData['coin_req'] = true;

                    foreach ($result['data']['quotes'] as $data) {
                        $dateTimestamp = new DateTime($data['quote'][$convert]['timestamp']);
                        $dateTimestampFormat = $dateTimestamp->format('Y-m-d H:i:s');
                        $ohlcvQuote = OhlcvQuote::where('timestamp', $dateTimestampFormat)
//                            ->where('time_close', $data['time_close'])
                            ->where('time_period', $timePeriod)
                            ->where('interval', $interval)->first();

                        if (!$ohlcvQuote) {
                            $ohlcvQuote = new OhlcvQuote();
                        }

                        // todo maybe we need this part of code only if $ohlcvQuote is empty ???
                        $ohlcvQuote->cryptocurrency_id = $cryptocurrency->cryptocurrency_id;
                        $ohlcvQuote->convert = $convert;
                        $ohlcvQuote->open = $data['quote'][$convert]['open'];
                        $ohlcvQuote->high = $data['quote'][$convert]['high'];
                        $ohlcvQuote->low = $data['quote'][$convert]['low'];
                        $ohlcvQuote->close = $data['quote'][$convert]['close'];
                        $ohlcvQuote->volume = $data['quote'][$convert]['volume'];
                        $ohlcvQuote->market_cap = $data['quote'][$convert]['market_cap'];
                        $ohlcvQuote->timestamp = $dateTimestamp;
                        $ohlcvQuote->time_open = $data['time_open'];
                        $ohlcvQuote->time_close = $data['time_close'];
                        $ohlcvQuote->interval = $interval;
                        $ohlcvQuote->time_period = $timePeriod;
                        $ohlcvQuote->save();
                        ////

                        $returnData['c'][] = floatval($data['quote'][$convert]['close']);
                        $returnData['h'][] = floatval($data['quote'][$convert]['high']);
                        $returnData['l'][] = floatval($data['quote'][$convert]['low']);
                        $returnData['o'][] = floatval($data['quote'][$convert]['open']);
                        $returnData['t'][] = strtotime($data['quote'][$convert]['timestamp']);
                        $returnData['v'][] = $data['quote'][$convert]['volume'];
                    }

                    if (empty($ohlcvRequest)) {
                        $ohlcvRequest = new OhlcvRequest();
                        $ohlcvRequest->time_start = $timeStart;
                        $ohlcvRequest->time_end = $timeEnd;
                    } else {

                        if ($ohlcvRequest->time_start >= $timeStart) {
                            $ohlcvRequest->time_start = $timeStart;
                        }

                        if ($ohlcvRequest->time_end <= $timeEnd) {
                            $ohlcvRequest->time_end = $timeEnd;
                        }
                    }

                    $ohlcvRequest->symbol = $symbol;
                    $ohlcvRequest->count = $count;
                    $ohlcvRequest->interval = $interval;
                    $ohlcvRequest->time_period = $timePeriod;
                    $ohlcvRequest->convert = $convert;
                    $ohlcvRequest->save();

                    $this->saveRequest(0, $result['status']['credit_count'], $cryptocurrency->symbol, $convert,
                        $curlString);
                    return response()->json($returnData, 201);
                } else {
                    $this->saveRequest(400, 0, $symbol, $convert, $curlString);
                    return response()->json([
                        's' => 'no_data',
                        'query' => $query
                    ]);
                }
            } catch (ClientException $exception) {
                $this->saveRequest(400, 0, $symbol, $convert, $curlString);

                if($cryptocurrencyWithQuotes['ohlcvQuotes']) {
                    $ohlcvQuotes = $cryptocurrencyWithQuotes['ohlcvQuotes'];
                    $returnData = [];
                    $returnData['s'] = "ok";
                    $returnData['c'] = [];
                    $returnData['h'] = [];
                    $returnData['l'] = [];
                    $returnData['o'] = [];
                    $returnData['t'] = [];
                    $returnData['v'] = [];
                    $returnData['query'] = $query;


                    foreach ($ohlcvQuotes as $key => $quotes) {
                        $returnData['c'][] = floatval($quotes->close);
                        $returnData['h'][] = floatval($quotes->high);
                        $returnData['l'][] = floatval($quotes->low);
                        $returnData['o'][] = floatval($quotes->open);
                        $returnData['t'][] = strtotime($quotes->timestamp);
                        $returnData['v'][] = floatval($quotes->volume);
                    }

                    return response()->json($returnData, 201);
                }

                return response()->json([
                    's' => 'no_data',
                    'query' => $query,
                    'coin_req' => true
                ]);
//                return response()->json(json_decode($exception->getResponse()->getBody()->getContents(), true));
            }

        } else {
            $ohlcvQuotes = $cryptocurrencyWithQuotes['ohlcvQuotes'];
            $returnData = [];
            $returnData['s'] = "ok";
            $returnData['c'] = [];
            $returnData['h'] = [];
            $returnData['l'] = [];
            $returnData['o'] = [];
            $returnData['t'] = [];
            $returnData['v'] = [];
            $returnData['query'] = $query;

            foreach ($ohlcvQuotes as $key => $quotes) {
                $returnData['c'][] = floatval($quotes->close);
                $returnData['h'][] = floatval($quotes->high);
                $returnData['l'][] = floatval($quotes->low);
                $returnData['o'][] = floatval($quotes->open);
                $returnData['t'][] = strtotime($quotes->timestamp);
                $returnData['v'][] = floatval($quotes->volume);
            }

            $this->saveRequest(0, 0, $cryptocurrencyWithQuotes->symbol, $convert);
            return response()->json($returnData, 201);
        }

    }

    /**
     * used /cryptocurrency/listings/latest
     * TN-54, TN-89, TN-53_see_comments
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function cryptocurrency(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'integer',
            'sort' => 'in:name,symbol,date_added,circulating_supply,total_supply,max_supply,num_market_pairs,market_cap_order,market_cap',
            'sort_dir' => 'in:asc,desc'
        ]);

        if ($validator->fails()) {
            $this->saveRequest(400);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => '400'
            ], 400);
        }
        $limit = $request->get('limit', 100);
        $sort = $request->get('sort', 'market_cap');
        $sortDir = $request->get('sort_dir', 'desc');
        $autocomplete = $request->get('autocomplete', '');
        $market_cap_filter = $request->get('market_cap', '');
        $price_filter = $request->get('price', '');
        $volume24_filter = $request->get('volume24', '');
        $cryptoService = new CryptoCurrencyService();

        $cryptocurrencies = $cryptoService->getCryptocurrenciesWithFilters($sort, $sortDir, $limit, $autocomplete,$market_cap_filter, $price_filter, $volume24_filter );
        if ($cryptocurrencies['total'] === 0) {
            return response()->json([
                'error_message' => 'No records for these filters in the database',
                'error_code' => '400'
            ], 400);
        }
        $this->saveRequest();
        return response()->json([
            "status" => [
                "error_code" => 0,
                "error_message" => null,
            ],
            'pagination' => [
                "page" => $cryptocurrencies['current_page'],
                "per_page" => $cryptocurrencies['per_page'],
                "skip" => ($cryptocurrencies['current_page'] - 1) * $limit,
                "total" => $cryptocurrencies['total'],
            ],
            'filters' => [
                'limit' => $limit,
                'sort' => $sort,
                'sortDir' => $sortDir,
                'autocomplete' => $autocomplete,
                'market_cap' => $cryptoService->getMarketCapFilters(),
                'price' => $cryptoService->getPriceFilters(),
                'volume24' => $cryptoService->getVolume24Filters(),
            ],
            'data' => $cryptocurrencies['data']
        ], 201);

        $limit = $request->get('limit', 100);
        $sort = $request->get('sort', 'market_cap');
        $sortDir = $request->get('sort_dir', 'desc');
    }

    public function cryptocurrencyOld(Request $request)
    {
        $start = 0;
        $limit = 100;
        $convert = ['USD'];
        $sort = 'market_cap_order'; //'market_cap';
        $sortDir = 'asc';
        $cryptocurrency_type = 'all';

        $validator = Validator::make($request->all(), [
            'start' => 'integer',
            'limit' => 'integer',
            'sort' => 'in:name,symbol,date_added,circulating_supply,total_supply,max_supply,num_market_pairs,market_cap_order',
            'sort_dir' => 'in:asc,desc'
        ]);

        if ($validator->fails()) {
            $this->saveRequest(400);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => '400'
            ], 400);
        }

        if (!empty($request['start'])) {
            $start = $request['start'];
//            $start = $request['start'] - 1;
        }

        if (!empty($request['limit'])) {
            $limit = $request['limit'];
        }

        if (!empty($request['sort'])) {
            $sort = $request['sort'];
        }

        if (!empty($request['sort_dir'])) {
            $sortDir = $request['sort_dir'];
        }

        if (!empty($request['convert'])) {
            $convert = explode(',', $request['convert']);
        }

        $cryptocurrencies = Cryptocurrency::select([
            '*',
            DB::raw('IF(`market_cap_order` IS NOT NULL, `market_cap_order`, 1000000) `market_cap_order`')
        ])
            ->limit($limit)->offset($start)->orderByRaw($sort . ' ' . $sortDir)->with('platform')->with([
                'quotes' => function ($q) use ($convert) {
                    $q->whereIn('symbol', $convert)->select('cryptocurrency_id', 'symbol', 'price', 'volume_24h',
                        'percent_change_24h', 'percent_change_1h', 'percent_change_7d', 'market_cap', 'last_updated');
                }
            ])->with([
                'tags' => function ($q) {
                    $q->select('name');
                }
            ])->get();
        $k = [];
        $cryptoCount = count($cryptocurrencies);

        foreach ($cryptocurrencies as $cryptocurrency) {
            foreach ($cryptocurrency['quotes'] as $quotesKey => $quotesC) {
                $k[$quotesC['symbol']] = isset($k[$quotesC['symbol']]) ? $k[$quotesC['symbol']] + ($quotesC['market_cap'] / $cryptoCount) : $quotesC['market_cap'] / $cryptoCount;
            }
        }

        foreach ($cryptocurrencies as $key => $cryptocurrency) {
            $cryptocurrency['circulating_supply'] = floatval($cryptocurrency['circulating_supply']);
            $cryptocurrency['max_supply'] = floatval($cryptocurrency['max_supply']);

            if ($cryptocurrency['max_supply']) {
                $cryptocurrency['circulating_supply_percent'] = $cryptocurrency['circulating_supply'] / $cryptocurrency['max_supply'] * 100;
            } else {
                $cryptocurrency['circulating_supply_percent'] = null;
            }

            $cryptocurrency['total_supply'] = floatval($cryptocurrency['total_supply']);
            $quotesArr = $cryptocurrency['quotes'];
            $tags = $cryptocurrency['tags'];

            foreach ($quotesArr as $quotesKey => $quotes) {
                $qSymbol = $quotes['symbol'];
                $quotes['price'] = floatval($quotes['price']);
                $quotes['volume_24h'] = floatval($quotes['volume_24h']);
                $quotes['percent_change_24h'] = floatval($quotes['percent_change_24h']);
                $quotes['percent_change_1h'] = floatval($quotes['percent_change_1h']);
                $quotes['percent_change_7d'] = floatval($quotes['percent_change_7d']);
                $quotes['market_cap'] = floatval($quotes['market_cap']);
                $quotes['k'] = $k[$quotes['symbol']];
                $quotes['wx'] = $quotes['market_cap'] / $k[$quotesC['symbol']];
                unset($quotes['cryptocurrency_id']);
                $cryptocurrency['quotes'][$qSymbol] = $quotes;
                unset($cryptocurrency['quotes'][$quotesKey]);
            }

            $newTags = [];

            foreach ($tags as $tag) {
                $newTags[] = $tag['name'];
            }

            unset($cryptocurrency['tags']);
            unset($cryptocurrency['urls']);
            unset($cryptocurrency['market_cap_order']);
            $cryptocurrency['tags'] = $newTags;

        }

        $count = Cryptocurrency::count();
        $step = $start / $limit;
        $allSteps = $count / $limit;

        $this->saveRequest();
        return response()->json([
            "status" => [
                "error_code" => 0,
                "error_message" => null,
                "step" => is_int($step) ? $step : intval($step) + 1,
                "all_steps" => is_int($allSteps) ? $allSteps : intval($allSteps) + 1,
                "count" => $count,
                "start" => $start,
                "limit" => $limit,
                "k" => $k
            ],
            'data' => $cryptocurrencies
        ], 201);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function marketPairs(Request $request)
    {
        $id = null;
        $symbol = null;
        $start = 0;
        $limit = 100;
//        $curlString = env('API_COIN') . "cryptocurrency/market-pairs/latest";
        $validator = Validator::make($request->all(), [
            'id' => 'integer',
            'start' => 'integer',
            'limit' => 'integer',
        ]);

        if ($validator->fails()) {
            $this->saveRequest(400);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => '400'
            ], 400);
        }

        if (!empty($request['id'])) {
            $id = $request['id'];
        }

        if (!empty($request['symbol'])) {
            $symbol = $request['symbol'];
        }

        if (!empty($request['limit'])) {
            $limit = $request['limit'];
        }

        if (!empty($request['start'])) {
            $start = $request['start'];
        }

        if (!($id xor $symbol)) {
            $this->saveRequest(400);

            if ($id && $symbol) {
                return response()->json([
                    'error_message' => "'value'contains a conflict between exclusive peers [id, symbol]",
                    'error_code' => '400'
                ], 400);
            }
            return response()->json([
                'error_message' => "'value' must contain at least one of [id, symbol]",
                'error_code' => '400'
            ], 400);
        }

        $cryptocurrency = Cryptocurrency::select('cryptocurrency_id', 'id', 'name', 'symbol',
            'num_market_pairs')->where('id', $id)->orwhere('symbol', $symbol)
            ->with([
                'marketPairsLeft' => function ($q) {
                    $q->select('string1_id', 'string2_id', 'symbol')->join('cryptocurrencies as c1',
                        'market_pairs.string2_id', '=', 'c1.cryptocurrency_id');
                }
            ])
            ->with([
                'marketPairsRight' => function ($q) {
                    $q->select('string1_id', 'string2_id', 'symbol')->join('cryptocurrencies as c2',
                        'market_pairs.string1_id', '=', 'c2.cryptocurrency_id');
                }
            ])->first();

        $marketPairs = [];

        if (!$cryptocurrency) {
            $this->saveRequest(400);
            return response()->json([
                "status" => [
                    "error_code" => 400,
                    "error_message" => "empty data",
                    'timestamp' => date('Y-m-d H:i:s'),
                ],
            ], 400);
        }

        if ($cryptocurrency->num_market_pairs) {

            if (count($cryptocurrency['marketPairsLeft'])) {

                foreach ($cryptocurrency->marketPairsLeft as $marketPairsLeft) {
                    $marketPairs[] = ['market_pair' => $cryptocurrency->symbol . '/' . $marketPairsLeft->symbol];
                }

            }

            if (count($cryptocurrency['marketPairsRight'])) {

                foreach ($cryptocurrency->marketPairsRight as $marketPairsRight) {
                    $marketPairs[] = ['market_pair' => $marketPairsRight->symbol . '/' . $cryptocurrency->symbol];
                }

            }

        }

        $cryptocurrency->market_pairs = array_slice($marketPairs, $start, $limit);
        unset($cryptocurrency->marketPairsLeft);
        unset($cryptocurrency->marketPairsRight);
        $this->saveRequest(0, 0, $cryptocurrency->symbol);
        return response()->json([
            "status" => [
                "error_code" => 0,
                "error_message" => null,
                'timestamp' => date('Y-m-d H:i:s'),
            ],
            'data' => $cryptocurrency
        ], 200);

    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function mapAll(Request $request)
    {
        $limit = 0;
        $start = 0;
        $validator = Validator::make($request->all(), [
            'start' => 'integer',
            'limit' => 'integer',
        ]);

        if ($validator->fails()) {
            $this->saveRequest(400);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => '400'
            ], 400);
        }

        if (!empty($request['limit'])) {
            $limit = $request['limit'];
        }

        if (!empty($request['start'])) {
            $start = $request['start'];
        }

        $cryptocurrencyCount = Cryptocurrency::count();

        if (!$limit) {
            $limit = $cryptocurrencyCount;
        }

        $cryptocurrencies = Cryptocurrency::select('name', 'symbol')->limit($limit)->offset($start)->get();
        $this->saveRequest();
        return response()->json([
            "status" => [
                "error_code" => 0,
                "error_message" => null,
                'timestamp' => date('Y-m-d H:i:s'),
            ],
            'cryptocurrencies_count' => $cryptocurrencyCount,
            'data' => $cryptocurrencies,
        ], 201);
    }

    /**
     * for charting_library
     * @return \Illuminate\Http\JsonResponse
     */
    public function config()
    {
        $this->saveRequest();
        return response()->json([
            'supported_resolutions' => ['60', "D", "2D", "3D", "W", "3W", "M"],
//            'supported_resolutions' => ['6', '12', '24', '60', '1D', '2D', '3D', '7D', '14D', '15D', '30D', '60D', '90D', '365D'],
            'supports_group_request' => false,
            'supports_marks' => false,
            'supports_search' => true,
            'supports_time' => true,
//            'supports_timescale_marks' => true,
            'symbols_types' => [
                0 => [
                    'name' => "bitcoin",
                    'value' => "bitcoin"
                ]
            ]
        ], 201);
    }

    /**
     * for charting_library
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function symbols(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'symbol' => 'required'
        ]);

        if ($validator->fails()) {
            $this->saveRequest();
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => '400'
            ], 400);
        }

        $symbolArrFirst = explode(':', $request['symbol']);
        $symbolArr = explode('/', $symbolArrFirst[0]);
        $symbol = $symbolArr[0];


        // get data from DB
        $symbolData = Cryptocurrency::where('symbol', $symbol)->first();
        $convert = !empty($symbolArr[1]) ? $symbolArr[1] : 'USD';

        $returnDada = [
            'description' => $symbolData->name,
            'exchange-listed' => $convert,
            'exchange-traded' => $convert,
            'has_intraday' => false,
            'has_no_volume' => false,
            'minmovement' => 1,
            'minmovement2' => 0,
            'name' => $symbolData->name,
            'pointvalue' => 1,
            'pricescale' => 100000000,
            'session' => "24x7",
            'ticker' => $symbolArrFirst[0],
            'timezone' => "Asia/Almaty",
            'has_daily' => true,
            'has_intraday' => true,
            'has_weekly_and_monthly' => true,
            'type' => "bitcoin",
            'supported_resolutions' => ["60", "D", "2D", "3D", "W", "3W", "M"],
            'intraday_multipliers' => ["60"]
//            'supported_resolutions' => ['6', '12', '24', '60', '1D', '2D', '3D', '7D', '14D', '15D', '30D', '60D', '90D', '365D'],
        ];

        $this->saveRequest(0, 0, $symbol, $convert);
        return response()->json($returnDada, 201);
    }

    /**
     * for charting_library
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchMethod(Request $request)
    {
        $limit = 30;
        $query = null;
        $query2 = null;
//       type:
//       exchange:

        if (!empty($request['limit'])) {
            $limit = $request['limit'];
        }

        if (!empty($request['query'])) {
            $queryArray = explode('/', $request['query']);
            $query = $queryArray[0];
            $query2 = !empty($queryArray[1]) ? $queryArray[1] : null;
        }

        // get cryptocurrencies
//        $cryptocurrencies = Cryptocurrency::where('currency_type', 'cryptocurrency')
//            ->Where(function ($q) use ($query) {
//                $q->where('name', 'like', '%' . $query . '%')
//                    ->orWhere('symbol', 'like', '%' . $query . '%');
//            })->limit($limit)->get();

        $cryptocurrencies = Cryptocurrency::where('currency_type', 'cryptocurrency')->select('cryptocurrency_id', 'id',
            'name', 'symbol', 'num_market_pairs')
            ->Where(function ($q) use ($query) {
                $q->where('name', 'like', '%' . $query . '%')
                    ->orWhere('symbol', 'like', '%' . $query . '%');
            })
            ->with([
                'marketPairsLeft' => function ($q) use ($query2) {
                    $q->select('string1_id', 'string2_id', 'symbol', 'name')->where('symbol', 'like',
                        '%' . $query2 . '%')->join('cryptocurrencies as c1', 'market_pairs.string2_id', '=',
                        'c1.cryptocurrency_id');
                }
            ])
            ->with([
                'marketPairsRight' => function ($q) use ($query2) {
                    $q->select('string1_id', 'string2_id', 'symbol', 'name')->where('symbol', 'like',
                        '%' . $query2 . '%')->join('cryptocurrencies as c2', 'market_pairs.string1_id', '=',
                        'c2.cryptocurrency_id');
                }
            ])
            ->limit($limit)->get();

        $resultData = [];
        $returnData = [];

        foreach ($cryptocurrencies as $cryptocurrency) {

            foreach ($cryptocurrency->marketPairsLeft as $marketPair) {
                $resultData['description'] = $cryptocurrency->name . '/' . $marketPair->name;
//                $resultData['exchange'] = $cryptocurrency->symbol;
                $resultData['full_name'] = $cryptocurrency->symbol . '/' . $marketPair->symbol;
                $resultData['symbol'] = $cryptocurrency->symbol . '/' . $marketPair->symbol;
                $resultData['type'] = "bitcoin";
                $returnData[] = $resultData;
            }

            foreach ($cryptocurrency->marketPairsRight as $marketPair) {
                $resultData['description'] = $cryptocurrency->name . '/' . $marketPair->name;
//                $resultData['exchange'] = $cryptocurrency->symbol;
                $resultData['full_name'] = $cryptocurrency->symbol . '/' . $marketPair->symbol;
                $resultData['symbol'] = $cryptocurrency->symbol . '/' . $marketPair->symbol;
                $resultData['type'] = "bitcoin";
                $returnData[] = $resultData;
            }
        }
        $this->saveRequest();
        return response()->json($returnData, 201);

    }

    /**
     * for charting_library
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function timescaleMarks(Request $request)
    {
        $data = [];

        if ($request['to']) {
            $data[] = [
                'id' => 1,
                'color' => "blue",
                'label' => "A",
                'time' => $request['to'],
                'tooltip' => []
            ];
        }
        $this->saveRequest();
        return response()->json($data, 201);
    }

    /**
     * TN-89
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrency(Request $request)
    {
        $symbol = null;
        $id = null;
        $convert = 'USD';

        $validator = Validator::make($request->all(), [
            'id' => 'integer',
            'symbol' => 'string',
        ]);

        if ($validator->fails()) {
            $this->saveRequest(400);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => '400'
            ], 400);
        }

        if (!empty($request['id'])) {
            $id = $request['id'];
        }

        if (!empty($request['symbol'])) {
            $symbol = $request['symbol'];
        }

        if (!($id xor $symbol)) {
            $this->saveRequest(400);
            if ($id && $symbol) {
                return response()->json([
                    'error_message' => "'value'contains a conflict between exclusive peers [id, symbol]",
                    'error_code' => '400'
                ], 400);
            }
            return response()->json([
                'error_message' => "'value' must contain at least one of [id, symbol]",
                'error_code' => '400'
            ], 400);
        }

        $cryptocurrency = Cryptocurrency::query()
            ->select(
                'name',
                'symbol',
                'circulating_supply',
                'max_supply',
                'num_market_pairs',
                'total_supply',
                'logo_2 as logo',
                'urls')
            ->where('id', $id)
            ->orWhere('symbol', $symbol)
            ->with([
                'quotes' => function ($q) use ($convert) {
                    $q
                        ->where('symbol', $convert)
                        ->select('symbol', 'price', 'volume_24h', 'percent_change_7d', 'market_cap', 'last_updated');
                }
            ])->first();

        if ($cryptocurrency) {
            $cryptocurrency->circulating_supply = floatval($cryptocurrency->circulating_supply);
            $cryptocurrency->max_supply = floatval($cryptocurrency->max_supply);

            if ($cryptocurrency->max_supply) {
                $cryptocurrency->circulating_supply_percent = $cryptocurrency->circulating_supply / $cryptocurrency->max_supply * 100;
            } else {
                $cryptocurrency->circulating_supply_percent = null;
            }

            $cryptocurrency->total_supply = floatval($cryptocurrency->total_supply);
            $quotesArr = $cryptocurrency->quotes;

            foreach ($quotesArr as $quotesKey => $quotes) {
                $qSymbol = $quotes->symbol;
                $quotes->price = floatval($quotes->price);
                $quotes->volume_24h = floatval($quotes->volume_24h);
                $quotes->percent_change_24h = floatval($quotes->percent_change_24h);
                $quotes->percent_change_1h = floatval($quotes->percent_change_1h);
                $quotes->percent_change_7d = floatval($quotes->percent_change_7d);
                $quotes->market_cap = floatval($quotes->market_cap);
                unset($quotes->cryptocurrency_id);
                $cryptocurrency['quotes'][$qSymbol] = $quotes;
                unset($cryptocurrency['quotes'][$quotesKey]);
            }

            if (isset($cryptocurrency['quotes']['BTC'])) {
                $quote = $cryptocurrency['quotes']['BTC'];
                $cryptocurrency->price = $quote->price;
                $cryptocurrency->volume_24h = $quote->volume_24h;
                $cryptocurrency->percent_change_7d = $quote->percent_change_7d;
                $cryptocurrency->market_cap = $quote->market_cap;
                $cryptocurrency->percent_change_24h = $quote->percent_change_24h;
                $cryptocurrency->percent_change_1h = $quote->percent_change_1h;
            }
            unset($cryptocurrency['quotes']);
            $cryptocurrency->urls = json_decode($cryptocurrency->urls);
            unset($cryptocurrency->market_cap_order);
        }

        $this->saveRequest();
        return response()->json([
            "status" => [
                "error_code" => 0,
                "error_message" => null,
            ],
            'data' => $cryptocurrency,
            'filters' => [
                'id' => $id,
                'symbol' => $symbol
            ]
        ], 201);

    }

    /**
     * TN-379
     * @return \Illuminate\Http\JsonResponse
     */
    public function ticker()
    {
        //done for convert symbol = USD
        //todo maybe need to get from global_metrics_historical_requests
        $cryptocurrencies = Cryptocurrency::select([
            '*',
            DB::raw('IF(`market_cap_order` IS NOT NULL, `market_cap_order`, 1000000) `market_cap_order`')
        ])
            ->limit(10)->offset(0)->orderBy('market_cap_order')->with([
                'quotes' => function ($q) {
                    $q->where('symbol', 'USD')->select('cryptocurrency_id', 'symbol', 'price', 'volume_24h',
                        'percent_change_24h', 'percent_change_1h', 'percent_change_7d', 'market_cap', 'last_updated');
                }
            ])->get();

        $globalmetric = GlobalMetric::select('id', 'btc_dominance')->orderByRaw('last_updated DESC')
            ->with([
                'quotes' => function ($q) {
                    $q->select('global_metric_id', 'last_updated', 'symbol', 'total_market_cap', 'total_volume_24h',
                        'last_updated')->where('symbol', 'USD');
                }
            ])
            ->first();

        $globalmetricM = [];
        $globalmetric24 = [];
        $globalmetricBd = [];

        if ($globalmetric && count($globalmetric['quotes']) > 0) {
            $lastUpdated = $globalmetric['quotes'][0]->last_updated;
            $compareDate = date('Y-m-d H:i:s', strtotime($lastUpdated . '-1 hour'));
            $quoteForCompare = GlobalMetricsHistorical::select('convert', 'total_market_cap',
                'total_volume_24h')->where('timestamp', "<=",
                $compareDate)->orderByRaw('timestamp DESC')->where('convert', 'USD')->first();

            $globalmetricM['price_name'] = 'M.Cap';
            $globalmetricM['price'] = round($globalmetric['quotes'][0]->total_market_cap, 2);
            $globalmetricM['price_symbol'] = "$";
            $globalmetricM['price_human'] = $this->currencyFormat(round($globalmetric['quotes'][0]->total_market_cap,
                1));
            $globalmetric24['price_name'] = 'D.Vol';
            $globalmetric24['price'] = round($globalmetric['quotes'][0]->total_volume_24h, 2);
            $globalmetric24['price_symbol'] = "$";
            $globalmetric24['price_human'] = $this->currencyFormat(round($globalmetric['quotes'][0]->total_volume_24h,
                1));

            if ($quoteForCompare) {
                $globalmetricM['change'] = $globalmetricM['price'] !== 0 ? ($quoteForCompare->total_market_cap - $globalmetricM['price']) * 100 / $globalmetricM['price'] : 0;
                $globalmetricM['change'] = round($globalmetricM['change'], 2);
                $globalmetric24['change'] = $globalmetric24['price'] !== 0 ? ($quoteForCompare->total_volume_24h - $globalmetric24['price']) * 100 / ($globalmetric24['price']) : 0;
                $globalmetric24['change'] = round($globalmetric24['change'], 2);
            } else {
                $globalmetricM['change'] = null;
                $globalmetric24['change'] = null;
            }

            $globalmetricM['change_symbol'] = '%';
            $globalmetric24['change_symbol'] = '%';
            $globalmetricM['increase'] = $globalmetricM['change'] >= 0 ? 1 : 0;
            $globalmetric24['increase'] = $globalmetric24['change'] >= 0 ? 1 : 0;

            $globalmetricBd['change_name'] = 'BTC Dom';
            $globalmetricBd['change'] = round($globalmetric->btc_dominance, 2);
            $globalmetricBd['change_symbol'] = "%";
        }

        $crypts = [];

        foreach ($cryptocurrencies as $key => $cryptocurrency) {
            $crypts[] = [
                'top_number' => $key + 1,
                'symbol' => $cryptocurrency->symbol,
                'name' => $cryptocurrency->name,
                'logo' => $cryptocurrency->logo_2 ? URL::to('/') . $cryptocurrency->logo_2 : null,
                'price' => round($cryptocurrency['quotes'][0]->price, 2),
                'price_symbol' => '$',
                'percent_change_24h' => round($cryptocurrency['quotes'][0]->percent_change_24h, 2),
                'percent_change_24h_symbol' => "%",
                'increase' => $cryptocurrency['quotes'][0]->percent_change_24h >= 0 ? 1 : 0
            ];

        }

        $this->saveRequest();
        return response()->json([
            "status" => [
                "error_code" => 0,
                "error_message" => null,
            ],
            'top10_crypto' => $crypts,
            'ticker' => [$globalmetricM, $globalmetric24, $globalmetricBd]
        ], 201);
    }

    public function widgetCryptomarket()
    {
        $cryptoService = new CryptoCurrencyService();
        $widgetData = $cryptoService->getWidgetCryptoMarketTopData();
        $this->saveRequest();
        return response()->json($widgetData, 201);
    }

    public function tickerOld()
    {
        //done for convert symbol = USD
        //todo maybe need to get from global_metrics_historical_requests
        $cryptocurrencies = Cryptocurrency::select([
            '*',
            DB::raw('IF(`market_cap_order` IS NOT NULL, `market_cap_order`, 1000000) `market_cap_order`')
        ])
            ->limit(10)->offset(0)->orderBy('market_cap_order')->with([
                'quotes' => function ($q) {
                    $q->where('symbol', 'USD')->select('cryptocurrency_id', 'symbol', 'price', 'volume_24h',
                        'percent_change_24h', 'percent_change_1h', 'percent_change_7d', 'market_cap', 'last_updated');
                }
            ])->get();

        $globalmetric = GlobalMetric::select('id', 'btc_dominance')->orderByRaw('last_updated DESC')
            ->with([
                'quotes' => function ($q) {
                    $q->select('global_metric_id', 'last_updated', 'symbol', 'total_market_cap', 'total_volume_24h',
                        'last_updated')->where('symbol', 'USD');
                }
            ])
            ->first();

        $globalmetricM = [];
        $globalmetric24 = [];
        $globalmetricBd = [];

        if ($globalmetric) {
            $lastUpdated = $globalmetric['quotes'][0]->last_updated;
            $compareDate = date('Y-m-d H:i:s', strtotime($lastUpdated . '-1 hour'));
            $quoteForCompare = GlobalMetricsHistorical::select('convert', 'total_market_cap',
                'total_volume_24h')->where('timestamp', "<=",
                $compareDate)->orderByRaw('timestamp DESC')->where('convert', 'USD')->first();

            $globalmetricM['market_cap'] = round($globalmetric['quotes'][0]->total_market_cap, 3);
            $globalmetricM['market_cap_human'] = "$" . $this->currencyFormat(round($globalmetric['quotes'][0]->total_market_cap,
                    1));
            $globalmetric24['24value'] = $globalmetric['quotes'][0]->total_volume_24h;
            $globalmetric24['24value_human'] = "$" . $this->currencyFormat(round($globalmetric['quotes'][0]->total_volume_24h,
                    1));

            if ($quoteForCompare) {
                $globalmetricM['market_cap_change'] = $globalmetricM['market_cap'] !== 0 ? ($quoteForCompare->total_market_cap - $globalmetricM['market_cap']) * 100 / $globalmetricM['market_cap'] : 0;
                $globalmetricM['market_cap_change'] = round($globalmetricM['market_cap_change'], 3) . "%";
                $globalmetric24['24value_change'] = $globalmetric24['24value'] !== 0 ? ($quoteForCompare->total_volume_24h - $globalmetric24['24value']) * 100 / ($globalmetric24['24value']) : 0;
                $globalmetric24['24value_change'] = round($globalmetric24['24value_change'], 3) . "%";
            } else {
                $globalmetricM['market_cap_change'] = null;
                $globalmetric24['24value_change'] = null;
            }
            $globalmetricM['increase'] = $globalmetricM['market_cap_change'] >= 0 ? 1 : 0;
            $globalmetric24['increase'] = $globalmetric24['24value_change'] >= 0 ? 1 : 0;

            $globalmetricBd['btc_dominance'] = $globalmetric->btc_dominance;
        }

        $crypts = [];

        foreach ($cryptocurrencies as $key => $cryptocurrency) {
            $crypts[] = [
                'top_number' => $key + 1,
                'symbol' => $cryptocurrency->symbol,
                'name' => $cryptocurrency->name,
                'logo' => $cryptocurrency->logo,
                'price' => '$' . round($cryptocurrency['quotes'][0]->price, 3),
                'percent_change_24h' => round($cryptocurrency['quotes'][0]->percent_change_24h, 3) . "%",
                'increase' => $cryptocurrency['quotes'][0]->percent_change_24h >= 0 ? 1 : 0
            ];

        }

        $this->saveRequest();
        return response()->json([
            "status" => [
                "error_code" => 0,
                "error_message" => null,
            ],
            'top10_crypto' => $crypts,
            'ticker' => [$globalmetricM, $globalmetric24, $globalmetricBd]
        ], 201);
    }

    public function allowUnsetCurrencyPair(Request $request)
    {
        // todo use middleware
        $validator = Validator::make($request->all(), [
            'symbol1' => 'required',
            'symbol2' => 'required',
            'allowed' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            $this->saveRequest(400);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => '400'
            ], 400);
        }

        $allowed = $request['allowed'];
        $symbol1 = $request['symbol1'];
        $symbol2 = $request['symbol2'];

        $marketPairId = MarketPair::getPairId($symbol1, $symbol2);
        $marketPair = MarketPair::where('id', '=', $marketPairId)->first();

        if (!$marketPair) {
            $this->saveRequest(400);
            return response()->json([
                'error_message' => 'no_data_found',
                'error_code' => '400'
            ], 400);
        };

        $marketPair->allowed_ohlcv = $allowed;
        $marketPair->save();
        $this->saveRequest();
        return response()->json([
            "error_code" => 0,
            "error_message" => null,
        ], 200);

    }

    public function getAllPairs(Request $request)
    {
        $query1 = null;
        $query2 = null;
        $limit = 100;
        $allowed = 'all';

        $validator = Validator::make($request->all(), [
            'search' => 'string',
            'limit' => 'integer',
            'allowed' => 'boolean',
        ]);

        if ($validator->fails()) {
            $this->saveRequest(400);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => '400'
            ], 400);
        }

        if (!empty($request['search'])) {
            $queryArray = explode('/', $request['search']);
            $query1 = $queryArray[0];
            $query2 = !empty($queryArray[1]) ? $queryArray[1] : null;
        }


        if (!empty($request['limit'])) {
            $limit = $request['limit'];
        }

        if (isset($request['allowed'])) {
            $allowed = (int)$request['allowed'];
        }

        $marketPairs = MarketPair::leftJoin('cryptocurrencies as c1', 'market_pairs.string1_id', '=',
            'c1.cryptocurrency_id')
            ->leftJoin('cryptocurrencies as c2', 'market_pairs.string2_id', '=', 'c2.cryptocurrency_id')
            ->leftJoin('requests', 'market_pairs.id', '=', 'requests.market_pair_id')
            ->select(
                'market_pairs.id',
                DB::raw("CONCAT(c1.symbol, '/', c2.symbol) AS symbol"),
                DB::raw("CONCAT(c1.name, '/', c2.name) AS name"),
                'c1.symbol as coin1_symbol',
                'c2.symbol as coin2_symbol',
                'c1.name as coin1_name',
                'c2.name as coin2_name',
                DB::raw('sum(credit_count) credit_count'),
                DB::raw('sum(daily_request_count) request_count'),
                'allowed_ohlcv as allowed')
            ->Where(function ($q) use ($query1, $query2, $allowed) {

                if ($allowed === 0 || $allowed === 1) {
                    $q->where('c1.symbol', 'like', '%' . $query1 . '%')
                        ->where('c2.symbol', 'like', '%' . $query2 . '%')
                        ->where('allowed_ohlcv', $allowed);
                } else {
                    $q->where('c1.symbol', 'like', '%' . $query1 . '%')
                        ->where('c2.symbol', 'like', '%' . $query2 . '%');
                }
            })->orWhere(function ($q) use ($query1, $query2, $allowed) {
                if ($allowed === 0 || $allowed === 1) {
                    $q->where('c1.symbol', 'like', '%' . $query2 . '%')
                        ->where('c2.symbol', 'like', '%' . $query1 . '%')
                        ->where('allowed_ohlcv', $allowed);
                } else {
                    $q->where('c1.symbol', 'like', '%' . $query2 . '%')
                        ->where('c2.symbol', 'like', '%' . $query1 . '%');
                }
            })
            ->groupBy('market_pairs.id')
            ->orderByRaw('request_count DESC')
            ->orderByRaw('c1.symbol ASC')
            ->orderByRaw('c2.symbol ASC')
            ->limit($limit)->get();

        $this->saveRequest();
        return response()->json([
            "status" => [
                "error_code" => 0,
                "error_message" => null,
            ],
            "data" => $marketPairs
        ], 200);

    }

    /**
     * Retrieves coins data for ticker by symbols.
     * @api api/coin/ticker/coins
     *
     * @queryParam page int Default: 1
     * @queryParam perPage int Default: 10
     * @queryParam coins string Default: ''
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTickerCoins(Request $request)
    {
        $coinSymbols = $request->get('coins', '');
        $page = (int)$request['page'] ?: 1;
        $perPage = (int)$request['perPage'] ?: 10;
        $skip = ($page - 1) * $perPage;
        $data = [
            'status' => [
                'error_message' => 0,
                'error_code' => null
            ],
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'skip' => $skip,
                'total' => 0
            ],
            'filters' => [
                'coins' => $coinSymbols
            ],
            'data' => []
        ];

        if (empty($coinSymbols)) {
            $this->saveRequest(400);
            $data['status'] = [
                'error_message' => 'Field coins can\'t by empty',
                'error_code' => 400
            ];
            return response()->json($data, 400);
        }
        $symbols = explode(',', $coinSymbols);
        $cryptoService = new CryptoCurrencyService();
        $tickerCoins = $cryptoService->getTickerCoinsBySymbols($symbols);
        $paginateFavoriteCoins = array_slice($tickerCoins, $skip, $perPage);
        $data['pagination']['total'] = count($tickerCoins);
        $data['data'] = $paginateFavoriteCoins;
        $this->saveRequest();
        return response()->json($data, 200);
    }

    /**
     * Retrieves coins data by symbols.
     * @api api/coin/favorite
     *
     * @queryParam page int Default: 1
     * @queryParam perPage int Default: 10
     * @queryParam coins string Default: ''
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFavoriteCoins(Request $request)
    {
        $coinSymbols = $request->get('coins', '');
        $page = (int)$request['page'] ?: 1;
        $perPage = (int)$request['perPage'] ?: 10;
        $skip = ($page - 1) * $perPage;
        $data = [
            'status' => [
                'error_message' => 0,
                'error_code' => null
            ],
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'skip' => $skip,
                'total' => 0
            ],
            'filters' => [
                'coins' => $coinSymbols
            ],
            'data' => []
        ];

        if (empty($coinSymbols)) {
            $this->saveRequest(400);
            $data['status'] = [
                'error_message' => 'Field coins can\'t by empty',
                'error_code' => 400
            ];
            return response()->json($data, 400);
        }
        $symbols = explode(',', $coinSymbols);
        $cryptoService = new CryptoCurrencyService();
        $favoriteCoins = $cryptoService->getFavoriteCoinsBySymbols($symbols);
        $paginateFavoriteCoins = array_slice($favoriteCoins, $skip, $perPage);
        $data['pagination']['total'] = count($favoriteCoins);
        $data['data'] = $paginateFavoriteCoins;
        $this->saveRequest();
        return response()->json($data, 200);
    }
    public function getTopMarketPairs(Request $request)
    {
        $symbol = $request->get('symbol', '');
        $page = $request->get('page', 1);
        $perPage = $request->get('limit', 10);
        $skip = ($page - 1) * $perPage;
        $data = [
            'status' => [
                'error_message' => 0,
                'error_code' => null
            ],
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'skip' => $skip,
                'total' => 0
            ],
            'filters' => [
                'symbol' => $symbol
            ],
            'data' => []
        ];
        if (empty($symbol)) {
            $this->saveRequest(400);
            $data['status'] = [
                'error_message' => 'Field symbol can\'t by empty',
                'error_code' => 400
            ];
            return response()->json($data, 400);
        }
        $cryptoService = new ExchangePairQuoteService();





        $symbolId = $cryptoService->getIdCurrencyByTicker($symbol);
        if ($symbolId === false) {
            $this->saveRequest(400);
            $data['status'] = [
                'error_message' => 'Symbol not found in database',
                'error_code' => 400
            ];
            return response()->json($data, 400);
        }

        $symbolPairArray = $cryptoService->getPairsIdsBySymbolId($symbolId);
        
        if ($symbolPairArray === false) {
            $this->saveRequest(400);
            $data['status'] = [
                'error_message' => 'Symbol pairs not found in database',
                'error_code' => 400
            ];
            return response()->json($data, 400);
        }
        
        $topPairs = $cryptoService->getTopMarketPairs($symbolPairArray, $perPage, $page, $symbol);
        if ($topPairs !== false) {
            $data['pagination']['total'] = $topPairs['total'];
            $data['data'] = $topPairs['data'];
        }else{
            $this->saveRequest(400);
            $data['status'] = [
                'error_message' => 'Symbol pairs not found in database',
                'error_code' => 400
            ];
            return response()->json($data, 400);
        }
        
        return $data;
    }

}
