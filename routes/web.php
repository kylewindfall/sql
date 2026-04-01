<?php

use App\Http\Controllers\DatabaseExportController;
use App\Http\Controllers\TableCsvExportController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('/', 'dashboard')->name('home');
Volt::route('dashboard', 'dashboard')->name('dashboard');
Route::get('databases/{database}/export', DatabaseExportController::class)->name('databases.export');
Route::get('databases/{database}/tables/{table}/export-csv', TableCsvExportController::class)->name('tables.export-csv');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
