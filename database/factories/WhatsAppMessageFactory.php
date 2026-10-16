<?php

namespace Database\Factories;

use App\Enums\WhatsAppMessageType;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WhatsAppMessage> */
class WhatsAppMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'whatsapp_conversation_id' => WhatsAppConversation::factory(),
            'external_id' => Str::upper(Str::random(20)),
            'from_me' => false,
            'type' => WhatsAppMessageType::Text,
            'body' => fake()->sentence(),
            'sent_at' => now(),
        ];
    }
}
