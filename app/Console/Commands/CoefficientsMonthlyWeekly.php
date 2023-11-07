<?php

namespace App\Console\Commands;

use App\Coefficient;
use App\Http\DateFormat\DateFormat;
use App\Services\CoefficientService;
use App\Services\CryptoCurrencyService;
use App\TopCryptocurrency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class CoefficientsMonthlyWeekly extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'coefficients:get {--date=} {--interval=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // for usd
        $interval = !empty($this->option('interval')) ? $this->option('interval') : 'weekly';
        $dateTo = !empty($this->option('date')) ? $this->option('date') : date(DateFormat::DATE_FORMAT,
            strtotime("-1 day"));

        if ($interval == 'monthly') {
            $dateFrom = date('Y-m-t', strtotime($dateTo. "-1 month"));
            $daysCount = cal_days_in_month(CAL_GREGORIAN, date('m', strtotime($dateTo)), date('Y', strtotime($dateTo))) + 1;
        } else {
            $dateFrom = date('Y-m-d', strtotime($dateTo . "-7 days"));
            $daysCount = 8;
        }

        $cIds = TopCryptocurrency::limit(200)->pluck('cryptocurrency_id')->toArray();

        $cryptoService = new CryptoCurrencyService();
        $coefficientService = new CoefficientService();

        $cryptocurrenciesWithQuotes = $cryptoService->getCryptocurrencyWithOhlcv($cIds, $dateFrom, $dateTo, 'daily');

        foreach ($cryptocurrenciesWithQuotes as $cryptocurrency) {
            $vol = 0;
            $m = 0;
            $count = count($cryptocurrency->ohlcvQuotes);

            $this->info($cryptocurrency->cryptocurrency_id);
            $this->info($cryptocurrency->symbol);
            $this->info($count . '==' . $daysCount);

            if ($cryptocurrency->ohlcvQuotes && $count == $daysCount) {
                //   M = (X1 + X2 + ... + Xn)/n
                //    Xn = ln(X_n/X_n-1)
                foreach ($cryptocurrency->ohlcvQuotes as $key => $cQuote) {
                    //X1 = ln(X0/X1)
                    if ($key + 1 != $cryptocurrency->ohlcvQuotes->count()) {
                        $x1 = $cryptocurrency->ohlcvQuotes[$key]->close;
                        $x2 = $cryptocurrency->ohlcvQuotes[$key + 1]->close;

                        if ($x1 && $x2) {
                            $m += log($x2 / $x1);
                        }
                    }
                }
                $m = $m / $count;

                //Vw = 100 * Vol / Vol_max
                //SUM = (M - X1)^2+(M - X2)^2 + ... + (M - X24)^2

                foreach ($cryptocurrency->ohlcvQuotes as $key2 => $cQuote) {
                    //    Xn = ln(X_n/X_n-1)
                    if ($key2 + 1 != $cryptocurrency->ohlcvQuotes->count()) {
                        $x1 = $cryptocurrency->ohlcvQuotes[$key2]->close;
                        $x2 = $cryptocurrency->ohlcvQuotes[$key2 + 1]->close;

                        if ($x1 && $x2) {
                            $vol += pow($m - log($x2 / $x1), 2); //sum

                        }
                    }
                }

                $vol = $vol / ($count - 1);

                $maxVol = $coefficientService->getMaxVolatility();

                if(!$maxVol) {
                    $maxVol = $vol;
                }

                $vw = 100 * $vol / $maxVol;

                // S = R / V
                // R = (Xn - X1) / X1,

                $firstQuote = $cryptocurrency->ohlcvQuotes[1];
                $lastQuote = $cryptocurrency->ohlcvQuotes[$count - 1];
                $r = $firstQuote->close ? ($lastQuote->close - $firstQuote->close) / $firstQuote->close : null;
                $s = round($r / $vol, 4);

                $coefficient = Coefficient::where('cryptocurrency_id', $cryptocurrency->cryptocurrency_id)
                    ->where('interval', $interval)
                    ->whereDate('c_date', $dateTo)
                    ->first();

                if (!$coefficient) {
                    $coefficient = new Coefficient();
                }

                $coefficient->cryptocurrency_id = $cryptocurrency->cryptocurrency_id;
                $coefficient->convert = 'USD';
                $coefficient->volatility = $vol;
                $coefficient->volatility_w = $vw;
                $coefficient->sharpe = $s;
                $coefficient->interval = $interval;
                $coefficient->c_date = $dateTo;

                $coefficient->save();
            }

        }
        if ($interval === 'monthly') {
            sleep(Config::get('commands_sleep.coefficients_get_monthly'));
        } else {
            sleep(Config::get('commands_sleep.coefficients_get_weekly'));
        }
    }
}
