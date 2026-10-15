<?php

namespace App\Livewire\Projects;

use App\Enums\IntegrationProvider;
use App\Models\IcpDraft;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Show extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);

        $this->project = $project;
    }

    public function render(): View
    {
        $icps = $this->project->icps()
            ->withCount(['leads', 'searchJobs'])
            ->latest()
            ->get();

        $draft = IcpDraft::query()
            ->where('project_id', $this->project->id)
            ->where('user_id', auth()->id())
            ->first();

        return view('livewire.projects.show', [
            'icps' => $icps,
            'draft' => $draft,
            'needsPlacesKey' => ! $this->project->user->hasIntegration(IntegrationProvider::GooglePlaces),
        ])
            ->title($this->project->name);
    }
}
