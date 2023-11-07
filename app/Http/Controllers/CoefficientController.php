<?php

namespace App\Http\Controllers;

use App\Http\DateFormat\DateFormat;
use App\Http\StatusCode\HTTPStatusCode;
use App\Services\CoefficientService;
use App\Services\CryptoCurrencyService;
use Illuminate\Http\Request;
use Validator;
use App\Cryptocurrency;


class CoefficientController extends Controller
{
    const DEFAULT_CONVERT = 'USD';
    const RESOLUTIONS = ["D", "W", "M"];
    const RESOLUTIONS_CONVERT = [
        'H' => 'hourly',
        '60' => 'hourly',
        "D" => 'daily',
        "1W" => 'weekly',
        "W" => 'weekly',
        "1M" => 'monthly',
        "M" => 'monthly',
    ];

    /**
     * for charting_library
     * @return \Illuminate\Http\JsonResponse
     */
    public function config()
    {
        $this->saveRequest();
        return response()->json([
            'supported_resolutions' => self::RESOLUTIONS,
            'supports_group_request' => false,
            'supports_marks' => false,
            'supports_search' => true,
            'supports_time' => true,
            'symbols_types' => [
                0 => [
                    'name' => "bitcoin",
                    'value' => "bitcoin"
                ]
            ]
        ], HTTPStatusCode::OK);
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
                'error_code' => HTTPStatusCode::BAD_REQUEST
            ], HTTPStatusCode::BAD_REQUEST);
        }

        $symbolArrFirst = explode(':', $request['symbol']);
        $symbolArr = explode('/', $symbolArrFirst[0]);
        $symbol = $symbolArr[0];


        // get data from DB
        $symbolData = Cryptocurrency::where('symbol', $symbol)->first();
        $convert = !empty($symbolArr[1]) ? $symbolArr[1] : self::DEFAULT_CONVERT;

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
            'supported_resolutions' => self::RESOLUTIONS,
        ];

        $this->saveRequest(0, 0, $symbol, $convert);
        return response()->json($returnDada, HTTPStatusCode::OK);
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
        return response()->json($returnData, HTTPStatusCode::OK);

    }

    public function volatilityHistorical(Request $request)
    {
        $symbol = null;
        $interval = "daily";
        $convert = self::DEFAULT_CONVERT;

        $validator = Validator::make($request->all(), [
            'symbol' => 'required'
        ]);

        if ($validator->fails()) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => HTTPStatusCode::BAD_REQUEST
            ], HTTPStatusCode::BAD_REQUEST);
        }

        if ($request->get('symbol')) {
            $symbolArr = explode('/', $request->get('symbol'));
            $symbol = $symbolArr[0];

            if (!empty($symbolArr[1])) {
                $convert = $symbolArr[1];
            }

        }

        $timeEnd = date('Y-m-d', $request->get('to', strtotime('today')));
        $timeStart = date('Y-m-d', $request->get('from', strtotime($timeEnd . "-30 days")));

        if (!empty($request['resolution']) && !empty(self::RESOLUTIONS_CONVERT[$request['resolution']])) {
            $interval = self::RESOLUTIONS_CONVERT[$request['resolution']];
        }

        $cryptoService = new CryptoCurrencyService();
        $returnData = $cryptoService->getCryptocurrencyWithCoefficients($symbol, $convert, $timeStart, $timeEnd,
            $interval, 'volatility');

        if (!$returnData) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST, 0, $symbol, $convert);
            return response()->json([
                's' => 'no_data',
            ]);
        }

        $this->saveRequest(0, 0, $symbol, $convert);
        return response()->json($returnData, HTTPStatusCode::OK);

    }

    public function sharpeHistorical(Request $request)
    {
        $symbol = null;
        $interval = "daily";
        $convert = self::DEFAULT_CONVERT;

        $validator = Validator::make($request->all(), [
            'symbol' => 'required'
        ]);

        if ($validator->fails()) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => HTTPStatusCode::BAD_REQUEST
            ], HTTPStatusCode::BAD_REQUEST);
        }

        if ($request->get('symbol')) {
            $symbolArr = explode('/', $request->get('symbol'));
            $symbol = $symbolArr[0];

            if (!empty($symbolArr[1])) {
                $convert = $symbolArr[1];
            }

        }

        $timeEnd = date('Y-m-d', $request->get('to', strtotime('today')));
        $timeStart = date('Y-m-d', $request->get('from', strtotime($timeEnd . "-30 days")));

        if (!empty($request['resolution']) && !empty(self::RESOLUTIONS_CONVERT[$request['resolution']])) {
            $interval = self::RESOLUTIONS_CONVERT[$request['resolution']];
        }

        $cryptoService = new CryptoCurrencyService();
        $returnData = $cryptoService->getCryptocurrencyWithCoefficients($symbol, $convert, $timeStart, $timeEnd,
            $interval, 'sharpe');

        if (!$returnData) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST, 0, $symbol, $convert);
            return response()->json([
                's' => 'no_data',
            ]);
        }

        $this->saveRequest(0, 0, $symbol, $convert);
        return response()->json($returnData, HTTPStatusCode::OK);

    }

    public function alphaHistorical(Request $request)
    {
        $symbol = null;
        $interval = "daily";
        $convert = self::DEFAULT_CONVERT;

        $validator = Validator::make($request->all(), [
            'symbol' => 'required'
        ]);

        if ($validator->fails()) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => '400'
            ], HTTPStatusCode::BAD_REQUEST);
        }

        if ($request->get('symbol')) {
            $symbolArr = explode('/', $request->get('symbol'));
            $symbol = $symbolArr[0];

            if (!empty($symbolArr[1])) {
                $convert = $symbolArr[1];
            }

        }

        $timeEnd = date(DateFormat::DATE_FORMAT, $request->get('to', strtotime('today')));
        $timeStart = date(DateFormat::DATE_FORMAT, $request->get('from', strtotime($timeEnd . "-30 days")));

        if (!empty($request['resolution']) && !empty(self::RESOLUTIONS_CONVERT[$request['resolution']])) {
            $interval = self::RESOLUTIONS_CONVERT[$request['resolution']];
        }

        $cryptoService = new CryptoCurrencyService();
        $returnData = $cryptoService->getCryptocurrencyWithCoefficients($symbol, $convert, $timeStart, $timeEnd,
            $interval, 'alpha');

        if (!$returnData) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST, 0, $symbol, $convert);
            return response()->json([
                's' => 'no_data',
            ]);
        }

        $this->saveRequest(0, 0, $symbol, $convert);
        return response()->json($returnData, 201);
    }

    public function betaHistorical(Request $request)
    {
        $symbol = null;
        $interval = "daily";
        $convert = self::DEFAULT_CONVERT;

        $validator = Validator::make($request->all(), [
            'symbol' => 'required'
        ]);

        if ($validator->fails()) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => HTTPStatusCode::BAD_REQUEST
            ], HTTPStatusCode::BAD_REQUEST);
        }

        if ($request->get('symbol')) {
            $symbolArr = explode('/', $request->get('symbol'));
            $symbol = $symbolArr[0];

            if (!empty($symbolArr[1])) {
                $convert = $symbolArr[1];
            }

        }

        $timeEnd = date(DateFormat::DATE_FORMAT, $request->get('to', strtotime('today')));
        $timeStart = date(DateFormat::DATE_FORMAT, $request->get('from', strtotime($timeEnd . "-30 days")));

        if (!empty($request['resolution']) && !empty(self::RESOLUTIONS_CONVERT[$request['resolution']])) {
            $interval = self::RESOLUTIONS_CONVERT[$request['resolution']];
        }

        $cryptoService = new CryptoCurrencyService();
        $returnData = $cryptoService->getCryptocurrencyWithCoefficients($symbol, $convert, $timeStart, $timeEnd,
            $interval, 'beta');

        if (!$returnData) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST, 0, $symbol, $convert);
            return response()->json([
                's' => 'no_data',
            ]);
        }

        $this->saveRequest(0, 0, $symbol, $convert);
        return response()->json($returnData, HTTPStatusCode::OK);

    }

    public function sortinoHistorical(Request $request)
    {

        $symbol = null;
        $interval = "weekly";
        $convert = self::DEFAULT_CONVERT;

        $validator = Validator::make($request->all(), [
            'symbol' => 'required'
        ]);

        if ($validator->fails()) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => HTTPStatusCode::BAD_REQUEST
            ], HTTPStatusCode::BAD_REQUEST);
        }

        if ($request->get('symbol')) {
            $symbolArr = explode('/', $request->get('symbol'));
            $symbol = $symbolArr[0];

            if (!empty($symbolArr[1])) {
                $convert = $symbolArr[1];
            }

        }

        $timeEnd = date(DateFormat::DATE_FORMAT, $request->get('to', strtotime('today')));
        $timeStart = date(DateFormat::DATE_FORMAT, $request->get('from', strtotime($timeEnd . "-30 days")));

        if (!empty($request['resolution']) && !empty(self::RESOLUTIONS_CONVERT[$request['resolution']])) {
            $interval = self::RESOLUTIONS_CONVERT[$request['resolution']];
        }

        $cryptoService = new CryptoCurrencyService();
        $returnData = $cryptoService->getCryptocurrencyWithCoefficients($symbol, $convert, $timeStart, $timeEnd,
            $interval, 'sharpe');

        if (!$returnData) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST, 0, $symbol, $convert);
            return response()->json([
                's' => 'no_data',
            ]);
        }

        $this->saveRequest(0, 0, $symbol, $convert);
        return response()->json($returnData, HTTPStatusCode::OK);

    }

    public function getChartsData(Request $request)
    {
        $periodIntervals = CryptoCurrencyService::getPeriodIntervals2();
        $chartTypes = CryptoCurrencyService::getChartTypes();
        $validator = Validator::make($request->all(), [
            'symbol' => 'required',
            'period_interval' => 'in:' . implode(',', $periodIntervals),
            'chart_type' => 'in:' . implode(',', $chartTypes)
        ]);

        if ($validator->fails()) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => HTTPStatusCode::BAD_REQUEST
            ], HTTPStatusCode::BAD_REQUEST);
        }

        $periodStartDate = $request->get('period_date_start', date(DateFormat::DATE_FORMAT));
        $periodEndDate = $request->get('period_date_end', date(DateFormat::DATE_FORMAT, strtotime('-7days')));
        $step = $request->get('period_interval', CryptoCurrencyService::WEEKLY_HISTORY_INTERVAL);
        $chartType = $request->get('chart_type', CryptoCurrencyService::CHART_TYPE_SORTINO);
        $chartSymbol = $request->get('symbol', '');

        $data = [
            'status' => [
                'error_message' => 0,
                'error_code' => null
            ],
            'filters' => [
                'period_date_start' => $periodStartDate,
                'period_date_end' => $periodEndDate,
                'period_interval' => $step,
                'chart_type' => $chartType,
                'chart_types' => $chartTypes,
                'period_intervals' => $periodIntervals,
                'symbol' => $chartSymbol
            ],
            'info' => [
                'name' => $chartType,
                'period' => $periodStartDate . ' ' . $periodEndDate,
                'symbol' => $chartSymbol,
                'interval' => $step,
            ],
            'data' => []
        ];

        $cryptoService = new CryptoCurrencyService();

        try {
            $data['data'] = $cryptoService->getChartDataBySymbolAndType($chartSymbol, $chartType, $periodStartDate, $periodEndDate, $step, $data);
        } catch (\Exception $e) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST);
            $data['status'] = [
                'error_message' => $e->getMessage(),
                'error_code' => HTTPStatusCode::BAD_REQUEST
            ];
            return response()->json($data, HTTPStatusCode::BAD_REQUEST);
        }

        return response()->json($data, HTTPStatusCode::OK);
    }

    public function getGlobalChartsData(Request $request)
    {
        $periodIntervals = CoefficientService::getPeriodIntervals();
        $chartTypes = array_keys(CoefficientService::CHART_TYPES);
        $validator = Validator::make($request->all(), [
            'period_interval' => 'in:' . implode(',', $periodIntervals),
            'chart_type' => 'in:' . implode(',', $chartTypes)
        ]);

        if ($validator->fails()) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => HTTPStatusCode::BAD_REQUEST
            ], HTTPStatusCode::BAD_REQUEST);
        }

        $coefficientService = new CoefficientService();
        $step = $request->get('period_interval', CoefficientService::MONTHLY_INTERVAL);
        $chartType = $request->get('chart_type', CoefficientService::CHART_TYPE_RETURN);
        $periodStartDate = $request->get('period_date_start', date(DateFormat::DATE_FORMAT));
        $periodEndDate = $request->get('period_date_end', date(DateFormat::DATE_FORMAT, strtotime('-7days')));

        $data = [
            'status' => [
                'error_message' => 0,
                'error_code' => null
            ],
            'filters' => [
                'period_date_start' => $periodStartDate,
                'period_date_end' => $periodEndDate,
                'period_interval' => $step,
                'chart_type' => $chartType,
                'chart_types' => $chartTypes,
                'period_intervals' => $periodIntervals,
            ],
            'info' => [
                'name' => $chartType,
                'period' => $periodStartDate . ' ' . $periodEndDate,
                'interval' => $step,
            ],
            'data' => []
        ];

        $data['data'] = $coefficientService->getChartDataByType($chartType, $periodStartDate, $periodEndDate, $step, $data);

        return response()->json($data, HTTPStatusCode::OK);

    }
}
