<?php

namespace App\Console\Commands;

use App\Cryptocurrency;
use App\Services\CurrencyOhlcvService;
use App\Services\MarketPairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class CryptocurrencyOhlcvCmc extends Command
{
    const INTERVAL_HOURLY = "hourly";
    const INTERVAL_4H = "4h";
    const INTERVAL_12H = "12h";
    const INTERVAL_DAILY = "daily";
    const INTERVAL_WEEKLY = "weekly";
    const INTERVAL_MONTHLY = "monthly";
    const INTERVAL_MODELS = [
        'daily' => 'OhlcvModels\ohlcv_cmc_1d',
        'weekly' => 'OhlcvModels\ohlcv_cmc_1w',
        'monthly' => 'OhlcvModels\ohlcv_cmc_30d',
        'hourly' => 'OhlcvModels\ohlcv_cmc_1h',
        '4h' => 'OhlcvModels\ohlcv_cmc_4h',
        '12h' => 'OhlcvModels\ohlcv_cmc_12h'
    ];
    const DEFAULT_COINS_QUOTES_SYMBOL = 'USD';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cryptocurrency:ohlcv {--start=} {--time_end=} {--time_start=} {--interval=} {--convert=} {--pairs_convert=no} {--rewrite=no}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fill ohlcv tables.';

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
        $service = new CurrencyOhlcvService();
        $marketPairService = new MarketPairService();
        $start = !empty($this->option('start')) ? $this->option('start') : 0;
        $allCrypts = Cryptocurrency::select('cryptocurrency_id', 'id', 'symbol')->get();
        $convert = !empty($this->option('convert')) ? $this->option('convert') : self::DEFAULT_COINS_QUOTES_SYMBOL;
        $quote = Cryptocurrency::where('symbol', $convert)->first();
        $pairsConvert = $this->option('pairs_convert');
        $rewrite = $this->option('rewrite');

        $interval = !empty($this->option('interval')) ? $this->option('interval') : self::INTERVAL_DAILY;
        if ($interval == self::INTERVAL_HOURLY || $interval == self::INTERVAL_4H || $interval == self::INTERVAL_12H) {
            $timePeriod = self::INTERVAL_HOURLY;
        } else {
            $timePeriod = self::INTERVAL_DAILY;
        }
        $timeEnd = !empty($this->option('time_end')) ? date('Y-m-d  23:59:59', strtotime($this->option('time_end'))) : date('Y-m-d 23:59:59', strtotime("-1 day"));

        if ($interval == self::INTERVAL_WEEKLY) {
            $timeStart = !empty($this->option('time_start')) ? date('Y-m-d  23:59:59', strtotime($this->option('time_start'))) : date('Y-m-d H:i:s', strtotime($timeEnd . "-1 week"));
        } elseif ($interval == self::INTERVAL_MONTHLY) {
            $timeStart = !empty($this->option('time_start')) ? date('Y-m-d  23:59:59', strtotime($this->option('time_start'))) : date('Y-m-d H:i:s', strtotime($timeEnd . "-1 month"));
        } else {
            $timeStart = !empty($this->option('time_start')) ? date('Y-m-d  23:59:59', strtotime($this->option('time_start'))) : date('Y-m-d H:i:s', strtotime($timeEnd . "-1 day"));
        }

        if (!array_key_exists($interval, self::INTERVAL_MODELS)) {
            $this->info("not allowed interval value");
            return false;
        }

        foreach ($allCrypts as $currency) {

            if ($pairsConvert != "no") {
                $marketPairs = $marketPairService->getPairsByCurrency($currency->cryptocurrency_id);

                foreach ($marketPairs as $pair) {
                    //check if exist data for pair $pair->coin1_symbol . ' / ' . $pair->coin2_symbol and count is ok
                    $pairOhlcv = $service->getOhlcvForPair($pair->string1_id, $pair->string2_id, $timeStart, $timeEnd, self::INTERVAL_MODELS[$interval]);
                    $quotePair = Cryptocurrency::where('symbol', $pair->coin2_symbol)->first();

                    if (!$quotePair) {
                        continue;
                    }

                    $quoteId = $quotePair->cryptocurrency_id;

                    if ($rewrite == "no" && $pairOhlcv->count()) {
                        continue;
                    }

                    $result = $service->getCryptoCurrencyOhlcvApiData($currency->id, $pair->coin1_symbol, $timePeriod, $interval, $timeEnd, $timeStart, $pair->coin2_symbol);

                    if ($result['status']['error_code'] == 0) {
                        $service->saveCryptoCurrencyOhlcvData($result['data']['quotes'], self::INTERVAL_MODELS[$interval], $currency->cryptocurrency_id, $quoteId, $pair->coin2_symbol);
                        $this->info('Done for ' . $pair->coin1_symbol . ' / ' . $pair->coin2_symbol);
                    } else {
                        $this->info('Fails for pair '  . $pair->coin1_symbol . ' / ' . $pair->coin2_symbol . ' ' . $result['status']['error_message']);
                        Log::info($this->description . ' fails for pair '  . $pair->coin1_symbol . ' / ' . $pair->coin2_symbol . ' ' . $result['status']['error_message']);
                    }

                    sleep(0.6);
                }
            } else {

                if (!$quote) {
                    return false;
                }

                $quoteId = $quote->cryptocurrency_id;
                $result = $service->getCryptoCurrencyOhlcvApiData($currency->id,  $currency->symbol, $timePeriod, $interval, $timeEnd, $timeStart, $convert);

                if ($result['status']['error_code'] == 0) {
                    $service->saveCryptoCurrencyOhlcvData($result['data']['quotes'], self::INTERVAL_MODELS[$interval], $currency->cryptocurrency_id, $quoteId, $convert);
                    $this->info('Done for ' . $currency->symbol . ' / ' . $convert);
                } else {
                    $this->info('cryptocurrency_id ' . $currency->cryptocurrency_id . ' ' . $result['status']['error_message']);
                    Log::info($this->description . ' fails for pair '  . $currency->symbol . ' / ' . $convert . ' ' . $result['status']['error_message']);
                }

                sleep(0.7);
            }
        }

//        if ($interval == self::INTERVAL_WEEKLY) {
//            sleep(Config::get('commands_sleep.cryptocurrency_ohlcv_cmc__weekly'));
//        } elseif ($interval == self::INTERVAL_MONTHLY) {
//            sleep(Config::get('commands_sleep.cryptocurrency_ohlcv_cmc__monthly'));
//        } else {
//            sleep(Config::get('commands_sleep.cryptocurrency_ohlcv_cmc__daily'));
//        }
        sleep(Config::get('commands_sleep.cryptocurrency_1000000000'));
    }
}
