/**
 * Loop — API Communication Layer
 * 
 * All AJAX calls to the backend live here.
 * No fetch() calls should exist outside this file.
 * This makes the app easy to maintain — change the API, change one file.
 */

const API = {

    /**
     * Base fetch wrapper with consistent error handling.
     * Every API call goes through this.
     * 
     * @param {string} endpoint  - Path relative to app root e.g. '/api/auth/login.php'
     * @param {object} options   - fetch() options (method, body, etc.)
     * @returns {Promise<object>} - Always resolves to { success, message, data }
     */
    async request(endpoint, options = {}) {
        const defaults = {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',   // Send session cookies
        };

        const config = { ...defaults, ...options };
        if (config.headers && options.headers) {
            config.headers = { ...defaults.headers, ...options.headers };
        }

        // Attach the CSRF token to every state-changing request.
        // GET requests are read-only and don't need it (CSRF only matters
        // for requests that change state). FormData bodies get the token
        // appended as a field; everything else doesn't need one here since
        // this app only ever sends FormData or no body.
        const method = (config.method || 'GET').toUpperCase();
        if (method !== 'GET' && window.CSRF_TOKEN) {
            if (config.body instanceof FormData) {
                config.body.append('csrf_token', window.CSRF_TOKEN);
            } else if (!config.body) {
                config.body = new URLSearchParams({ csrf_token: window.CSRF_TOKEN });
            }
        }

        try {
            const response = await fetch(endpoint, config);

            // Handle non-JSON responses (e.g. PHP fatal errors)
            const contentType = response.headers.get('content-type');
            if (!contentType?.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text);
                return { success: false, message: 'Server error. Please try again.' };
            }

            return await response.json();

        } catch (error) {
            console.error('API request failed:', error);
            return { success: false, message: 'Network error. Check your connection.' };
        }
    },

    // ── Auth ──────────────────────────────────────────────────────────────

    async login(username, password) {
        const body = new FormData();
        body.append('username', username);
        body.append('password', password);
        return this.request('/loop/api/auth/login.php', { method: 'POST', body });
    },

    async register(formData) {
        return this.request('/loop/api/auth/register.php', { method: 'POST', body: formData });
    },

    async logout() {
        return this.request('/loop/api/auth/logout.php', { method: 'POST' });
    },

    // ── Messages ──────────────────────────────────────────────────────────

    async sendMessage(conversationId, content) {
        const body = new FormData();
        body.append('conversation_id', conversationId);
        body.append('content', content);
        return this.request('/loop/api/messages/send.php', { method: 'POST', body });
    },

    async fetchMessages(conversationId, lastId = 0) {
        return this.request(
            `/loop/api/messages/fetch.php?conversation_id=${conversationId}&last_id=${lastId}`
        );
    },

    async fetchConversations() {
        return this.request('/loop/api/messages/conversations.php');
    },

    // ── Users ─────────────────────────────────────────────────────────────

    async searchUsers(query) {
        return this.request(`/loop/api/users/search.php?q=${encodeURIComponent(query)}`);
    },

    async updateProfile(formData) {
        return this.request('/loop/api/users/profile.php', { method: 'POST', body: formData });
    },

    async updateStatus(status) {
        const body = new FormData();
        body.append('status', status);
        return this.request('/loop/api/users/status.php', { method: 'POST', body });
    },

    // ── Notifications ─────────────────────────────────────────────────────

    async fetchNotifications() {
        return this.request('/loop/api/notifications/fetch.php');
    }
};