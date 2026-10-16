<?php

use App\Livewire\Auth\AcceptInvitation;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Integrations;
use App\Livewire\Settings\Invitations;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', Appearance::class)->name('appearance.edit');
    Route::livewire('settings/integrations', Integrations::class)->name('integrations.edit');
    Route::livewire('settings/invitations', Invitations::class)->middleware('can:invite-users')->name('invitations.index');

    Route::livewire('settings/security', Security::class)
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::livewire('invitations/{token}', AcceptInvitation::class)
    ->middleware(['guest', 'throttle:30,1'])
    ->name('invitations.accept');

if (Features::enabled(Features::passkeys())) {
    Route::get('.well-known/passkey-endpoints', function () {
        return response()->json([
            'enroll' => route('security.edit'),
            'manage' => route('security.edit'),
        ]);
    })->name('well-known.passkeys');
}
