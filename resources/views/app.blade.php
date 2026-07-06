<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <title inertia>{{ config('app.name', 'MixTape') }}</title>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <meta name="color-scheme" content="light dark" />

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
