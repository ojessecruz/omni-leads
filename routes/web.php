<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OutboundWebhookController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Livewire\Icps\Show as IcpShow;
use App\Livewire\Icps\Wizard as IcpWizard;
use App\Livewire\Leads\Index as LeadsIndex;
use App\Livewire\Leads\Show as LeadShow;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Settings as ProjectSettings;
use App\Livewire\Projects\Show as ProjectShow;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('outbound/{connection}/webhook/{secret}', OutboundWebhookController::class)
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->middleware('throttle:600,1')
    ->name('outbound.webhook');
Route::post('whatsapp/{integration}/webhook/{secret}', WhatsAppWebhookController::class)
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->middleware('throttle:1200,1')
    ->name('whatsapp.webhook');
Route::post('locale', LocaleController::class)->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::post('theme', ThemeController::class)->name('theme.update');

    Route::livewire('projects', ProjectsIndex::class)->name('projects.index');
    Route::livewire('projects/{project}', ProjectShow::class)->name('projects.show');
    Route::livewire('projects/{project}/settings', ProjectSettings::class)->name('projects.settings');
    Route::livewire('projects/{project}/icps/create', IcpWizard::class)->name('icps.create');
    Route::livewire('icps/{icp}', IcpShow::class)->name('icps.show');

    Route::livewire('leads', LeadsIndex::class)->name('leads.index');
    Route::livewire('leads/{lead}', LeadShow::class)->name('leads.show');
});

require __DIR__.'/settings.php';
