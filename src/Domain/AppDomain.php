<?php

declare(strict_types=1);

namespace App\Domain;

enum AppDomain: string
{
    case Weather = 'weather';
    case Trackers = 'trackers';
    case Notifications = 'notifications';
    case CustomApps = 'customApps';
    case Indicators = 'indicators';
    case Icons = 'icons';
}
