<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationFailures;
use App\Services\Library\Contracts\TagReader;
use App\Services\Library\Id3TagReader;
use App\Services\Meta\SocialCards;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as RenderedView;

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

        // The Open Graph card, on every render of the root view — a COMPOSER rather than a
        // prop each controller passes, because the tags are read by crawlers that never run
        // the Vue app, and because a per-controller card is one every new public page has to
        // remember. SocialCards decides from the route; see its docblock for why there are
        // only three answers.
        View::composer('app', function (RenderedView $view): void {
            $view->with('card', app(SocialCards::class)->for(request()));
        });
    }
}
