<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * App\OhlcvRequest
 *
 * @property int $request_id
 * @property int|null $id
 * @property string|null $symbol
 * @property string|null $time_start
 * @property string|null $time_end
 * @property int $count
 * @property string $interval
 * @property string $time_period
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $convert
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereConvert($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereSymbol($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereTimeEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereTimePeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereTimeStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\OhlcvRequest whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class OhlcvRequest extends Model
{
    //
}
