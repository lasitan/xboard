<?php

namespace Plugin\GoogleLogin;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // no-op
    }

    public function schedule(Schedule $schedule): void
    {
        // no-op
    }
}
