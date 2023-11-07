<?php

namespace App\Http\Controllers;

use App\Http\StatusCode\HTTPStatusCode;
use Illuminate\Http\Request; 
use App\OhlcvQuote;
use App\Exceptions\EmptyEntityListException;
use Carbon\Carbon;
use DateTime;
use App\Services\CapitalizationService;
class CapitalizationController extends Controller
{

    public function getChartsDataByTicker(Request $request)
    {
        $periodStartDate = $request->get('period_date_start', '');
        $periodEndDate = $request->get('period_date_end', '');
        $step = $request->get('period_interval', CapitalizationService::CHART_STEP_WEEK);
        $objectAmount = $request->get('object_amount', 0);
        $currency = $request->get('currency', 'btc');
        $data = [
            'status' => [
                'error_message' => 0,
                'error_code' => null
            ],
            'filters' => [
                'period_date_start' => $periodStartDate,
                'period_date_end' => $periodEndDate,
                'period_interval' => $step,
                'object_amount' => $objectAmount,
                'period_intervals' => CapitalizationService::getPeriodIntervals(),
                'currency' => $currency,
            ],
            'data' => []
        ];
        $CapitalizationService = new CapitalizationService;
        try {
            $data = $CapitalizationService->getChartDataByTicker($periodStartDate, $periodEndDate, $step, $data, $objectAmount, $currency);
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


    
}
