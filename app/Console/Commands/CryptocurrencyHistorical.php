<?php

namespace App\Console\Commands;

use App\Cryptocurrency;
use App\OhlcvQuote;
use App\OhlcvRequest;
use App\Services\CoinBaseService;
use App\TopCryptocurrency;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use  App\Services\SleepService;


class CryptocurrencyHistorical extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cryptocurrency:historical {--time_end=} {--time_start=} {--interval=} {--time_period=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'save ohlcv for top 200, see TN-419';

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
        $this->info('Start save ohlcv for top 200 ' . date('H:i:s d-m-Y'));
        // get ids from top200
        // save historical data for 200 with convert value USD
        $topCrypts = TopCryptocurrency::pluck('cryptocurrency_coin_id')->toArray();
        $interval = !empty($this->option('interval')) ? $this->option('interval') : 'hourly';
        $timePeriod = !empty($this->option('time_period')) ? $this->option('time_period') : 'hourly';
        $convert = "USD";
        $count = 10;
        $query['interval'] = $interval;
        $query['time_period'] = $timePeriod;

        $timeEnd = !empty($this->option('time_end')) ? date('Y-m-d  23:59:59', strtotime($this->option('time_end'))) : date('Y-m-d 23:59:59', strtotime("-1 day"));
        $timeStart = !empty($this->option('time_start')) ? date('Y-m-d  23:59:59', strtotime($this->option('time_start'))) : date('Y-m-d H:i:s', strtotime($timeEnd . "-1 day"));
        $query['time_end'] = $timeEnd;
        $query['time_start'] = $timeStart;

        foreach ($topCrypts as $id) {
            $query ['id'] = $id;

            try {
                $client = new Client();

                $response = $client->get(env('API_COIN') . 'cryptocurrency/ohlcv/historical',
                    [
                        'headers' => ['X-CMC_PRO_API_KEY' => env('API_COIN_KEY')],
                        'query' => $query,
                    ]);

                $body = $response->getBody();
                $result = json_decode($body, true);
                $cryptocurrency = Cryptocurrency::where('id', $result['data']['id'])->first();
                CoinBaseService::saveRequestCommands($result['status']['error_code'], $result['status']['credit_count'], $cryptocurrency->symbol, $convert, env('API_COIN') . 'cryptocurrency/ohlcv/historical');

                $ohlcvRequest = OhlcvRequest::where('symbol', $cryptocurrency->symbol)
                    ->where('convert', $convert)
                    ->where('count', $count)
                    ->where('interval', $interval)
                    ->where('time_period', $timePeriod)
                    ->first();
                $this->info('done for ' . $id);
                $this->info('count ' . count(($result['data']['quotes'])));

                foreach ($result['data']['quotes'] as $data) {
                    $dateTimestamp = new \DateTime($data['quote'][$convert]['timestamp']);
                    $dateTimestampFormat = $dateTimestamp->format('Y-m-d H:i:s');
                    $ohlcvQuote = OhlcvQuote::where('cryptocurrency_id', $cryptocurrency->cryptocurrency_id)
                        ->where('convert', $convert)
                        ->where('timestamp', $dateTimestampFormat)
                        ->where('time_period', $timePeriod)
                        ->where('interval', $interval)->first();

                    if (!$ohlcvQuote) {
                        $ohlcvQuote = new OhlcvQuote();
                    }

                    // todo maybe we need this part of code only if $ohlcvQuote is empty ???
                    $ohlcvQuote->cryptocurrency_id = $cryptocurrency->cryptocurrency_id;
                    $ohlcvQuote->convert = $convert;
                    $ohlcvQuote->open = $data['quote'][$convert]['open'];
                    $ohlcvQuote->high = $data['quote'][$convert]['high'];
                    $ohlcvQuote->low = $data['quote'][$convert]['low'];
                    $ohlcvQuote->close = $data['quote'][$convert]['close'];
                    $ohlcvQuote->volume = $data['quote'][$convert]['volume'];
                    $ohlcvQuote->market_cap = $data['quote'][$convert]['market_cap'];
                    $ohlcvQuote->timestamp = $dateTimestampFormat;
                    $ohlcvQuote->time_open = $data['time_open'];
                    $ohlcvQuote->time_close = $data['time_close'];
                    $ohlcvQuote->interval = $interval;
                    $ohlcvQuote->time_period = $timePeriod;
                    $ohlcvQuote->save();
                    ////
                }

                if (empty($ohlcvRequest)) {
                    $ohlcvRequest = new OhlcvRequest();
                    $ohlcvRequest->time_start = $timeStart;
                    $ohlcvRequest->time_end = $timeEnd;
                } else {

                    if ($ohlcvRequest->time_start >= $timeStart ){
                        $ohlcvRequest->time_start = $timeStart;
                    }

                    if( $ohlcvRequest->time_end <= $timeEnd) {
                        $ohlcvRequest->time_end = $timeEnd;
                    }
                }

                $ohlcvRequest->symbol = $cryptocurrency->symbol;
                $ohlcvRequest->id = $id;
                $ohlcvRequest->count = $count;
                $ohlcvRequest->interval = $interval;
                $ohlcvRequest->time_period = $timePeriod;
                $ohlcvRequest->convert = $convert;
                $ohlcvRequest->save();
            } catch (ClientException $exception) {
                $this->info('faild for symbol with ID = ' . $id);
                $this->info('time_start = ' . $timeStart);
                $this->info('time_end = ' . $timeEnd);
                $res = json_decode($exception->getResponse()->getBody()->getContents(), true);
                $this->info($res['status']['error_message']);
                CoinBaseService::saveRequestCommands($res['status']['error_code'], $res['status']['credit_count'],'', '', env('API_COIN') . 'cryptocurrency/ohlcv/historical');
            }
            sleep(2);
        }
        $sleep = new SleepService;

        $this->info('finish '.$interval. ' save ohlcv for top 200 ' . date('H:i:s d-m-Y'));
        
        if ($interval === 'hourly' && $timePeriod === 'hourly') {
            sleep($sleep->intervalSleepEveryDayByTime(Config::get('
                commands_sleep.cryptocurrency_historical')));
        } elseif ($interval === 'daily' && $timePeriod === 'daily') {
            sleep($sleep->intervalSleepEveryDayByTime(Config::get('commands_sleep.cryptocurrency_historical_daily')));
        }
    }
}
