<?php

namespace App\Domains\LeadSources\Contracts;

use App\Domains\Icp\Data\IcpCriteria;

interface QueryMapper
{
    /**
     * Turns generic criteria into source-specific queries.
     *
     * @return list<array<string, mixed>>
     */
    public function map(IcpCriteria $criteria): array;

    /**
     * ICP criteria this source does NOT use in the search (they become post-filters).
     *
     * @return list<string>
     */
    public function unsupported(IcpCriteria $criteria): array;
}
