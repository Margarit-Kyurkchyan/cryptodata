<?php
/**
 * Created by PhpStorm.
 * User: developer
 * Date: 3/11/19
 * Time: 7:18 PM
 */

namespace App\Services;


use App\Coefficient;
use App\GlobalCoefficient;

class CoefficientService
{
    const DAILY_INTERVAL = 'daily';
    const WEEKLY_INTERVAL = 'weekly';
    const MONTHLY_INTERVAL = 'monthly';
    const CHART_TYPE_RETURN = 'return';

    const CHART_TYPES = [
        'return' => 'annualized_return'
    ];

    public function getCoefficentsAndSave($cryptocurrency_id, $dateTo, $interval, $alpha = 0, $beta = 0, $sharpe = 0, $volatility = 0, $sortino = 0)
    {
        $coefficient = Coefficient::where('cryptocurrency_id', $cryptocurrency_id)
            ->where('interval', $interval)
            ->whereDate('c_date', $dateTo)
            ->where('convert', 'USD')
            ->first();

        if (!$coefficient) {
            $coefficient = new Coefficient();
        }

        $coefficient->cryptocurrency_id = $cryptocurrency_id;
        $coefficient->convert = 'USD';
        $coefficient->c_date = $dateTo;
        $coefficient->interval = $interval;

        if ($alpha) {
            $coefficient->alpha = $alpha;
        }

        if ($beta) {
            $coefficient->beta = $beta;
        }

        if ($sharpe) {
            $coefficient->sharpe = $sharpe;
        }

        if ($volatility) {
            $coefficient->volatility = $volatility;
        }

        if ($sortino) {
            $coefficient->sortino = $sortino;
        }

        $coefficient->save();
    }

    public function saveCoefficient($ar, $dateTo, $interval)
    {
        $coefficient = GlobalCoefficient::whereDate('timestamp', $dateTo)->first();

        if (!$coefficient) {
            $coefficient = new GlobalCoefficient();
        }

        $coefficient->annualized_return = $ar;
        $coefficient->timestamp = $dateTo;
        $coefficient->interval = $interval;
        $coefficient->save();
    }

    public function getChartDataByType(string $chartType, string $periodStartDate, string $periodEndDate, string $interval)
    {
        $chartType = self::CHART_TYPES[$chartType];
        $coefficients = GlobalCoefficient::whereBetween('timestamp', [$periodStartDate, $periodEndDate])
            ->where('interval', $interval)->get();
        $data = [];

        foreach ($coefficients as $coefficient) {
            $data[] = [
                'time' => $coefficient->timestamp,
                'value' => $coefficient[$chartType]
            ];
        }

        return $data;
    }

    public function getMaxVolatility()
    {
        $maxVol = Coefficient::max('volatility');
        return $maxVol;
    }

    public static function getPeriodIntervals(): array
    {
        return [
            self::DAILY_INTERVAL,
            self::WEEKLY_INTERVAL,
            self::MONTHLY_INTERVAL,
        ];
    }
}
