<?php

namespace App\Livewire\Settings;

use App\Enums\Theme;
use App\Support\Locales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Appearance settings')]
class Appearance extends Component
{
    public string $locale = '';

    public string $theme = '';

    public function mount(): void
    {
        $this->locale = Auth::user()->locale ?? app()->getLocale();
        $this->theme = Auth::user()->theme->value;
    }

    public function updatedLocale(string $value): void
    {
        $this->validate(['locale' => ['required', Rule::in(array_keys(Locales::SUPPORTED))]]);

        Auth::user()->update(['locale' => $value]);

        $this->redirectRoute('appearance.edit', navigate: false);
    }

    public function updatedTheme(string $value): void
    {
        $this->validate(['theme' => ['required', Rule::enum(Theme::class)]]);

        Auth::user()->update(['theme' => $value]);

        // Full reload: the theme is applied on <html>, outside what Livewire re-renders.
        $this->redirectRoute('appearance.edit', navigate: false);
    }
}
