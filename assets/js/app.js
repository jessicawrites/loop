/**
 * Loop — Application Entry Point
 *
 * This file runs on every page. It initializes global features
 * and provides the foundation everything else builds on.
 */

const App = {
    version:  '1.0.0',
    name:     'Loop',
    baseUrl: '/',
    pollInterval: null,
    heartbeatTimer: null,

    /**
     * Runs once when the DOM is ready.
     * Initializes all global features.
     */
    init() {
        this.initTheme();
        UI.initRipples();
        this.initNetworkMonitor();
        this.initHeartbeat();
        console.log(`${this.name} v${this.version} initialized ✓`);
    },

    /**
     * Applies the saved theme (light/dark) from localStorage.
     */
    initTheme() {
        const saved = localStorage.getItem('loop_theme') || 'light';
        document.documentElement.setAttribute('data-theme', saved);
    },

    /**
     * Toggles between light and dark mode.
     */
    toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme');
        const next    = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('loop_theme', next);
    },

    /**
     * Watches for network connectivity changes.
     * Shows a friendly toast when the user goes offline.
     */
    initNetworkMonitor() {
        window.addEventListener('offline', () => {
            UI.toast('You\'re offline. Reconnecting…', 'warning', 0);
        });
        window.addEventListener('online', () => {
            UI.toast('Back online!', 'success');
        });
    },

    /**
     * Online Status Heartbeat
     *
     * Sends a "still here" ping to the server every 25 seconds while
     * any page is open. This is what keeps a user's online_status row
     * fresh — without it, the row would only update at login/logout,
     * and anyone who closed their laptop without logging out would be
     * stuck showing "online" forever (or "offline" the instant the tab
     * closes, with no graceful aging-out).
     *
     * Skips entirely on auth pages (login/register) where there's no
     * logged-in session yet to report status for.
     */
    initHeartbeat() {
        // Auth pages have no session — don't bother starting the timer.
        const path = window.location.pathname;
        if (path.includes('/login.php') || path.includes('/register.php')) {
            return;
        }

        const HEARTBEAT_INTERVAL = 25000; // 25s — comfortably inside the 5-minute online window

        const sendHeartbeat = () => {
            // Don't waste a request if the tab is in the background —
            // resumes immediately on visibilitychange below.
            if (document.hidden) return;

            const params = { status: 'online' };
            if (window.CSRF_TOKEN) params.csrf_token = window.CSRF_TOKEN;

            fetch(`${this.baseUrl}/api/users/status.php`, {
                method:      'POST',
                credentials: 'same-origin',
                headers:     { 'X-Requested-With': 'XMLHttpRequest' },
                body:        new URLSearchParams(params)
            }).catch(() => {
                // Silent fail — a missed heartbeat just means the user
                // ages out of "online" a little early. Next tick retries.
            });
        };

        // Fire one immediately on page load, then on the interval.
        sendHeartbeat();
        this.heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL);

        // Resume heartbeats promptly when the user comes back to this tab,
        // rather than waiting for the next scheduled tick.
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) sendHeartbeat();
        });

        // ── Mark offline on page close ──────────────────────────────────
        // Regular fetch() calls can be cancelled mid-flight when a tab
        // closes. navigator.sendBeacon() is built for exactly this case —
        // it queues the request so it survives the page unloading.
        window.addEventListener('pagehide', () => {
            clearInterval(this.heartbeatTimer);

            if (navigator.sendBeacon) {
                const params = { status: 'offline' };
                if (window.CSRF_TOKEN) params.csrf_token = window.CSRF_TOKEN;

                const data = new Blob(
                    [new URLSearchParams(params).toString()],
                    { type: 'application/x-www-form-urlencoded' }
                );
                navigator.sendBeacon(`${this.baseUrl}/api/users/status.php`, data);
            }
        });
    }
};

// ── Initialize when DOM is ready ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => App.init());