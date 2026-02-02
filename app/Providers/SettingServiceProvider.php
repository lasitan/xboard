<?php

namespace App\Providers;

use App\Support\Setting;
use App\Services\TicketService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SettingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->scoped(Setting::class, function (Application $app) {
            return new Setting();
        });

    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            app(Setting::class)->toArray();
        } catch (\Throwable) {
            // no-op
        }

        try {
            if (admin_setting('email_host')) {
                $lock = Cache::store('redis')->lock('boot:ticket_email_backfill', 60);
                if ($lock->get()) {
                    try {
                        (new TicketService())->backfillEmailNotifications(200);
                    } finally {
                        $lock->release();
                    }
                }
            }
        } catch (\Throwable) {
            // no-op
        }
    }
}
