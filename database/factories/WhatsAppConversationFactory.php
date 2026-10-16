<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WhatsAppConversation> */
class WhatsAppConversationFactory extends Factory
{
    public function definition(): array
    {
        $phone = '55119'.fake()->unique()->numerify('########');

        return [
            'user_id' => User::factory(),
            'remote_jid' => $phone.'@s.whatsapp.net',
            'phone' => $phone,
            'name' => fake()->name(),
            'unread_count' => 0,
        ];
    }
}
