<?php

declare(strict_types=1);

namespace App\Tui\SyncNow;

enum SyncTarget: string
{
    case Weather = 'sync-weather';
    case Tracker = 'sync-tracker';

    public function label(): string
    {
        return match ($this) {
            self::Weather => 'SyncWeatherMessage',
            self::Tracker => 'SyncTrackerMessage',
        };
    }

    public function messageClass(): string
    {
        return match ($this) {
            self::Weather => 'App\\Message\\SyncWeatherMessage',
            self::Tracker => 'App\\Message\\SyncTrackerMessage',
        };
    }
}
