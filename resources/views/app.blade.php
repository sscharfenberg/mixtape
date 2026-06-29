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
        @inertia
    </body>
</html>
