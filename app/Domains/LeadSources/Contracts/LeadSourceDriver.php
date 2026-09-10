<?php

namespace App\Domains\LeadSources\Contracts;

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Data\LeadDTO;
use Generator;

interface LeadSourceDriver
{
    /** Stable identifier, e.g. 'google_places'. */
    public function name(): string;

    /** Whether the source can search anything with these criteria (e.g. CNPJ is BR only). */
    public function supports(IcpCriteria $criteria): bool;

    public function mapper(): QueryMapper;

    /**
     * @param  list<array<string, mixed>>  $queries  output of mapper()->map()
     * @return Generator<int, LeadDTO>
     */
    public function search(array $queries): Generator;
}
