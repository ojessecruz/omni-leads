<?php

namespace App\Livewire\Auth;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::auth')]
#[Title('Create your account')]
class AcceptInvitation extends Component
{
    use PasswordValidationRules, ProfileValidationRules;

    #[Locked]
    public string $token = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        if ($locale = $this->invitation()?->locale) {
            app()->setLocale($locale);
        }
    }

    public function accept(): void
    {
        $invitation = $this->invitation();

        abort_if($invitation === null, 410);

        $this->validate(['name' => $this->nameRules(), 'password' => $this->passwordRules()]);

        if (User::query()->where('email', $invitation->email)->exists()) {
            $this->addError('name', __('This email already has an account. Log in instead.'));

            return;
        }

        $user = DB::transaction(function () use ($invitation): User {
            $user = User::create([
                'name' => $this->name,
                'email' => $invitation->email,
                'password' => $this->password,
                'locale' => $invitation->locale,
            ]);

            // The link arrived by email, so the address is already proven.
            $user->forceFill(['email_verified_at' => now()])->save();
            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }

    protected function invitation(): ?Invitation
    {
        return Invitation::findPending($this->token);
    }

    public function render(): View
    {
        return view('livewire.auth.accept-invitation', ['invitation' => $this->invitation()]);
    }
}
