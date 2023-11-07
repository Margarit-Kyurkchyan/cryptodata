<?php

namespace App\Console\Commands;

use App\Services\CryptoCurrencyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class CryptocurrencyListing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cryptocurrency:listing_update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'update cryptocurrency listing data';

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
        $service = new CryptoCurrencyService();
        $data = $service->getCryptoCurrencyApiData();
        $service->updateCryptoCurrencyData($data);
        sleep(Config::get('commands_sleep.cryptocurrency_listing_update'));
    }
}