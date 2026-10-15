<?php

namespace App\Domains\Outbound\Contracts;

use App\Domains\Outbound\Data\OutboundCampaign;
use App\Domains\Outbound\Data\OutboundEvent;
use App\Domains\Outbound\Data\OutboundProspect;
use App\Domains\Outbound\Exceptions\OutboundException;

/**
 * A cold-mail tool (Saleshandy, ...) that runs the sequences. Built per project by
 * OutboundConnectorResolver with that project's own API key.
 */
interface OutboundConnector
{
    /** Stable identifier stored in outbound_connections.connector, e.g. 'saleshandy'. */
    public function key(): string;

    /**
     * Merge tags the user puts in the tool's sequence step to use the subject and
     * message filled by Omni Leads.
     *
     * @return array{subject: string, message: string}
     */
    public function messageTags(): array;

    /** @throws OutboundException when the key is rejected or the API is unreachable */
    public function verify(): void;

    /**
     * Campaigns (sequences) leads can be sent to.
     *
     * @return list<OutboundCampaign>
     *
     * @throws OutboundException
     */
    public function campaigns(): array;

    /**
     * Adds the prospects to the campaign and returns the tool's request/import id.
     *
     * @param  list<OutboundProspect>  $prospects
     *
     * @throws OutboundException
     */
    public function push(string $campaignId, array $prospects): string;

    /**
     * Subscribes $url to the events parseWebhook() understands and returns the webhook id.
     *
     * @throws OutboundException
     */
    public function registerWebhook(string $url): string;

    /**
     * Turns a webhook body into an event, or null for events Omni Leads ignores.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): ?OutboundEvent;
}
