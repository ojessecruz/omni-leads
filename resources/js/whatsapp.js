/**
 * Live WhatsApp inbox events on the user's private channel: Livewire components refresh
 * through "whatsapp-message" / "whatsapp-updated", the nav badge follows the unread total,
 * and incoming messages raise a toast, or a system notification when the user is not looking
 * at the page (tab hidden or window unfocused). The chat on screen only skips it while looked at.
 */
export default function listenToWhatsApp(echo, config) {
    const setBadge = (total) => {
        document.querySelectorAll('[data-whatsapp-unread]').forEach((badge) => {
            badge.textContent = total;
            badge.classList.toggle('hidden', !total);
        });
    };

    const looking = () => !document.hidden && document.hasFocus();

    const notify = (event, seen) => {
        if (event.from_me || (seen && window.omniActiveConversation === event.conversation_id)) {
            return;
        }

        if (!seen && 'Notification' in window && Notification.permission === 'granted') {
            const notification = new Notification(event.name, { body: event.preview ?? '', tag: `whatsapp-${event.conversation_id}` });
            notification.onclick = () => {
                window.focus();
                window.location.href = `${config.inboxUrl}?c=${event.conversation_id}`;
            };

            return;
        }

        window.Flux?.toast({ heading: `${config.newMessage} · ${event.name}`, text: event.preview ?? '' });
    };

    echo.private(`App.Models.User.${config.userId}`)
        .listen('.whatsapp.message', (event) => {
            const seen = looking();
            setBadge(event.unread_total);
            notify(event, seen);
            // The open chat marks it read only when seen; otherwise when the user comes back.
            window.Livewire?.dispatch('whatsapp-message', { payload: { ...event, seen } });
        })
        .listen('.whatsapp.updated', (event) => window.Livewire?.dispatch('whatsapp-updated', { payload: event }));

    window.addEventListener('whatsapp-read', (event) => setBadge(event.detail.total));
}
