<?php

namespace App\Domains\Leads;

use App\Domains\LeadSources\Data\LeadDTO;
use App\Models\Icp;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lead upsert with per-project dedup.
 *
 * 1. Look up by hash (cnpj > domain > phone > email).
 * 2. If not found, look for any strong key in common (smart merge across sources/ICPs).
 * 3. On collision, merge: fill null fields, add the lead_source and attach the current ICP.
 */
class LeadUpserter
{
    public const array STRONG_KEYS = ['cnpj', 'domain', 'phone', 'email'];

    public function upsert(Icp $icp, LeadDTO $dto): Lead
    {
        $normalized = $this->normalize($dto);
        $hash = self::hash($normalized);

        return DB::transaction(function () use ($icp, $dto, $normalized, $hash) {
            $lead = $this->find($icp->project_id, $hash, $normalized);

            if ($lead === null) {
                $lead = Lead::create([
                    'project_id' => $icp->project_id,
                    'icp_id' => $icp->id,
                    'dedup_hash' => $hash,
                    ...$this->fields($normalized),
                ]);
            } else {
                $lead->fill(array_filter($this->fields($normalized), fn ($v, $k) => $v !== null && $lead->{$k} === null, ARRAY_FILTER_USE_BOTH));
                $lead->save();
            }

            $lead->icps()->syncWithoutDetaching([$icp->id]);

            $lead->sources()->updateOrCreate(
                ['driver' => $dto->driver, 'external_id' => $dto->externalId],
                ['raw_payload' => $dto->rawPayload],
            );

            return $lead;
        });
    }

    /** @param  array<string, mixed>  $normalized */
    private function find(int $projectId, string $hash, array $normalized): ?Lead
    {
        $base = fn () => Lead::query()->where('project_id', $projectId)->lockForUpdate();

        $lead = $base()->where('dedup_hash', $hash)->first();

        if ($lead !== null) {
            return $lead;
        }

        $keys = array_filter(array_intersect_key($normalized, array_flip(self::STRONG_KEYS)));

        if ($keys === []) {
            return null;
        }

        return $base()
            ->where(function (Builder $q) use ($keys) {
                foreach ($keys as $column => $value) {
                    $q->orWhere($column, $value);
                }
            })
            ->orderBy('id')
            ->first();
    }

    /** @param  array<string, mixed>  $fields  campos normalizados do lead */
    public static function hash(array $fields): string
    {
        $key = $fields['cnpj'] ?? $fields['domain'] ?? $fields['phone'] ?? $fields['email'] ?? null;

        // Without any strong identifier, fall back to the external id to avoid collisions.
        if ($key === null) {
            $key = 'external:'.($fields['_external'] ?? '');
        }

        return sha1(strtolower($key));
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function fields(array $normalized): array
    {
        unset($normalized['_external']);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalize(LeadDTO $dto): array
    {
        $digits = fn (?string $v) => $v !== null && ($d = preg_replace('/\D+/', '', $v)) !== '' ? $d : null;
        $lower = fn (?string $v) => filled($v) ? strtolower(trim($v)) : null;

        $domain = $lower($dto->domain);
        $domain = $domain ? preg_replace('/^www\./', '', $domain) : null;

        return [
            'name' => filled($dto->name) ? trim($dto->name) : null,
            'company_name' => filled($dto->companyName) ? trim($dto->companyName) : null,
            'domain' => $domain,
            'email' => $lower($dto->email),
            'phone' => $digits($dto->phone),
            'country' => strtoupper(substr($dto->country ?: 'XX', 0, 2)),
            'city' => filled($dto->city) ? trim($dto->city) : null,
            'cnpj' => $digits($dto->cnpj),
            '_external' => $dto->driver.':'.$dto->externalId,
        ];
    }
}
