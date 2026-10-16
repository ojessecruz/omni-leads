<?php

namespace App\Livewire\Dashboard;

use App\Domains\Onboarding\OnboardingChecklist;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Onboarding extends Component
{
    public function dismiss(): void
    {
        Auth::user()->forceFill(['onboarding_dismissed_at' => now()])->save();
    }

    public function render(OnboardingChecklist $checklist): View
    {
        $user = Auth::user();
        $steps = $checklist->steps($user);

        return view('livewire.dashboard.onboarding', [
            'steps' => $steps,
            'visible' => $checklist->visible($user, $steps),
            'next' => collect($steps)->first(fn ($s) => ! $s->done),
            'completed' => count(array_filter($steps, fn ($s) => $s->done)),
        ]);
    }
}
