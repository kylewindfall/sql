<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[var(--color-linear-950)]">
        <flux:sidebar sticky stashable class="border-r border-[var(--color-linear-775)] bg-[var(--color-linear-950)]">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('home') }}" class="mr-5 flex items-center space-x-2" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group heading="Platform" class="grid">
                    <flux:navlist.item icon="circle-stack" :href="route('home')" :current="request()->routeIs('home') || request()->routeIs('dashboard')" wire:navigate>Databases</flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            <flux:navlist variant="outline">
                <flux:navlist.item icon="bolt" href="#" aria-disabled="true">Local</flux:navlist.item>
            </flux:navlist>

            <div class="rounded-[10px] border border-[var(--color-linear-750)] bg-[var(--color-linear-900)] px-4 py-3 text-sm text-[var(--color-linear-300)] shadow-[0_2px_4px_rgba(0,0,0,0.1)]">
                Local-first mode
                <div class="mt-1 text-xs text-[var(--color-linear-400)]">
                    Spreadsheet editing for your Herd MySQL databases.
                </div>
            </div>
        </flux:sidebar>

        <flux:header class="border-b border-[var(--color-linear-775)] bg-[var(--color-linear-950)] lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />
            <div class="text-xs font-medium tracking-[0.18em] text-[var(--color-linear-400)] uppercase">Local mode</div>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
