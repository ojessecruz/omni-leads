<?php

namespace App\Events;

use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Events\Dispatchable;

/** A new message landed in a WhatsApp conversation (incoming, or sent by the app or the phone). */
class WhatsAppMessageStored
{
    use Dispatchable;

    public function __construct(public WhatsAppMessage $message) {}
}
