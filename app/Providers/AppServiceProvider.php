<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationFailures;
use App\Services\Library\Contracts\TagReader;
use App\Services\Library\Id3TagReader;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The library scanner reads tags/stream/hash through this contract;
        // production uses getID3 (tests bind a fake, so they need no real audio).
        $this->app->bind(TagReader::class, Id3TagReader::class);
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
