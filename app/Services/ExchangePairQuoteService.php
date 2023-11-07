<?php

namespace App\Services;
use App\ExchangePairQuotes;
use App\MarketPair;
use App\Cryptocurrency;
use App\Exchange;
use GuzzleHttp\Client;
use App\Services\CoinBaseService;

class ExchangePairQuoteService
{
	public function getTodayQuotes($crypto_id)
	{

	}

	public function checkTodayQuotes($crypto_id)
	{
		$pairs = MarketPair::select('id')
			->where('string1_id', $crypto_id)
			->orWhere('string2_id', $crypto_id)
			->get();
		$pairs_array = [];
		foreach ($pairs as $key => $value) {
			$pairs_array[] = $value->id;
		}


		$quote_bd = ExchangePairQuotes::whereIn('market_pair_id', $pairs_array)
			->whereDate('updated_at', date('Y-m-d'))
			->count();
		if ($quote_bd > 0) {
			return true;
		}else{
			return false;
		}
	}
	public function oldVolumePair($pairId,$stockId)
	{
		$oldVolumePair = ExchangePairQuotes::select('volume_24h')
			->where('market_pair_id', $pairId)
			->where('exchange_id', $stockId)
			->whereDate('updated_at', '=', date('Y-m-d',strtotime("-1 day")))
			->first();
		if (!empty($oldVolumePair)) {
			return $oldVolumePair->volume_24h;
		}else{
			return 0;
		}
	}
	public function getTopMarketPairs($pairsIdArray, $limit, $page, $symbol)
    {
        $topPairs = ExchangePairQuotes::leftJoin('market_pairs', 'exchange_pair_quotes.market_pair_id', '=',
            'market_pairs.id')
        	->leftJoin('exchanges as exchange', 'exchange_pair_quotes.exchange_id', '=', 'exchange.id')
        	->leftJoin('cryptocurrencies as c1', 'market_pairs.string1_id', '=',
            'c1.cryptocurrency_id')
            ->leftJoin('cryptocurrencies as c2', 'market_pairs.string2_id', '=', 'c2.cryptocurrency_id')
            ->whereIn('exchange_pair_quotes.market_pair_id', $pairsIdArray)
            ->whereDate('exchange_pair_quotes.updated_at', date('Y-m-d'))
            ->select(
            	'exchange.name as exchange_name',
            	'exchange.logo_2 as exchange_logo',
            	'c1.symbol as base',
            	'c2.symbol as quote',
            	'exchange_pair_quotes.price as price',
            	'exchange_pair_quotes.volume_24h as volume_24',
            	'exchange_pair_quotes.percent_value_24h as percent_value_24h')
            ->orderBY('volume_24h', 'DESC')
            ->paginate($limit);

        if (count($topPairs) === 0) {
        	$exchanges = Exchange::select('id as cmc_id', 'exchange_id')->get();
	        $exchanges_array = [];
	        foreach ($exchanges as $key => $value) {
	        	$exchanges_array[$value->cmc_id] = $value->exchange_id;
	        }
	        $TopPairsFrom = $this->saveTopPairsFromCMC($symbol, $exchanges_array);
        	if ($TopPairsFrom) {
        		$topPairs = ExchangePairQuotes::leftJoin('market_pairs', 'exchange_pair_quotes.market_pair_id', '=',
            'market_pairs.id')
        	->leftJoin('exchanges as exchange', 'exchange_pair_quotes.exchange_id', '=', 'exchange.id')
        	->leftJoin('cryptocurrencies as c1', 'market_pairs.string1_id', '=',
            'c1.cryptocurrency_id')
            ->leftJoin('cryptocurrencies as c2', 'market_pairs.string2_id', '=', 'c2.cryptocurrency_id')
            ->whereIn('exchange_pair_quotes.market_pair_id', $pairsIdArray)
            ->whereDate('exchange_pair_quotes.updated_at', date('Y-m-d'))
            ->select(
            	'exchange.name as exchange_name',
            	'exchange.logo_2 as exchange_logo',
            	'c1.symbol as base',
            	'c2.symbol as quote',
            	'exchange_pair_quotes.price as price',
            	'exchange_pair_quotes.volume_24h as volume_24',
            	'exchange_pair_quotes.percent_value_24h as percent_value_24h')
            ->orderBY('volume_24h', 'DESC')
            ->paginate($limit);
        	}else{
        		return false;
        	}
        }
    	$response['total'] = $topPairs->total();
    	$topPairsArray = [];
    	foreach ($topPairs as $key => $value) {
        	$topPairsArray[] = [
        		'rank' 				=> $key + 1 + $limit* ($page - 	1),
        		'exchange_logo' 	=> $value['exchange_logo'],
        		'exchange_name' 	=> $value['exchange_name'],
        		'pair_name' 		=> $value['base'] . '/' . $value['quote'],
        		'price' 			=> $value['price'],
        		'volume_24h' 		=> $value['volume_24'],
        		'percent_value_24h' => $value['percent_value_24h'],
        	];
        }
        $response['data'] = $topPairsArray;
        return $response;
    }
    public function getIdCurrencyByTicker(string $ticker)
    {
    	$crypto = Cryptocurrency::where('symbol', strtoupper($ticker))->select('cryptocurrency_id')->first();
    	if ($crypto === null) {
    		return false;
    	}
    	return($crypto->cryptocurrency_id);
    }
    public function getPairsIdsBySymbolId($symbolId)
    {
    	$pairs= MarketPair::select('id')
    		->where('string1_id', $symbolId)
    		->orWhere('string2_id', $symbolId)
    		->get()->toArray();
    	if (count($pairs) !== 0) {
    		$pairs_array = [];
    		foreach ($pairs as $key => $value) {
    			$pairs_array[] = $value['id'];
    		}
    		return $pairs_array;
    	}else{
    		return false;
    	}
    }
    public function saveTopPairsFromCMC($symbol, $exchanges_array)
    {
    	$query = 	[
        				'limit' => 100,
        				'symbol' => strtoupper($symbol),
        			];
		$client = new Client();
        $response = $client->get('https://pro-api.coinmarketcap.com/v1/cryptocurrency/market-pairs/latest',
            [
                'headers' => ['X-CMC_PRO_API_KEY' => env('API_COIN_KEY')],
                'query' => $query,
            ]);
        $body = $response->getBody();
        $result = json_decode($body, true);
        CoinBaseService::saveRequestCommands($result['status']['error_code'], $result['status']['credit_count'], $symbol, 'USD', env('API_COIN') . 'cryptocurrency/market-pairs/latest');
        if ($result['status']['error_message'] === null) {
        	$data = $result['data']['market_pairs'];
        	if (!empty($data)) {
        		foreach ($data as $key => $value) {
        			list($base, $quote) = explode('/', $value['market_pair']);
        			$pairId = MarketPair::getPairId($base, $quote);
        			if ($pairId == null) {
        				$baseId = Cryptocurrency::where('symbol', $base)->select('cryptocurrency_id')->first();
        				if ($baseId){
        					$baseId = $baseId->cryptocurrency_id;

        				}else{
        					continue;
        				}
        				$quoteId = Cryptocurrency::where('symbol', $quote)->select('cryptocurrency_id')->first();
        				if ($quoteId){
        					$quoteId = $quoteId->cryptocurrency_id;
        				}else{
        					continue;
        				}
        				$pair = MarketPair::create(['string1_id' => $baseId, 'string2_id' => $quoteId]);
        				$pairId = $pair->id;
        			}
        			if (array_key_exists($value['exchange']['id'], $exchanges_array)) {
        				$stockId = $value['exchange']['id'];
        			}else{
        				continue;
        			}
        			$price  		= $value['quote']['exchange_reported']['price'];
        			$convertPrice 	= $value['quote']['USD']['price'];
        			$volume24 		= $value['quote']['USD']['volume_24h'];
        			$volume24_old 	= $this->oldVolumePair($pairId,$stockId);
        			$volume24_change = ($volume24_old !== 0) ? (($volume24 - $volume24_old)/$volume24_old * 100) : 0;
        			$symbol 		= 2068;
        			$count_quote_update = ExchangePairQuotes::where('market_pair_id','=', $pairId)
        				->where('exchange_id', '=', $stockId)->count();
        			if ($count_quote_update === 1) {
        				$quote_update = ExchangePairQuotes::where('market_pair_id','=', $pairId)
        				->where('exchange_id', '=', $stockId)
        				->update([
        					'symbol' 			=> $symbol,
        					'price' 			=> $price,
        					'convert_price' 	=> $convertPrice,
        					'volume_24h' 		=> $volume24,
        					'percent_value_24h' => $volume24_change
        				]);
        			}else{
        				$quote_update = ExchangePairQuotes::create([
        					'market_pair_id'	=> $pairId,
        					'exchange_id'		=> $stockId,
        					'symbol' 			=> $symbol,
        					'price' 			=> $price,
        					'convert_price' 	=> $convertPrice,
        					'volume_24h' 		=> $volume24,
        					'percent_value_24h' => $volume24_change
        				]);
        			}
        		}
        		return true;
        	}else{
        		return false;
        	}
        }else{
        	return false;
        }
    }
}
