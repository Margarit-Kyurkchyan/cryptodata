<?php

namespace App\Console\Commands;

use App\Exchange;
use App\Services\CoinBaseService;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

class ExchangeInfo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exchange:info';

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
        $pathStr = '/images/exchange/logo';
        $exchanges = Exchange::select('id')->where('logo', '!=', '')->get();
        $ids = '';

        foreach ($exchanges as $exchange) {

            $ids .= $exchange->id . ',';
        }

        $ids = trim($ids, ',');

        try {

            $client = new Client();
            $response = $client->get(env('API_COIN') . 'exchange/info',
                [
                    'headers' => ['X-CMC_PRO_API_KEY' => env('API_COIN_KEY')],
                    'query' => ['id' => $ids],
                ]);

            $body = $response->getBody();
            $result = json_decode($body, true);
            CoinBaseService::saveRequestCommands($result['status']['error_code'], $result['status']['credit_count'], '', '', env('API_COIN') . 'exchange/info');
        } catch (ClientException $exception) {
            $res = json_decode($exception->getResponse()->getBody()->getContents(), true);
            $this->info($res['status']['error_message']);
            CoinBaseService::saveRequestCommands($res['status']['error_code'], $res['status']['credit_count'],'', '', env('API_COIN') . 'cryptocurrency/ohlcv/historical');
            return false;
        }


        foreach ($result['data'] as $item) {
            $urls = isset($item['urls']) ? json_encode($item['urls']) : null;
            $logo = isset($item['logo']) ? $item['logo'] : null;
            $id = $item['id'];
            $name = $item['slug'] . '.' . pathinfo($logo, PATHINFO_EXTENSION);
            $path = public_path($pathStr);

            if (!file_exists($path . '/' . $name)) {
                // save logo in the /images/cryptocurrency/logo
                File::isDirectory($path) or File::makeDirectory($path, 0755, true, true);
                $contents = @file_get_contents($logo);
                file_put_contents($path . '/' . $name, $contents);
                // end save logo
            }


            Exchange::where('id', $id)->update(['urls' => $urls, 'logo' => $logo, 'logo_2' => $pathStr . '/' . $name]);
        }
        sleep(Config::get('commands_sleep.exchange_info'));
    }
}
