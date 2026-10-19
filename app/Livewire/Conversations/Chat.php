<?php

namespace App\Livewire\Conversations;

use App\Actions\WhatsApp\FirstContactSlots;
use App\Actions\WhatsApp\ReplyToConversation;
use App\Domains\WhatsApp\WhatsAppInbox;
use App\Jobs\MarkWhatsAppReadJob;
use App\Models\WhatsAppConversation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use RuntimeException;

/**
 * One WhatsApp chat, used by the inbox and embedded in the lead page. Opening it clears the
 * unread count and sends the read receipts; new messages arrive through Reverb events.
 */
class Chat extends Component
{
    public const PAGE = 50;

    #[Locked]
    public int $conversationId;

    public string $text = '';

    public int $limit = self::PAGE;

    /** @var list<string> texts sent from here that Evolution has not echoed back yet */
    public array $pending = [];

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->conversation();
        $this->markRead();
    }

    public function send(ReplyToConversation $reply): void
    {
        $conversation = $this->conversation();

        $this->validate(['text' => ['required', 'string', 'max:2000']]);

        try {
            $reply($conversation, trim($this->text));
        } catch (RuntimeException $e) {
            $this->addError('text', $e->getMessage());

            return;
        }

        $this->pending[] = trim($this->text);
        $this->reset('text');
        $this->dispatch('chat-scroll');
    }

    public function loadOlder(): void
    {
        $this->limit += self::PAGE;
    }

    /** @param  array<string, mixed>  $payload  WhatsAppMessageStored::broadcastWith() */
    #[On('whatsapp-message')]
    public function onMessage(array $payload): void
    {
        if (($payload['conversation_id'] ?? null) !== $this->conversationId) {
            return;
        }

        if (($payload['from_me'] ?? false) === true) {
            array_shift($this->pending);
        } elseif (($payload['seen'] ?? true) === true) {
            // Read receipts go out only while the user is looking; markSeen() catches up on return.
            $this->markRead();
        }

        $this->dispatch('chat-scroll');
    }

    /** The user came back to the tab/window with this chat open. Incoming messages show no ticks, so no redraw. */
    #[Renderless]
    public function markSeen(): void
    {
        $this->markRead();
    }

    /** Redraws the ticks. */
    #[On('whatsapp-updated')]
    public function onUpdated(): void {}

    protected function markRead(): void
    {
        $conversation = $this->conversation();

        if ($conversation->unread_count === 0) {
            return;
        }

        $receipts = app(WhatsAppInbox::class)->markRead($conversation);

        if ($receipts !== []) {
            MarkWhatsAppReadJob::dispatch($conversation->id, $receipts);
        }

        $this->dispatch('whatsapp-read', total: (int) WhatsAppConversation::query()->where('user_id', $conversation->user_id)->sum('unread_count'));
    }

    protected function conversation(): WhatsAppConversation
    {
        return WhatsAppConversation::query()
            ->where('user_id', Auth::id())
            ->findOrFail($this->conversationId);
    }

    public function render(WhatsAppInbox $inbox, FirstContactSlots $slots): View
    {
        $conversation = $this->conversation();
        $conversation->load('leads.project');

        $messages = $conversation->messages()->latest('sent_at')->latest('id')->limit($this->limit + 1)->get();

        return view('livewire.conversations.chat', [
            'conversation' => $conversation,
            'messages' => $messages->take($this->limit)->reverse()->values(),
            'hasOlder' => $messages->count() > $this->limit,
            'hasReplied' => $inbox->hasReplied($conversation),
            'perHour' => $slots->schedule()->perHour(),
            'perDay' => $slots->dailyCap($conversation->user),
        ]);
    }
}
