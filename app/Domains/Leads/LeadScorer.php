<?php

namespace App\Domains\Leads;

use App\Domains\Icp\Data\IcpCriteria;
use App\Enums\LeadStatus;
use App\Events\LeadQualified;
use App\Models\Lead;

/**
 * 0-100 score from the ICP's `filterable` criteria.
 * Only applicable criteria count (normalized over the sum of applicable weights).
 */
class LeadScorer
{
    /**
     * @return array{score: int, breakdown: list<array{key: string, label: string, weight: int, met: bool, detail: string}>}
     */
    public function evaluate(Lead $lead, ?IcpCriteria $criteria = null): array
    {
        $criteria ??= $lead->icp->criteria_object;
        $weights = config('lead-scoring.weights', []);
        $places = $this->placesData($lead);

        $checks = [];

        if ($criteria->countries !== []) {
            $checks[] = $this->check('country', __('Country within ICP'), $weights, $criteria->includesCountry($lead->country), $lead->country);
        }

        if ($criteria->hasWebsite === true) {
            $checks[] = $this->check('has_website', __('Has its own website'), $weights, $lead->domain !== null, $lead->domain ?? __('no website'));
        }

        if ($criteria->minReviews !== null) {
            $reviews = $places['reviews'];
            $checks[] = $this->check('min_reviews', __('≥ :n reviews', ['n' => $criteria->minReviews]), $weights, $reviews !== null && $reviews >= $criteria->minReviews, $reviews === null ? __('no data') : __(':n reviews', ['n' => $reviews]));
        }

        if ($criteria->minRating !== null) {
            $rating = $places['rating'];
            $checks[] = $this->check('min_rating', __('Rating ≥ :n', ['n' => $criteria->minRating]), $weights, $rating !== null && $rating >= $criteria->minRating, $rating === null ? __('no data') : __('rating :n', ['n' => $rating]));
        }

        $checks[] = $this->check('email', __('Email found'), $weights, $lead->email !== null, $lead->email ?? __('not found'));
        $checks[] = $this->check('whatsapp', __('Valid WhatsApp'), $weights, $lead->whatsapp_valid === true, match ($lead->whatsapp_valid) {
            true => __('validated'),
            false => __('number without WhatsApp'),
            null => __('not verified'),
        });

        $applicable = array_sum(array_column($checks, 'weight'));
        $earned = array_sum(array_map(fn ($c) => $c['met'] ? $c['weight'] : 0, $checks));

        $score = $applicable > 0 ? (int) round($earned / $applicable * 100) : 0;

        return ['score' => max(0, min(100, $score)), 'breakdown' => $checks];
    }

    /** Applies the score and moves status between new ↔ qualified (never touches discarded/contacted). */
    public function score(Lead $lead): Lead
    {
        $result = $this->evaluate($lead);
        $threshold = (int) config('lead-scoring.qualified_threshold', 60);

        $status = $lead->status;

        if ($result['score'] >= $threshold && $status === LeadStatus::New) {
            $status = LeadStatus::Qualified;
        } elseif ($result['score'] < $threshold && $status === LeadStatus::Qualified) {
            $status = LeadStatus::New;
        }

        $wasQualified = $lead->status === LeadStatus::Qualified;

        $lead->forceFill(['score' => $result['score'], 'status' => $status, 'scored_at' => now()])->save();

        if ($status === LeadStatus::Qualified && ! $wasQualified) {
            LeadQualified::dispatch($lead);
        }

        return $lead;
    }

    /** @return array{rating: float|null, reviews: int|null} */
    private function placesData(Lead $lead): array
    {
        $source = $lead->sources()->where('driver', 'google_places')->latest('updated_at')->first();
        $place = $source?->raw_payload['place'] ?? [];

        return [
            'rating' => isset($place['rating']) && is_numeric($place['rating']) ? (float) $place['rating'] : null,
            'reviews' => isset($place['userRatingCount']) && is_numeric($place['userRatingCount']) ? (int) $place['userRatingCount'] : null,
        ];
    }

    /**
     * @param  array<string, int>  $weights
     * @return array{key: string, label: string, weight: int, met: bool, detail: string}
     */
    private function check(string $key, string $label, array $weights, bool $met, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'weight' => (int) ($weights[$key] ?? 0), 'met' => $met, 'detail' => $detail];
    }
}
