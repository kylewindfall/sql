<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[var(--color-linear-950)] text-[var(--color-linear-200)]">
        {{ $slot }}

        @fluxScripts
    </body>
</html>
