<?php

namespace App\Domains\LeadSources\Contracts;

interface TracksRequests
{
    /** Requests made to the external API since the driver was created (for cost estimates). */
    public function requestCount(): int;
}
