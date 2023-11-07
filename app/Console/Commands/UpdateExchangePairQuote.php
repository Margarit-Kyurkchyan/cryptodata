<?php 

namespace App\Console\Commands;

use App\TopCryptocurrency;
use App\Exchange;
use App\MarketPair;
use App\ExchangePairQuotes;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use App\Services\ExchangePairQuoteService;
use App\Services\CoinBaseService;
use App\Cryptocurrency;
use  App\Services\SleepService;


class UpdateExchangePairQuote extends Command
{
	/**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pairquote:daily';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update exchange pairs quotes TOP 100 cryptocurrencies';

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
        $this->info('Start update exchange pairs quotes TOP 100 ' . date('H:i:s d-m-Y'));
        $topId = TopCryptocurrency::limit(100)->leftJoin('cryptocurrencies', 'top_cryptocurrencies.cryptocurrency_id', '=', 'cryptocurrencies.cryptocurrency_id')->select(
        			'top_cryptocurrencies.cryptocurrency_id as cryptocurrency_id',
        			'cryptocurrencies.symbol as symbol'
    			)
        	->get();

        $exchanges = Exchange::select('id as cmc_id', 'exchange_id')->get();
        $exchanges_array = [];
        foreach ($exchanges as $key => $value) {
        	$exchanges_array[$value->cmc_id] = $value->exchange_id;
        }
        foreach ($topId as $key_topId => $value_topId) {

        	$quoteService = new ExchangePairQuoteService;
        	$quoteService->saveTopPairsFromCMC($value_topId->symbol, $exchanges_array);
    		
        }
        $this->info('Finish update exchange pairs quotes TOP 100 ' . date('H:i:s d-m-Y') );

        $sleep = new SleepService;
        sleep($sleep->intervalSleepEveryDayByTime(Config::get('commands_sleep.pairquote')));

    }

}
