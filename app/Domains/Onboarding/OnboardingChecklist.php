<?php

namespace App\Domains\Onboarding;

use App\Enums\IntegrationProvider;
use App\Models\Lead;
use App\Models\SearchJob;
use App\Models\User;

/**
 * First steps of a new account. Each step is derived from the user's data,
 * so there is no progress to keep in sync: it is done once the thing exists.
 */
class OnboardingChecklist
{
    /** @return list<OnboardingStep> */
    public function steps(User $user): array
    {
        $project = $user->projects()->latest('id')->first();

        return [
            new OnboardingStep(
                key: 'places_key',
                title: __('Connect Google Places'),
                description: __('Searches use your own Google Places key. It takes about 5 minutes and Google has a free monthly quota.'),
                action: __('Add key'),
                url: route('integrations.edit').'#google-places-guide',
                done: $user->hasIntegration(IntegrationProvider::GooglePlaces),
            ),
            new OnboardingStep(
                key: 'project',
                title: __('Create a project'),
                description: __('A project is the product you sell. It holds the context used to score leads.'),
                action: __('New project'),
                url: route('projects.index'),
                done: $project !== null,
            ),
            new OnboardingStep(
                key: 'search',
                title: __('Define the ICP and run the search'),
                description: __('Tell who your ideal customer is and where they are. The wizard starts the search at the end.'),
                action: __('Define ICP'),
                url: $project ? route('icps.create', $project) : route('projects.index'),
                done: SearchJob::whereHas('icp.project', fn ($q) => $q->where('user_id', $user->id))->exists(),
            ),
            new OnboardingStep(
                key: 'leads',
                title: __('Review your first leads'),
                description: __('Each lead gets a score from 0 to 100. The qualified ones are ready to contact.'),
                action: __('See leads'),
                url: route('leads.index'),
                done: Lead::whereHas('project', fn ($q) => $q->where('user_id', $user->id))->exists(),
            ),
            new OnboardingStep(
                key: 'outbound',
                title: __('Connect an outbound channel'),
                description: __('Send qualified leads to a Saleshandy sequence or message them on WhatsApp.'),
                action: __('Connect'),
                url: route('integrations.edit'),
                done: $user->hasIntegration(IntegrationProvider::Saleshandy) || $user->hasIntegration(IntegrationProvider::Evolution),
                required: false,
            ),
        ];
    }

    /**
     * Shown until the user dismisses it or finishes every required step.
     *
     * @param  list<OnboardingStep>|null  $steps  already computed steps, to avoid querying twice
     */
    public function visible(User $user, ?array $steps = null): bool
    {
        if ($user->onboarding_dismissed_at !== null) {
            return false;
        }

        return ! array_all($steps ?? $this->steps($user), fn (OnboardingStep $s) => $s->done || ! $s->required);
    }
}
