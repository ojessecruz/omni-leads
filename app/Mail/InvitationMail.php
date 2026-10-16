<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':name invited you to Omni Leads', ['name' => $this->inviterName()]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation', with: [
            'inviter' => $this->inviterName(),
            'url' => route('invitations.accept', $this->token),
            'days' => Invitation::VALID_DAYS,
        ]);
    }

    protected function inviterName(): string
    {
        return $this->invitation->inviter->name ?? 'Omni Leads';
    }
}
