/* Optional WebSocket delivery with bounded dedupe and polling fallback. */
(function () {
    const config = window.sevillaRealtimeConfig;
    if (!config?.enabled || !config.tokenUrl || !window.WebSocket) return;

    const seen = new Set();
    let attempts = 0;
    let socket = null;
    let retryTimer = null;

    function remember(eventId) {
        if (!eventId) return true;
        if (seen.has(eventId)) return false;
        seen.add(eventId);
        if (seen.size > 256) seen.delete(seen.values().next().value);
        return true;
    }

    function retry() {
        clearTimeout(retryTimer);
        const delay = Math.min(30000, 500 * (2 ** Math.min(attempts, 6))) + Math.floor(Math.random() * 250);
        retryTimer = setTimeout(connect, delay);
    }

    async function connect() {
        clearTimeout(retryTimer);
        retryTimer = null;
        if (document.visibilityState === 'hidden') return;
        if (socket && (socket.readyState === WebSocket.CONNECTING || socket.readyState === WebSocket.OPEN)) return;
        try {
            const response = await fetch(config.tokenUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok || !data.enabled || !data.token || !data.ws_url) return;
            const currentSocket = new WebSocket(data.ws_url);
            socket = currentSocket;
            currentSocket.addEventListener('open', () => {
                attempts = 0;
                currentSocket.send(JSON.stringify({ type: 'auth', token: data.token }));
            });
            currentSocket.addEventListener('message', event => {
                try {
                    const message = JSON.parse(event.data);
                    if (message?.type === 'authenticated' && data.channel) {
                        currentSocket.send(JSON.stringify({ type: 'subscribe', channel: data.channel }));
                        return;
                    }
                    if (message?.event_id && !remember(message.event_id)) return;
                    window.dispatchEvent(new CustomEvent('SevillaRealtimeEvent', { detail: message }));
                } catch (_) { /* Ignore malformed gateway frames. */ }
            });
            currentSocket.addEventListener('close', () => {
                if (socket === currentSocket) socket = null;
                attempts += 1;
                retry();
            });
            currentSocket.addEventListener('error', () => currentSocket.close());
        } catch (_) {
            attempts += 1;
            retry();
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && (!socket || socket.readyState > 1)) connect();
    });
    connect();
})();
