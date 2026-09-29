<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr as BaseArr;

/**
 * Small array helpers used by the Telegram update parser.
 */
final class Arr
{
    /**
     * @param  array<array-key, mixed>  $array
     */
    public static function last(array $array): mixed
    {
        return $array === [] ? null : BaseArr::last($array);
    }
}
