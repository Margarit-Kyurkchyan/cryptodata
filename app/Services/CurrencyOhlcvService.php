<?php
/**
 * Created by PhpStorm.
 * User: developer
 * Date: 3/15/19
 * Time: 2:04 PM
 */

namespace App\Services;

use App\DataProviders\CryptoCurrencyOhlcvDataProvider;
use App\Http\DateFormat\DateFormat;


class CurrencyOhlcvService
{
    const DEFAULT_COINS_QUOTES_SYMBOL = 'USD';

    public function getCryptoCurrencyOhlcvApiData(int $id, string $symbol, string $timePeriod, string $interval, string $timeEnd, $timeStart, string $convert = '')
    {
        $dataProvider = new CryptoCurrencyOhlcvDataProvider();

        $dataProvider->setFields($id, $symbol, $timePeriod, $interval, $timeEnd, $timeStart, $convert);

        $result = $dataProvider->getData();
        return $result;
    }

    public function getOhlcvForPair($baseId, $quoteId, $timeStart, $timeEnd, $model)
    {
        $modelName = "App\\" . $model;

        $ohlcvQuote = $modelName::where('base_id', $baseId)
            ->where('quote_id', $quoteId)
            ->where('timestamp', '>=', $timeStart)
            ->where('timestamp', '<=', $timeEnd)
            ->get();

        return $ohlcvQuote;
    }

    public function saveCryptoCurrencyOhlcvData($allQuotes, $modelName, $cryptocurrency_id, $quoteId, $convert)
    {
        $modelName = "App\\" . $modelName;
        foreach ($allQuotes as $data) {
            $dateTimestamp = new \DateTime($data['quote'][$convert]['timestamp']);
            $dateTimestampFormat = $dateTimestamp->format(DateFormat::DATE_TIME_FORMAT);

            $ohlcvQuote = $modelName::where('base_id', $cryptocurrency_id)
                ->where('quote_id', $quoteId)
                ->where('timestamp', $dateTimestampFormat)->first();

            if (!$ohlcvQuote) {
                $ohlcvQuote = new $modelName();;
            }

            $ohlcvQuote->base_id = $cryptocurrency_id;
            $ohlcvQuote->quote_id = $quoteId;
            $ohlcvQuote->open = $data['quote'][$convert]['open'];
            $ohlcvQuote->high = $data['quote'][$convert]['high'];
            $ohlcvQuote->low = $data['quote'][$convert]['low'];
            $ohlcvQuote->close = $data['quote'][$convert]['close'];
            $ohlcvQuote->volume = $data['quote'][$convert]['volume'];
            $ohlcvQuote->market_cap = $data['quote'][$convert]['market_cap'];
            $ohlcvQuote->timestamp = $dateTimestampFormat;
            $ohlcvQuote->time_open = $data['time_open'];
            $ohlcvQuote->time_close = $data['time_close'];
            $ohlcvQuote->save();

        }
    }
}
