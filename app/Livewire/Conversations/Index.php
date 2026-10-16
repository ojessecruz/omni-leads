<?php

namespace App\Livewire\Conversations;

use App\Enums\IntegrationProvider;
use App\Models\WhatsAppConversation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Conversations')]
class Index extends Component
{
    /** Open conversation. */
    #[Url(as: 'c')]
    public ?int $conversation = null;

    #[Url(as: 'q')]
    public string $search = '';

    public function open(int $id): void
    {
        $this->conversation = WhatsAppConversation::query()->where('user_id', Auth::id())->findOrFail($id)->id;
    }

    public function close(): void
    {
        $this->conversation = null;
    }

    /** Re-sorts the list and refreshes previews and unread counts. */
    #[On('whatsapp-message')]
    public function refreshList(): void {}

    public function render(): View
    {
        $user = Auth::user();
        $term = trim($this->search);
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        $conversations = WhatsAppConversation::query()
            ->where('user_id', $user->id)
            ->with('leads.project')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('name ilike ?', ['%'.$term.'%'])
                ->when($digits !== '', fn ($q) => $q->orWhere('phone', 'like', '%'.$digits.'%'))
                ->orWhereHas('leads', fn ($q) => $q->whereRaw('coalesce(company_name, name) ilike ?', ['%'.$term.'%']))))
            ->orderByRaw('last_message_at desc nulls last')
            ->limit(200)
            ->get();

        $open = $this->conversation !== null
            ? WhatsAppConversation::query()->where('user_id', $user->id)->find($this->conversation)
            : null;

        return view('livewire.conversations.index', [
            'conversations' => $conversations,
            'open' => $open,
            'connected' => $user->hasIntegration(IntegrationProvider::Evolution),
        ]);
    }
}
