import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Connects to Reverb with the config the app layout renders in window.omniEcho, read at
 * runtime so the WebSocket domain can change without rebuilding the assets.
 */
export default function connectEcho(config) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    document.dispatchEvent(new CustomEvent('echo:ready'));

    return window.Echo;
}
