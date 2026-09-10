<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

class LeadEnriched
{
    use Dispatchable;

    /** @param  list<string>  $steps */
    public function __construct(public Lead $lead, public array $steps) {}
}
