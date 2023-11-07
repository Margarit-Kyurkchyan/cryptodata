<?php

namespace App\Services;

use App\DataProviders\GlobalMetricsHistoryProvider;
use App\Exceptions\EmptyEntityListException;
use App\GlobalMetricsHistorical;
use App\GlobalMetricsHistoricalRequests;
use Carbon\Carbon;
use DateTime;

class GlobalMetricService
{
    const CHART_TYPE_DOMINANCE = 'btc_dominance';
    const CHART_TYPE_MARKET_CUP = 'market_cap';

    const CHART_STEP_MINUTE = 'minute';
    const CHART_STEP_HOUR = 'hour';
    const CHART_STEP_DAY = 'day';
    const CHART_STEP_WEEK = 'week';
    const DAY_DURATION_IN_SECONDS = 86400;


    const DEFAULT_QUOTE_COIN = 'USD';


    public function getChartDataByType(string $chartType, string $periodStartDate, string $periodEndDate, string $step, array $data, int $objectAmount)
    {
        $globalMetricsData = GlobalMetricsHistorical::where('convert', self::DEFAULT_QUOTE_COIN)->orderBY('timestamp', 'ASC')->get();
        if ($globalMetricsData->isEmpty()) {
            throw new EmptyEntityListException('Entity collection list is empty');
        }

        $stepTimestamp = $this->getTimestampStep($step);
        $periodStartDate = $this->getStartDateAsTimestamp($periodStartDate, $stepTimestamp);
        $periodEndDate = $this->getEndDateAsTimestamp($periodEndDate);
        $chartDataInformation = [];
        $lastData = [];
        $startFrom = $periodStartDate;
        foreach ($globalMetricsData as $datum) {
            list($value, $symbol) = $this->getChartFieldsByType($chartType, $datum);
            if (strtotime($datum->timestamp) >= $startFrom) {
                for(; strtotime($datum->timestamp) >= $startFrom; $startFrom += $stepTimestamp) {
                    if ($startFrom > $periodEndDate) {
                        break;
                    }
                    $chartDataInformation[] = [
                        'time' => date('Y-m-d H:i:s', $startFrom),
                        'value' => $value,
                        'symbol' => $symbol
                    ];
                }
            } elseif (strtotime($datum->timestamp) < $startFrom) {
                $lastData = [
                    'time' => date('Y-m-d H:i:s', $startFrom),
                    'value' => $value,
                    'symbol' => $symbol,
                ];
                continue;
            } elseif ($periodEndDate < $startFrom) {
                break;
            }
        }
        $chartDataInformationArray = [];
        if ($objectAmount !==0) {
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
        $dataInPeriod = GlobalMetricsHistorical::whereBetween('timestamp', [date('Y-m-d H:i:s', $periodStartDate), date('Y-m-d H:i:s', $periodEndDate)])->get();
        $data['filters']['period_date_start'] = date('Y-m-d', $periodStartDate);
        $data['filters']['period_date_end'] = date('Y-m-d', $periodEndDate);
        $data['data'] = $chartDataInformationArray;
        if (count($dataInPeriod) === 0) {
            $data['data'] = [];
        }
        return $data;
    }

    public function getGmHistoricalApiData($interval, $timeEnd, $timeStart, $convert, $count)
    {
        $dataProvider = new GlobalMetricsHistoryProvider();
        $dataProvider->setFields($interval, $timeEnd, $timeStart, $convert, $count);
        $result = $dataProvider->getData();
        return $result;
    }

    public function saveGmHistoricalRequests($timeStart, $timeEnd, $convert, $interval, $count)
    {

        $globalMetricsHistoricalRequests = new GlobalMetricsHistoricalRequests();
        $globalMetricsHistoricalRequests->time_start = $timeStart;
        $globalMetricsHistoricalRequests->time_end = $timeEnd;
        $globalMetricsHistoricalRequests->interval = $interval;
        $globalMetricsHistoricalRequests->convert = $convert;
        $globalMetricsHistoricalRequests->count = $count;
        $globalMetricsHistoricalRequests->save();
    }

    public function saveGmHistoricalData($data, $convert, $interval, $count)
    {

        foreach ($data['quotes'] as $key => $dataItem) {
            $key = key($dataItem['quote']);

            if (isset($dataItem['timestamp'])) {
                $timestamp = new DateTime($dataItem['timestamp']);
                $timestampFormat = $timestamp->format('Y-m-d H:i:s');
            } else {
                $timestampFormat = null;
            }

            if (isset($dataItem['quote'][$key]['timestamp'])) {
                $timestampQuote = new DateTime($dataItem['quote'][$key]['timestamp']);
                $timestampQuoteFormat = $timestampQuote->format('Y-m-d H:i:s');
            } else {
                $timestampQuoteFormat = null;
            }

            $globalmetrichistorical = GlobalMetricsHistorical::firstOrNew(['convert' => $convert, 'timestamp' => $timestampFormat, 'timestamp_quote' => $timestampQuoteFormat]);

            $globalmetrichistorical->timestamp = $timestampFormat;
            $globalmetrichistorical->timestamp_quote = $timestampQuoteFormat;
            $globalmetrichistorical->btc_dominance = isset($dataItem['btc_dominance']) ? $dataItem['btc_dominance'] : null;
            $globalmetrichistorical->convert = isset($key) ? $key : null;
            $globalmetrichistorical->total_market_cap = isset($dataItem['quote'][$key]['total_market_cap']) ? $dataItem['quote'][$key]['total_market_cap'] : null;
            $globalmetrichistorical->total_volume_24h = isset($dataItem['quote'][$key]['total_volume_24h']) ? $dataItem['quote'][$key]['total_volume_24h'] : null;
            $globalmetrichistorical->interval = $interval;
            $globalmetrichistorical->count = $count;
            $globalmetrichistorical->save();

        }

    }

    public static function getChartTypes(): array
    {
        return [
            self::CHART_TYPE_DOMINANCE,
            self::CHART_TYPE_MARKET_CUP
        ];
    }

    public static function getPeriodIntervals(): array
    {
        return [
            self::CHART_STEP_MINUTE,
            self::CHART_STEP_HOUR,
            self::CHART_STEP_DAY,
            self::CHART_STEP_WEEK,
        ];
    }

    protected function getChartFieldsByType(string $chartType, $datum): array
    {
        switch ($chartType) {
            case self::CHART_TYPE_MARKET_CUP:
                return [
                    $datum->total_market_cap,
                    '$'
                ];
            case self::CHART_TYPE_DOMINANCE:
                return [
                    $datum->btc_dominance,
                    '%'
                ];
            default:
                return [
                $datum->total_market_cap,
                '$'
            ];
        }
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
            case self::CHART_STEP_MINUTE:
                return 60;
            case self::CHART_STEP_HOUR:
                return 60 * 60;
            case self::CHART_STEP_DAY:
                return self::DAY_DURATION_IN_SECONDS;
            case self::CHART_STEP_WEEK:
                return self::DAY_DURATION_IN_SECONDS * 7;
            default:
                return 60;
        }
    }
}
