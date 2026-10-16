import areaMap from './area-map';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('areaMap', areaMap);
});

// Real-time updates only on app pages whose layout rendered the Reverb config (not the landing).
if (window.omniEcho) {
    Promise.all([import('./echo'), import('./whatsapp')]).then(([{ default: connectEcho }, { default: listenToWhatsApp }]) => {
        listenToWhatsApp(connectEcho(window.omniEcho), window.omniEcho);
    });
}
