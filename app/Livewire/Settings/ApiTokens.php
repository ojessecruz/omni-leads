<?php

namespace App\Livewire\Settings;

use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Personal access tokens (Sanctum) for the conversations and leads API. */
#[Title('API')]
class ApiTokens extends Component
{
    /** Labels are translation keys. */
    public const array ABILITIES = [
        'conversations:read' => 'Read conversations and messages',
        'conversations:reply' => 'Reply, mark as read and pause the bot',
        'leads:read' => 'Read leads',
        'leads:write' => 'Change lead status and send first WhatsApp messages',
    ];

    public string $name = '';

    /** @var list<string> */
    public array $abilities = ['conversations:read', 'conversations:reply'];

    /** Shown once, right after creation. */
    public ?string $plainToken = null;

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['in:'.implode(',', array_keys(self::ABILITIES))],
        ]);

        $this->plainToken = Auth::user()->createToken(trim($this->name), $this->abilities)->plainTextToken;
        $this->reset('name');
    }

    public function revoke(int $id): void
    {
        Auth::user()->tokens()->whereKey($id)->delete();
        $this->plainToken = null;

        Flux::toast(text: __('Token revoked. Requests with it now fail.'));
    }

    public function render(): View
    {
        return view('livewire.settings.api-tokens', [
            'tokens' => Auth::user()->tokens()->latest()->get(),
            'labels' => array_map(fn (string $label) => __($label), self::ABILITIES),
        ]);
    }
}
