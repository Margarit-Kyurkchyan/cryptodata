<?php

namespace App\Console\Commands;

use App\Cryptocurrency;
use App\Services\CoinBaseService;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\File;

class CryptocurrencyInfo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cryptocurrency:info {--id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'update data from cryptocurrency/info';

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
        $pathStr = '/images/cryptocurrency/logo';

        if (!empty($this->option('id'))) {
            $idsArray[] = [$this->option('id')];
        } else {
            $allCrypts = Cryptocurrency::pluck('id')->toArray();
            $idsArray = array_chunk($allCrypts, 500);
        }

        foreach ($idsArray as $ids) {
            $idsStr = implode(',', $ids);
            $client = new Client();
            $response = $client->get(env('API_COIN') . 'cryptocurrency/info',
                [
                    'headers' => ['X-CMC_PRO_API_KEY' => env('API_COIN_KEY')],
                    'query' => ['id' => $idsStr],
                ]);

            $body = $response->getBody();
            $result = json_decode($body, true);
            CoinBaseService::saveRequestCommands($result['status']['error_code'], $result['status']['credit_count'], '', '', env('API_COIN') . 'cryptocurrency/info');

            foreach ($result['data'] as $item) {
                $urls = isset($item['urls']) ? json_encode($item['urls']) : null;
                $logo = isset($item['logo']) ? $item['logo'] : null;
                $id = $item['id'];
                $name = $item['symbol'] . '.' . pathinfo($logo, PATHINFO_EXTENSION);
                $path = public_path($pathStr);

                if (!file_exists($path . '/' . $name)) {
                    // save logo in the /images/cryptocurrency/logo
                    File::isDirectory($path) or File::makeDirectory($path, 0755, true, true);
                    $contents = @file_get_contents($logo);
                    file_put_contents($path . '/' . $name, $contents);
                    // end save logo
                }

                Cryptocurrency::where('id', $id)->update(['urls' => $urls, 'logo' => $logo, 'logo_2' => $pathStr . '/' . $name]);
            }
        }
    }
}
