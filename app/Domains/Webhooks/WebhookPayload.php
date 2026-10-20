<?php

namespace App\Domains\Webhooks;

use App\Models\Lead;
use App\Models\SearchJob;
use App\Models\WhatsAppMessage;

class WebhookPayload
{
    /** @return array<string, mixed> */
    public static function lead(Lead $lead): array
    {
        $lead->loadMissing(['icp:id,name', 'project:id,name', 'sources:id,lead_id,driver']);

        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'company_name' => $lead->company_name,
            'domain' => $lead->domain,
            'website' => $lead->websiteUrl(),
            'email' => $lead->email,
            'phone' => $lead->phone,
            'whatsapp_valid' => $lead->whatsapp_valid,
            'country' => $lead->country,
            'city' => $lead->city,
            'cnpj' => $lead->cnpj,
            'score' => $lead->score,
            'status' => $lead->status->value,
            'sources' => $lead->sources->pluck('driver')->unique()->values()->all(),
            'icp' => ['id' => $lead->icp->id, 'name' => $lead->icp->name],
            'project' => ['id' => $lead->project->id, 'name' => $lead->project->name],
            'url' => route('leads.show', $lead),
            'created_at' => $lead->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function searchJob(SearchJob $job): array
    {
        $job->loadMissing('icp:id,name,project_id');

        return [
            'id' => $job->id,
            'driver' => $job->driver,
            'status' => $job->status->value,
            'results_count' => $job->results_count,
            'requests_count' => $job->requests_count,
            'error' => $job->error,
            'icp' => ['id' => $job->icp->id, 'name' => $job->icp->name],
            'url' => route('icps.show', $job->icp),
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
        ];
    }

    /**
     * An incoming WhatsApp message; $lead is the conversation's lead in the receiving project.
     *
     * @return array<string, mixed>
     */
    public static function whatsappMessage(WhatsAppMessage $message, ?Lead $lead): array
    {
        $conversation = $message->conversation;

        return [
            'id' => $message->id,
            'type' => $message->type->value,
            'body' => $message->body,
            'has_media' => $message->type->hasMedia(),
            'sent_at' => $message->sent_at->toIso8601String(),
            'contact' => ['phone' => $conversation->phone, 'name' => $conversation->name],
            'conversation' => [
                'id' => $conversation->id,
                'unread_count' => $conversation->unread_count,
                'bot_paused' => $conversation->bot_paused_at !== null,
                'url' => route('conversations.index', ['c' => $conversation->id]),
            ],
            'lead' => $lead ? self::lead($lead) : null,
        ];
    }
}
