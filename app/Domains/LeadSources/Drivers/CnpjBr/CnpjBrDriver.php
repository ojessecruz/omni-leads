<?php

namespace App\Domains\LeadSources\Drivers\CnpjBr;

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Contracts\LeadSourceDriver;
use App\Domains\LeadSources\Contracts\QueryMapper;
use App\Domains\LeadSources\Data\LeadDTO;
use App\Models\CnpjEstablishment;
use Generator;

/**
 * Local Receita Federal database (imported via `cnpj:import`). BR only, no API cost.
 */
class CnpjBrDriver implements LeadSourceDriver
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private array $config) {}

    public function name(): string
    {
        return 'cnpj_br';
    }

    public function supports(IcpCriteria $criteria): bool
    {
        return $criteria->includesCountry('BR')
            && ($criteria->keywords !== [] || $criteria->industry !== null)
            && CnpjEstablishment::query()->exists();
    }

    public function mapper(): QueryMapper
    {
        return new CnpjBrMapper;
    }

    public function search(array $queries): Generator
    {
        $limit = max(1, (int) ($this->config['max_results_per_query'] ?? 500));

        foreach ($queries as $query) {
            $codes = $query['cnae_codes'] ?? [];

            if ($codes === []) {
                continue;
            }

            $builder = CnpjEstablishment::query()
                ->with('municipality')
                ->where('situacao', CnpjEstablishment::ACTIVE)
                ->whereIn('cnae_principal', $codes)
                ->when($query['municipio_codes'] ?? [], fn ($q, $m) => $q->whereIn('municipio_code', $m))
                ->when(($query['municipio_codes'] ?? []) === [] && ($query['ufs'] ?? []) !== [], fn ($q) => $q->whereIn('uf', $query['ufs']))
                ->orderByDesc('is_headquarters')
                ->orderBy('cnpj')
                ->limit($limit);

            // get(), not cursor(): cursor() skips eager loading and would query the municipality per row.
            foreach ($builder->get() as $establishment) {
                yield $this->toLead($establishment, $query);
            }
        }
    }

    /** @param  array<string, mixed>  $query */
    private function toLead(CnpjEstablishment $e, array $query): LeadDTO
    {
        $name = $e->nome_fantasia ?: $e->razao_social;

        return new LeadDTO(
            driver: $this->name(),
            externalId: $e->cnpj,
            name: $name ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE) : null,
            companyName: $e->razao_social ? mb_convert_case(mb_strtolower($e->razao_social), MB_CASE_TITLE) : ($name ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE) : null),
            domain: null,
            email: $e->email && filter_var($e->email, FILTER_VALIDATE_EMAIL) ? $e->email : null,
            phone: $e->telefone1 ? '55'.$e->telefone1 : null,
            country: 'BR',
            city: $e->municipality?->name,
            cnpj: $e->cnpj,
            rawPayload: ['query' => ['keyword' => $query['keyword'] ?? null], 'establishment' => $e->toArray()],
        );
    }
}
