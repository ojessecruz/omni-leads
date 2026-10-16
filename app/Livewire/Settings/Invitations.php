<?php

namespace App\Livewire\Settings;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Invitations')]
class Invitations extends Component
{
    public string $email = '';

    public function mount(): void
    {
        Gate::authorize('invite-users');
    }

    public function invite(): void
    {
        Gate::authorize('invite-users');

        $this->email = strtolower(trim($this->email));

        $this->validate(
            ['email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')]],
            ['email.unique' => __('This email already has an account.')],
        );

        if ($this->send($this->email)) {
            Flux::toast(variant: 'success', text: __('Invitation sent to :email.', ['email' => $this->email]));
            $this->reset('email');
        }
    }

    public function resend(int $id): void
    {
        Gate::authorize('invite-users');

        $invitation = Invitation::query()->whereNull('accepted_at')->findOrFail($id);

        if ($this->send($invitation->email)) {
            Flux::toast(variant: 'success', text: __('Invitation sent to :email.', ['email' => $invitation->email]));
        }
    }

    public function revoke(int $id): void
    {
        Gate::authorize('invite-users');

        Invitation::query()->whereNull('accepted_at')->findOrFail($id)->delete();

        Flux::toast(text: __('Invitation revoked. The link no longer works.'));
    }

    /** Issues a fresh token (older links stop working) and emails it right away, so failures show up here. */
    protected function send(string $email): bool
    {
        [$invitation, $token] = Invitation::issue($email, Auth::user());

        try {
            Mail::to($invitation->email)->send(new InvitationMail($invitation->load('inviter'), $token));
        } catch (Throwable $e) {
            Log::warning('Invitation email failed', ['invitation_id' => $invitation->id, 'error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: __('Could not send the email: :reason', ['reason' => $e->getMessage()]));

            return false;
        }

        return true;
    }

    public function render(): View
    {
        return view('livewire.settings.invitations', [
            'invitations' => Invitation::query()->with('inviter')->latest('updated_at')->get(),
        ]);
    }
}
