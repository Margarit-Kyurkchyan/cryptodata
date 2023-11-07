<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * App\OhlcvQuote
 *
 * @property int $id
 * @property int $cryptocurrency_id
 * @property string $convert
 * @property float|null $open
 * @property float|null $high
 * @property float|null $low
 * @property float|null $close
 * @property float|null $volume
 * @property string|null $timestamp
 * @property string $time_open
 * @property string $time_close
 * @property string $interval
 * @property string $time_period
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property float|null $market_cap
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereClose($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereConvert($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereCryptocurrencyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereHigh($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereLow($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereMarketCap($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereOpen($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereTimeClose($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereTimeOpen($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereTimePeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvQuote whereVolume($value)
 * @mixin \Eloquent
 */
class OhlcvQuote extends Model
{
    //
}
