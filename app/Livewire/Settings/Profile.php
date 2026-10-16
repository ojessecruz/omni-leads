<?php

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

#[Title('Profile settings')]
class Profile extends Component
{
    use ProfileValidationRules, WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public string $name = '';

    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /** Saves as soon as a file is picked: the new photo goes to the default disk and the old one is deleted. */
    public function updatedPhoto(): void
    {
        $this->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000']]);

        $user = Auth::user();
        $old = $user->avatar_path;

        try {
            $path = $this->photo->store('avatars/'.$user->id);
        } catch (Throwable $e) {
            // Usually storage not configured (e.g. R2 keys missing): tell the user instead of a 500.
            report($e);
            $this->reset('photo');
            $this->addError('photo', __('Could not save the photo. Try again later.'));

            return;
        }

        $user->avatar_path = $path;
        $user->save();

        if ($old !== null) {
            Storage::delete($old);
        }

        $this->reset('photo');

        Flux::toast(variant: 'success', text: __('Profile photo updated.'));
    }

    public function removePhoto(): void
    {
        $user = Auth::user();

        if ($user->avatar_path === null) {
            return;
        }

        Storage::delete($user->avatar_path);
        $user->avatar_path = null;
        $user->save();

        Flux::toast(text: __('Profile photo removed.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }
}
