<?php

namespace App\Services;

use App\OhlcvQuote;
use App\Exceptions\EmptyEntityListException;
use Carbon\Carbon;
use Exception;
use DateTime;
use App\Cryptocurrency;
class CapitalizationService
{
	
    const CHART_STEP_HOUR = 'hour';
    const CHART_STEP_4HOUR = '4hour';
    const CHART_STEP_12HOUR = '12hour';
    const CHART_STEP_DAY = 'day';
    const CHART_STEP_WEEK = 'week';
    const CHART_STEP_MONTH = 'month';
    const DAY_DURATION_IN_SECONDS = 86400;

	public function getChartDataByTicker(string $periodStartDate, string $periodEndDate, string $step, array $data, int $objectAmount, string $currency)
    {   
        

        $interval_array = [ 
            'hour' => '1h',
            '4hour' => '4h',
            '12hour' => '12h',
            'day' => '1d',
            'week' => '1w',
            'month' => '30d',
        ];

    	$currencyId = $this->getIdCurrencyByTicker($currency);
    	if ($currencyId === false) {
    		throw new Exception("This currency not find");
    	}
        $quoteId = $this->getIdCurrencyByTicker('USD');

        $stepTimestamp = $this->getTimestampStep($step);
        $periodStartDate = $this->getStartDateAsTimestamp($periodStartDate, $stepTimestamp);
        $periodEndDate = $this->getEndDateAsTimestamp($periodEndDate);
        $className = 'App\OhlcvModels\ohlcv_cmc_' .  ((array_key_exists($step, $interval_array)) ? $interval_array[$step] : '1h');
        $CapitalizationData = $className::where('quote_id', $quoteId)
		        ->where('base_id', $currencyId)
		        ->whereBetween('timestamp', [date('Y-m-d H:i:s', $periodStartDate), date('Y-m-d H:i:s', $periodEndDate)])
		        ->orderBY('timestamp', 'ASC')
		        ->get();
        if ($CapitalizationData->isEmpty()) {
            throw new EmptyEntityListException('Entity collection list is empty');
        }
        $chartDataInformation = [];
        $lastData = [];
        $startFrom = $periodStartDate;
        foreach ($CapitalizationData as $datum) {
            if (strtotime($datum->timestamp) >= $startFrom) {
                $chartDataInformation[] = [
                    'time' => $datum->timestamp,
                    'value' => $datum->market_cap,
                ];
            } elseif (strtotime($datum->timestamp) < $startFrom) {
                $lastData = [
                    'time' => $datum->timestamp,
                    'value' => $datum->market_cap,
                ];
                continue;
            } elseif ($periodEndDate < $startFrom) {
                break;
            }
        }
        $chartDataInformationArray = [];
        if (($objectAmount !==0) && (count($chartDataInformation)>$objectAmount)) {
            $CountDataShow = round(count($chartDataInformation) / $objectAmount);
            if ($CountDataShow > 1) {
                foreach ($chartDataInformation as $key => $value) {
                    if ($key % $CountDataShow === 0) {
                        $chartDataInformationArray[] = $value;
                    }
                }
            }
        }else{
            $chartDataInformationArray = $chartDataInformation;
        }
        if (!empty($lastData)) {
            $lastData['time'] = date('Y-m-d h:i:s', $startFrom);
            $chartDataInformationArray[] = $lastData;
        }
        $data['filters']['period_date_start'] = date('Y-m-d', $periodStartDate);
        $data['filters']['period_date_end'] = date('Y-m-d', $periodEndDate);
        $data['data'] = $chartDataInformationArray;
        if (count($CapitalizationData) === 0) {
            $data['data'] = [];
        }

        return $data;
    }

    public function getIdCurrencyByTicker(string $ticker)
    {
    	$crypto = Cryptocurrency::where('symbol', strtoupper($ticker))->select('cryptocurrency_id')->first();
    	if ($crypto === null) {
    		return false;
    	}
    	return($crypto->cryptocurrency_id);
    }
    public static function getPeriodIntervals(): array
    {
        return [
            self::CHART_STEP_HOUR,
            self::CHART_STEP_4HOUR,
            self::CHART_STEP_12HOUR,
            self::CHART_STEP_DAY,
            self::CHART_STEP_WEEK,
            self::CHART_STEP_MONTH,
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
            case self::CHART_STEP_HOUR:
                return 60 * 60;
            case self::CHART_STEP_4HOUR:
                return 60 * 60 * 4;
            case self::CHART_STEP_12HOUR:
                return 60 * 60 * 12;
            case self::CHART_STEP_DAY:
                return self::DAY_DURATION_IN_SECONDS;
            case self::CHART_STEP_WEEK:
                return self::DAY_DURATION_IN_SECONDS * 7;
            case self::CHART_STEP_MONTH:
                return self::DAY_DURATION_IN_SECONDS * 30;
            default:
                return 60;
        }
    }
   
}