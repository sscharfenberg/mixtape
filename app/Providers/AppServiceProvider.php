<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationFailures;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Feeds the dedicated `auth` log channel that fail2ban watches. Declared
        // here rather than discovered, so the wiring is greppable.
        Event::subscribe(LogAuthenticationFailures::class);
    }
}
