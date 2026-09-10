<?php

namespace App\Livewire\Projects;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Projects')]
class Index extends Component
{
    public string $name = '';

    public function create(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:120']]);

        $project = auth()->user()->projects()->create(['name' => $this->name]);

        $this->redirectRoute('icps.create', $project, navigate: true);
    }

    public function render(): View
    {
        $projects = auth()->user()->projects()
            ->withCount(['icps', 'leads'])
            ->latest()
            ->get();

        return view('livewire.projects.index', ['projects' => $projects]);
    }
}
