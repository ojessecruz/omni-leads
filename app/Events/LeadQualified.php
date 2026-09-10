<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

class LeadQualified
{
    use Dispatchable;

    public function __construct(public Lead $lead) {}
}
