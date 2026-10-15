<?php

namespace App\Domains\LeadSources\Drivers\CnpjBr;

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Contracts\QueryMapper;
use App\Models\Cnae;
use App\Models\CnaeMapping;
use App\Models\CnpjMunicipality;
use Illuminate\Support\Str;

/**
 * keyword → CNAE through the cnae_mappings table, falling back to the CNAE description.
 * regions → municipalities (by name) or UF (2-letter state code).
 */
class CnpjBrMapper implements QueryMapper
{
    public const array UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function map(IcpCriteria $criteria): array
    {
        $location = $this->resolveRegions($criteria->regions);
        $queries = [];

        $keywords = array_values(array_unique(array_map(
            fn (string $k) => mb_strtolower(trim($k)),
            array_filter([...$criteria->keywords, $criteria->industry]),
        )));

        foreach ($keywords as $keyword) {
            $codes = $this->resolveCnaes($keyword);

            if ($codes === []) {
                continue;
            }

            $queries[] = [
                'keyword' => $keyword,
                'cnae_codes' => $codes,
                'municipio_codes' => $location['municipio_codes'],
                'ufs' => $location['ufs'],
                'unresolved_regions' => $location['unresolved'],
            ];
        }

        return $queries;
    }

    public function unsupported(IcpCriteria $criteria): array
    {
        $unsupported = [];

        if ($criteria->jobTitles !== []) {
            $unsupported[] = 'job_titles';
        }
        if ($criteria->companySize !== null) {
            $unsupported[] = 'company_size';
        }
        if ($criteria->hasSignals()) {
            $unsupported[] = 'signals';
        }
        if (count($criteria->countries) > 1) {
            $unsupported[] = 'countries';
        }
        if ($criteria->areas !== []) {
            $unsupported[] = 'areas';
        }

        return $unsupported;
    }

    /** @return list<string> */
    public function resolveCnaes(string $keyword): array
    {
        $needle = mb_strtolower(trim($keyword));

        $codes = CnaeMapping::query()->where('keyword', $needle)->pluck('cnae_code')->all();

        if ($codes === []) {
            $codes = Cnae::query()
                ->whereRaw('lower(description) like ?', ['%'.$needle.'%'])
                ->limit(20)
                ->pluck('code')
                ->all();
        }

        if ($codes === []) {
            // Accent-insensitive: "estetica" ≈ "estética"
            $ascii = Str::ascii($needle);
            $codes = Cnae::query()
                ->whereRaw('lower(unaccent(description)) like ?', ['%'.$ascii.'%'])
                ->limit(20)
                ->pluck('code')
                ->all();
        }

        return array_values(array_unique(array_map('strval', $codes)));
    }

    /**
     * @param  list<string>  $regions
     * @return array{municipio_codes: list<string>, ufs: list<string>, unresolved: list<string>}
     */
    private function resolveRegions(array $regions): array
    {
        $municipios = [];
        $ufs = [];
        $unresolved = [];

        foreach ($regions as $region) {
            $region = trim($region);

            if (preg_match('/^[a-z]{2}$/i', $region) && in_array(strtoupper($region), self::UFS, true)) {
                $ufs[] = strtoupper($region);

                continue;
            }

            // "São Paulo, SP" → name + uf
            $name = trim((string) preg_replace('/,.*$/', '', $region));

            $codes = CnpjMunicipality::query()
                ->whereRaw('lower(unaccent(name)) = ?', [mb_strtolower(Str::ascii($name))])
                ->pluck('code')
                ->all();

            if ($codes !== []) {
                $municipios = array_merge($municipios, $codes);
            } else {
                $unresolved[] = $region;
            }
        }

        return [
            'municipio_codes' => array_values(array_unique($municipios)),
            'ufs' => array_values(array_unique($ufs)),
            'unresolved' => $unresolved,
        ];
    }
}
