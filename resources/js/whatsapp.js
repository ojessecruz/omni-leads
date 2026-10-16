/**
 * Live WhatsApp inbox events on the user's private channel: Livewire components refresh
 * through "whatsapp-message" / "whatsapp-updated", the nav badge follows the unread total,
 * and incoming messages raise a toast (or a browser notification when the tab is hidden)
 * unless that chat is the one on screen.
 */
export default function listenToWhatsApp(echo, config) {
    const setBadge = (total) => {
        document.querySelectorAll('[data-whatsapp-unread]').forEach((badge) => {
            badge.textContent = total;
            badge.classList.toggle('hidden', !total);
        });
    };

    const notify = (event) => {
        if (event.from_me || window.omniActiveConversation === event.conversation_id) {
            return;
        }

        if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
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
            setBadge(event.unread_total);
            notify(event);
            window.Livewire?.dispatch('whatsapp-message', { payload: event });
        })
        .listen('.whatsapp.updated', (event) => window.Livewire?.dispatch('whatsapp-updated', { payload: event }));

    window.addEventListener('whatsapp-read', (event) => setBadge(event.detail.total));
}
