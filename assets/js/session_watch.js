(() => {
    const scriptUrl = document.currentScript?.src;
    if (scriptUrl) {
        const appRoot = new URL('../../', scriptUrl);
        const checkUrl = new URL('actions/auth/session_check.php', appRoot).href;
        const signInUrl = new URL('auth.php', appRoot).href;
        const intervalMs = 1000;
        const requestTimeoutMs = 5000;
        let checking = false;
        let signedOut = false;
        let recheckWhenActive = false;

        const checkSession = async (foregroundEvent = false) => {
            if (document.hidden || signedOut) return;
            if (checking) {
                if (foregroundEvent) recheckWhenActive = true;
                return;
            }
            checking = true;
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), requestTimeoutMs);

            try {
                const response = await fetch(checkUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                });

                if (response.status === 401) {
                    signedOut = true;
                    window.location.replace(signInUrl);
                }
            } catch (error) {
                // Network outages do not invalidate the local session.
            } finally {
                window.clearTimeout(timeout);
                checking = false;
                if (recheckWhenActive && !document.hidden && !signedOut) {
                    recheckWhenActive = false;
                    window.setTimeout(() => checkSession(), 0);
                }
            }
        };

        // A visible tab checks continuously. Browsers throttle background timers,
        // so resuming or focusing the tab starts a check immediately.
        window.setInterval(checkSession, intervalMs);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) checkSession(true);
        });
        window.addEventListener('focus', () => checkSession(true));
        window.addEventListener('pageshow', () => checkSession(true));
        window.addEventListener('online', () => checkSession(true));
        checkSession();
    }
})();
