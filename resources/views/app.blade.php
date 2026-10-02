<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <title inertia>{{ config('app.name', 'MixTape') }}</title>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <meta name="color-scheme" content="light dark" />

        <link rel="icon" type="image/png" href="/favicons/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicons/favicon.svg" />
        <link rel="shortcut icon" href="/favicons/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png" />
        <meta name="apple-mobile-web-app-title" content="Mixtape" />
        <link rel="manifest" href="/favicons/site.webmanifest" />

        {{-- What a link to this page looks like pasted into a chat window. `$card` is supplied
             by the view composer in AppServiceProvider, so every render has one and no
             controller has to remember. --}}
        @include('partials.social-card')
        {{-- Apply the persisted theme before first paint so light-dark() resolves
             correctly with no flash. Runs synchronously in <head>, ahead of the
             deferred module bundle. Mirrors ThemeSwitch.vue's "theme" localStorage key. --}}
        <script>
            (function () {
                try {
                    var theme = localStorage.getItem("theme");
                    if (theme) {
                        document.querySelector('meta[name="color-scheme"]').setAttribute("content", theme);
                    }
                } catch (e) {
                    /* localStorage unavailable (private mode etc.) — fall back to the OS default */
                }
            })();
        </script>

        @vite(['resources/app/main.ts'])
        @inertiaHead
    </head>
    <body>
        {{-- inline the generated svg sprite (npm run icons) so <use href="#name"> resolves.
             hidden container: the <symbol> defs stay referenceable but never render. --}}
        @if (Storage::disk('public')->exists('sprite.svg'))
            <div hidden aria-hidden="true">{!! Storage::disk('public')->get('sprite.svg') !!}</div>
        @endif
        @inertia
    </body>
</html>
