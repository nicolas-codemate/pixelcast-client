<?php

declare(strict_types=1);

namespace App\Client;

enum StaleBehavior: string
{
    case Hide = 'hide';
    case Dim = 'dim';
    case Badge = 'badge';
    case None = 'none';
}
