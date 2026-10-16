<?php

namespace App\Actions\WhatsApp;

use App\Domains\WhatsApp\FirstContactLimit;
use App\Jobs\SendWhatsAppJob;
use App\Models\Lead;
use RuntimeException;

/**
 * Queues the first WhatsApp message to a lead, from the lead page or the leads list.
 * A first contact counts against the hourly cap that protects the number from a ban.
 */
class QueueFirstContact
{
    public function __construct(private FirstContactLimit $limit) {}

    /** @throws RuntimeException with a message for the user when it cannot be sent */
    public function __invoke(Lead $lead, string $text): void
    {
        if (! $lead->phone) {
            throw new RuntimeException(__('Lead has no phone.'));
        }

        $user = $lead->project->user;

        if (! $this->limit->attempt($user)) {
            throw new RuntimeException(__('This number never replied. To protect your WhatsApp from a ban, messages like this are limited to :count per hour. Try again in :minutes min.', [
                'count' => FirstContactLimit::PER_HOUR,
                'minutes' => $this->limit->availableInMinutes($user),
            ]));
        }

        SendWhatsAppJob::dispatch($lead->id, $text);
    }
}
