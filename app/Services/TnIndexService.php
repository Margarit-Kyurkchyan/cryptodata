<?php
/**
 * Created by PhpStorm.
 * User: ashot
 * Date: 2/26/19
 * Time: 5:33 PM
 */

namespace App\Services;


use App\Exceptions\IncorrectTypeException;
use App\TnIndex;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TnIndexService
{

    public function getTnIndexes($index_type, $from, $to)
    {
        $top = $this->getIndexes($index_type, $from, $to);

        if (count($top) == 0) {
            return false;
        }
        $data = [];
        foreach ($top as $t) {
            $d = [];
            $value = $t[$index_type];
            $time = $t->timestamp;
            $d['value'] = $value;
            $d['time'] = $time;
            $data[] = $d;
        }

        return $data;

    }

    public function getTnByTops($topCrypts, $count, $date)
    {
        $tn = 0;
        $top = DB::table('ohlcv_quotes')
            ->select('close')
            ->where('convert', 'USD')
            ->where('interval', 'daily')
            ->where('time_period', 'daily')
            ->whereDate('timestamp', $date)
            ->whereIn('cryptocurrency_id', $topCrypts)
            ->get();

        if (count($top) == $count) {
            $tn = $top->avg('close');
        }

        return $tn;
    }

    public function getIndexes($index_type, $dateFrom, $dateTo)
    {
        if (!in_array($index_type, self::getChartTypes())) {
            throw new IncorrectTypeException('This data type ' . $index_type . ' does not exist');
        }

        $tnIndex = TnIndex::select($index_type, 'timestamp')
            ->whereBetween('timestamp', [$dateFrom, $dateTo])
            ->orderBy('timestamp')
            ->get();
        return $tnIndex;
    }

    public function getTnIndexesforMonth($lastMonth)
    {
        $tnIndexesforMonth = TnIndex::whereMonth('timestamp', '=', date('m', $lastMonth))
            ->whereYear('timestamp', '=', date('Y', $lastMonth))->get();
        return $tnIndexesforMonth;

    }

    public function getTnIndexbyDate($date)
    {
        $tnIndex = TnIndex::select('tn100', 'timestamp')->whereDate('timestamp', $date)->first();
        return $tnIndex;
    }

    public static function getChartTypes()
    {
        return [
            'tn10',
            'tn50',
            'tn100',
            'tn200'
        ];
    }

    public function getChartsDataByType(string $type, string $periodStartDate, string $periodEndDate, array $data, int $objectAmount)
    {
        $periodStartDate = $this->getStartDate($periodStartDate);
        $periodEndDate = $this->getEndDate($periodEndDate);
        if (!in_array($type, self::getChartTypes())) {
          throw new IncorrectTypeException('This data type ' . $type . ' does not exist');
        }
        $tnIndex = TnIndex::query()->select(
            DB::raw("DATE_FORMAT(timestamp, '%Y-%m-%d') as timestamp"),
            $type
        )
            ->whereBetween('timestamp', [$periodStartDate . ' 00:00:00', $periodEndDate . ' 00:00:00'])
            ->orderBy('timestamp', 'asc')
            ->get();
        $tnIndexeArray = [];
        if ($objectAmount !==0) {
            $CountDataShow = round(count($tnIndex) / $objectAmount);
            if ($CountDataShow > 1) {
                foreach ($tnIndex as $key => $value) {
                    if ($key % $CountDataShow === 0) {
                        $tnIndexArray[] = $value;
                    }
                }
            }
        }else{
            $tnIndexArray = $tnIndex;
        }
        $data['filters']['period_date_start'] = $periodStartDate;
        $data['filters']['period_date_end'] = $periodEndDate;
        $data['data'] = $tnIndexArray;
        return $data;
    }

    protected function getStartDate(string $startAt): string
    {
        $today = Carbon::now()->subMonth()->format('Y-m-d');
        $startAt = strtotime($startAt) ? $startAt : $today;
        return $startAt;
    }

    protected function getEndDate($endAt): string
    {
        $today = Carbon::now()->startOfDay()->format('Y-m-d');
        $endAt = strtotime($endAt) ? $endAt : $today;
        return $endAt;
    }
}
