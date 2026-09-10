<?php

namespace App\Livewire\Settings;

use App\Support\Locales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Appearance settings')]
class Appearance extends Component
{
    public string $locale = '';

    public function mount(): void
    {
        $this->locale = Auth::user()->locale ?? app()->getLocale();
    }

    public function updatedLocale(string $value): void
    {
        $this->validate(['locale' => ['required', Rule::in(array_keys(Locales::SUPPORTED))]]);

        Auth::user()->update(['locale' => $value]);

        $this->redirectRoute('appearance.edit', navigate: false);
    }
}
