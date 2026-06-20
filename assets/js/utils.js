/**
 * Loop — JavaScript Utility Functions
 * 
 * Reusable helpers that have nothing to do with the UI.
 * These are pure functions: same input always gives same output.
 */

const Utils = {

    /**
     * Formats a date string into a human-readable relative time.
     * Mirrors the PHP time_ago() function for client-side use.
     * 
     * @param {string} dateString - ISO or MySQL datetime string
     * @returns {string}
     */
    timeAgo(dateString) {
        const now  = new Date();
        const then = new Date(dateString);
        const diff = Math.floor((now - then) / 1000); // seconds

        if (diff < 60)     return 'just now';
        if (diff < 3600)   return `${Math.floor(diff / 60)}m ago`;
        if (diff < 86400)  return `${Math.floor(diff / 3600)}h ago`;
        if (diff < 172800) return 'Yesterday';

        return then.toLocaleDateString('en-GB', {
            weekday: 'short', day: 'numeric', month: 'short'
        });
    },

    /**
     * Escapes HTML special characters to prevent XSS.
     * Always use this before inserting user content into the DOM.
     * 
     * @param {string} str
     * @returns {string}
     */
    escapeHtml(str) {
        const map = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' };
        return String(str).replace(/[&<>"']/g, m => map[m]);
    },

    /**
     * Debounces a function — waits until the user stops calling it.
     * Useful for search inputs: don't fire on every keystroke.
     * 
     * @param {Function} fn      - The function to debounce
     * @param {number}   delay   - Wait time in milliseconds
     * @returns {Function}
     */
    debounce(fn, delay = 300) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    },

    /**
     * Truncates a string to a max length with ellipsis.
     * 
     * @param {string} str
     * @param {number} max
     * @returns {string}
     */
    truncate(str, max = 60) {
        if (!str) return '';
        return str.length > max ? str.slice(0, max) + '…' : str;
    },

    /**
     * Generates initials from a display name.
     * Used as fallback when avatar image fails to load.
     * e.g. "Tonny Ochieng" → "TO"
     * 
     * @param {string} name
     * @returns {string}
     */
    initials(name) {
        if (!name) return '?';
        return name
            .split(' ')
            .slice(0, 2)
            .map(w => w[0].toUpperCase())
            .join('');
    },

    /**
     * Checks if the device is mobile.
     * 
     * @returns {boolean}
     */
    isMobile() {
        return window.innerWidth <= 480;
    },

    /**
     * Safely parses JSON without throwing.
     * 
     * @param {string} str
     * @param {*} fallback
     * @returns {*}
     */
    parseJSON(str, fallback = null) {
        try {
            return JSON.parse(str);
        } catch {
            return fallback;
        }
    },

    /**
     * Formats a number as a compact badge count.
     * e.g. 1500 → "1.5k", 99+ stays "99+"
     * 
     * @param {number} n
     * @returns {string}
     */
    formatCount(n) {
        if (n > 99)   return '99+';
        if (n > 999)  return (n / 1000).toFixed(1) + 'k';
        return String(n);
    }
};

/**
 * Loop — Poller
 *
 * A small reusable wrapper around setInterval that handles the same
 * three rules every polling loop in this app needs:
 *   1. Skip ticks while the tab is hidden (saves requests/battery)
 *   2. Fire immediately when the tab becomes visible again (catch up)
 *   3. Clean up automatically when the page is closed
 *
 * Usage:
 *   const poller = Poller.start(3000, () => { ...do the poll... });
 *   poller.stop();   // manual stop if ever needed
 */
const Poller = {
    start(intervalMs, fn) {
        const tick = () => {
            if (document.hidden) return;
            fn();
        };

        const timer = setInterval(tick, intervalMs);

        const onVisible = () => {
            if (!document.hidden) fn();
        };
        document.addEventListener('visibilitychange', onVisible);

        const onUnload = () => clearInterval(timer);
        window.addEventListener('pagehide', onUnload);

        return {
            stop() {
                clearInterval(timer);
                document.removeEventListener('visibilitychange', onVisible);
                window.removeEventListener('pagehide', onUnload);
            }
        };
    }
};