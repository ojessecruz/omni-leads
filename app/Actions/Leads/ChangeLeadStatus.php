<?php

namespace App\Actions\Leads;

use App\Enums\LeadStatus;
use App\Events\LeadStatusChanged;
use App\Models\Lead;

/** Sets a lead's status by hand (lead page, leads list, API); a real change fires the webhooks. */
class ChangeLeadStatus
{
    /** @return bool false when the lead already had that status */
    public function __invoke(Lead $lead, LeadStatus $status): bool
    {
        $from = $lead->status;

        if ($from === $status) {
            return false;
        }

        $lead->update(['status' => $status]);
        LeadStatusChanged::dispatch($lead, $from);

        return true;
    }
}
