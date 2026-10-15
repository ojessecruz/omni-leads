<?php

namespace App\Domains\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Merges duplicates within a project: leads sharing a cnpj, domain, phone or e-mail
 * become one. Covers what the upsert missed (e.g. an e-mail found during enrichment
 * that matches another lead).
 */
class LeadMerger
{
    private const array STATUS_PRIORITY = [
        LeadStatus::Replied->value => 5,
        LeadStatus::Contacted->value => 4,
        LeadStatus::Discarded->value => 3,
        LeadStatus::Qualified->value => 2,
        LeadStatus::New->value => 1,
    ];

    /** @return int quantidade de leads absorvidos */
    public function mergeProject(Project $project): int
    {
        $merged = 0;

        foreach (LeadUpserter::STRONG_KEYS as $column) {
            $values = Lead::query()
                ->where('project_id', $project->id)
                ->whereNotNull($column)
                ->select($column)
                ->groupBy($column)
                ->havingRaw('count(*) > 1')
                ->pluck($column);

            foreach ($values as $value) {
                $group = Lead::query()
                    ->where('project_id', $project->id)
                    ->where($column, $value)
                    ->orderBy('id')
                    ->get();

                if ($group->count() < 2) {
                    continue;
                }

                $primary = $group->shift();

                foreach ($group as $duplicate) {
                    $this->merge($primary, $duplicate);
                    $merged++;
                }
            }
        }

        return $merged;
    }

    public function merge(Lead $primary, Lead $duplicate): Lead
    {
        return DB::transaction(function () use ($primary, $duplicate) {
            $primary->refresh();

            $fill = [];

            foreach (['name', 'company_name', 'domain', 'email', 'phone', 'city', 'cnpj', 'whatsapp_valid'] as $field) {
                if ($primary->getAttribute($field) === null && $duplicate->getAttribute($field) !== null) {
                    $fill[$field] = $duplicate->getAttribute($field);
                }
            }

            $primary->forceFill($fill);

            if ($primary->country === 'XX' && $duplicate->country !== 'XX') {
                $primary->country = $duplicate->country;
            }

            if (self::STATUS_PRIORITY[$duplicate->status->value] > self::STATUS_PRIORITY[$primary->status->value]) {
                $primary->status = $duplicate->status;
            }

            $primary->score = max($primary->score, $duplicate->score);
            $primary->enriched_at = max($primary->enriched_at, $duplicate->enriched_at);
            $primary->save();

            // Sources: move the non-conflicting ones; identical ones (same driver+external_id) stay with the primary.
            $existing = $primary->sources()->get()->map(fn (LeadSource $s) => $s->driver.'|'.$s->external_id)->flip();

            foreach ($duplicate->sources()->get() as $source) {
                if ($existing->has($source->driver.'|'.$source->external_id)) {
                    continue;
                }

                $source->update(['lead_id' => $primary->id]);
            }

            $primary->icps()->syncWithoutDetaching($duplicate->icps()->pluck('icps.id')->all());

            $duplicate->delete();

            return $primary;
        });
    }
}
