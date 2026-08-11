<?php

declare(strict_types=1);

namespace App\Config\Sync;

/**
 * What the row under the price shows when bottomText does not fix it.
 */
enum BottomLine: string
{
    case AllTimeHigh = 'ath';
    case TradedVolume = 'volume';
}
