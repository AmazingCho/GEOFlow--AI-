import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const runtimeReverb = window.GEOFLOW_REVERB_CONFIG ?? {};
const reverbScheme = runtimeReverb.scheme ?? import.meta.env.VITE_REVERB_SCHEME ?? 'https';
const reverbPort = runtimeReverb.port ?? import.meta.env.VITE_REVERB_PORT ?? (reverbScheme === 'https' ? 443 : 80);
const reverbKey = runtimeReverb.key ?? import.meta.env.VITE_REVERB_APP_KEY;
const reverbHost = runtimeReverb.host ?? import.meta.env.VITE_REVERB_HOST;
const reverbPath = (runtimeReverb.path ?? import.meta.env.VITE_REVERB_PATH ?? '').trim().replace(/\/+$/, '');
const authEndpoint = (runtimeReverb.authEndpoint ?? '/broadcasting/auth').trim();
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

if (runtimeReverb.enabled === false || !reverbKey || !reverbHost) {
    window.Echo = null;
} else {
    const echoOptions = {
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: reverbHost,
        wsPort: reverbPort,
        wssPort: reverbPort,
        forceTLS: reverbScheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: authEndpoint.startsWith('/') ? authEndpoint : `/${authEndpoint}`,
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrfToken,
            },
        },
    };

    if (reverbPath !== '') {
        echoOptions.wsPath = reverbPath.startsWith('/') ? reverbPath : `/${reverbPath}`;
    }

    window.Echo = new Echo(echoOptions);
}
