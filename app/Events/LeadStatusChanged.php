<?php

namespace App\Events;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

class LeadStatusChanged
{
    use Dispatchable;

    public function __construct(public Lead $lead, public LeadStatus $from) {}
}
