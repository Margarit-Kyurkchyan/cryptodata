<?php

namespace App\Http\Controllers;

use App\Http\StatusCode\HTTPStatusCode;
use App\Services\TnIndexService;
use Illuminate\Http\Request;

class TnIndexesController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCharts(Request $request)
    {
        $objectAmount = $request->get('object_amount', 0);
        $type = $request->get('type', 'tn10');
        $periodStartDate = $request->get('period_date_start', '');
        $periodEndDate = $request->get('period_date_end', '');
        $data = [
            'status' => [
                'error_message' => 0,
                'error_code' => null
            ],
            'filters' => [
                'type' => $type,
                'period_date_start' => $periodStartDate,
                'period_date_end' => $periodEndDate,
                'object_amount' => $objectAmount,
                'types' => TnIndexService::getChartTypes()
            ],
            'data' => []
        ];
        $tnIndexService = new TnIndexService();
        try {
            $data = $tnIndexService->getChartsDataByType($type, $periodStartDate, $periodEndDate, $data, $objectAmount);
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
