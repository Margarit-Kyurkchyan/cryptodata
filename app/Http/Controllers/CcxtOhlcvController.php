    <?php

namespace App\Http\Controllers;

use App\Cryptocurrency;
use App\Http\DateFormat\DateFormat;
use App\Services\CryptoCurrencyService;
use App\Http\StatusCode\HTTPStatusCode;
use Illuminate\Http\Request;
use Validator;

class CcxtOhlcvController extends Controller
{
    const DEFAULT_COINS_QUOTES_SYMBOL = 'USDT';
    const DEFAULT_EXCHANGE_NAME = 'binance';
    const RESOLUTIONS = ['1', '5', '15', '30', '60', '240', '720', "D", "W", "M"];
    const INTRADAY_MULTIPLIERS = ['1', '5', '15', '30', '60', '240', '720'];
    const RESOLUTIONS_CONVERT = [
        '1' => '1m',
        '5' => '5m',
        '15' => '15m',
        '30' => '30m',
        'm' => '1m',
        'H' => '1h',
        '60' => '1h',
        '240' => '4h',
        '720' => '12h',
        "D" => '1d',
        "1D" => '1d',
        "1W" => '1w',
        "W" => '1w',
        "M" => 'monthly',
        "1M" => 'monthly',
        "365D" => 'yearly',
    ];

    /**
     * for charting_library
     * @return \Illuminate\Http\JsonResponse
     */
    public function config()
    {
        $this->saveRequest();
        $cryptoService = new CryptoCurrencyService();
        $exchanges = $cryptoService->getCcxtExchanges();

        return response()->json([
            'supported_resolutions' => self::RESOLUTIONS,
            'supports_group_request' => false,
            'supports_marks' => false,
            'supports_search' => true,
            'supports_time' => true,
            'symbols_types' => [0 => [
                'name' => "bitcoin",
                'value' => "bitcoin"
            ]
            ],
            'exchanges' => $exchanges
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

        $symbolArrFirst = explode(':', $request->get('symbol'));
        $symbolArr = count($symbolArrFirst) > 1 ? explode('/', $symbolArrFirst[1]) : explode('/', $symbolArrFirst[0]);
        $symbol = $symbolArr[0];


        // get data from DB
        $symbolData = Cryptocurrency::where('symbol', $symbol)->orWhere('name', $symbol)->first();
        $convert = !empty($symbolArr[1]) ? $symbolArr[1] : self::DEFAULT_COINS_QUOTES_SYMBOL;
        $exchangeName = count($symbolArrFirst) > 1 ? $symbolArrFirst[0] : '';

        $returnDada = [
            'description' => $symbolData->name,
            'exchange-listed' => $exchangeName,
            'exchange-traded' => $exchangeName,
            'has_intraday' => false,
            'has_no_volume' => false,
            'minmovement' => 1,
            'minmovement2' => 0,
            'name' => $symbolData->symbol,
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
            'intraday_multipliers' => self::INTRADAY_MULTIPLIERS
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
        $query = null;
        $query2 = null;
        $limit = $request->get('limit', 30);
        $exchange = $request->get('exchange', '');
        $requestQuery = $request->get('query', '');

        if ($requestQuery) {
            $queryArray = explode('/', $request['query']);
            $query = $queryArray[0];
            $query2 = !empty($queryArray[1]) ? $queryArray[1] : null;
        }

        $cryptoService = new CryptoCurrencyService();
        $searchData = $cryptoService->getSearchData($query, $query2, $limit, $exchange);
        $this->saveRequest();
        return response()->json($searchData, HTTPStatusCode::OK);
    }

    public function ohlcvHistory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'required',
            'symbol' => 'required',
            'resolution' => 'string'
        ]);

        if ($validator->fails()) {
            $this->saveRequest(HTTPStatusCode::BAD_REQUEST);
            return response()->json([
                'error_message' => $validator->errors()->first(),
                'error_code' => HTTPStatusCode::BAD_REQUEST
            ], HTTPStatusCode::BAD_REQUEST);
        }

        $symbol = $request->get('symbol');
        $resolution = $request->get('resolution', '60');
        $from = $request->get('from');
        $to = $request->get('to', date(DateFormat::DATE_FORMAT));
        $interval = self::RESOLUTIONS_CONVERT[$resolution];

        $symbolArr = explode('/', $symbol);
        $base = $symbolArr[0];
        $quote = !empty($symbolArr[1]) ? $symbolArr[1] : self::DEFAULT_COINS_QUOTES_SYMBOL;

        if (!empty($symbolArr[1])) {
            $quote = $symbolArr[1];
        }

        $timeStart = date(DateFormat::DATE_TIME_FORMAT, $from);
        $timeEnd = date(DateFormat::DATE_TIME_FORMAT, $to);

        $cryptoService = new CryptoCurrencyService();
        $ccxtOjlcvData = $cryptoService->getCcxtOjlcvData($base, $quote, $timeStart, $timeEnd, $interval);
        return response()->json($ccxtOjlcvData, HTTPStatusCode::OK);
    }
}
