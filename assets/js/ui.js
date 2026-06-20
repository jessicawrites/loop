/**
 * Loop — UI Utilities
 * 
 * DOM manipulation helpers, toast notifications, loaders, modals.
 * These functions manipulate what the user sees.
 */

const UI = {

    /**
     * Shows a toast notification at the top of the screen.
     * Automatically disappears after a delay.
     * 
     * @param {string} message
     * @param {'success'|'error'|'warning'|'info'} type
     * @param {number} duration  - Milliseconds before auto-dismiss
     */
    toast(message, type = 'info', duration = 3500) {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const icons = {
            success: '✓',
            error:   '✕',
            warning: '⚠',
            info:    'ℹ'
        };

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<span>${icons[type]}</span><span>${Utils.escapeHtml(message)}</span>`;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity  = '0';
            toast.style.transform = 'translateY(-8px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },

    /**
     * Sets a button into a loading state (disables it, shows spinner).
     * Returns a function to restore the button.
     * 
     * @param {HTMLButtonElement} btn
     * @param {string} loadingText
     * @returns {Function} - Call this to restore the button
     */
    setLoading(btn, loadingText = 'Loading...') {
        const original = btn.textContent;
        btn.classList.add('loading');
        btn.disabled   = true;
        btn.textContent = loadingText;
        return () => {
            btn.classList.remove('loading');
            btn.disabled    = false;
            btn.textContent = original;
        };
    },

    /**
     * Shows inline field errors under form inputs.
     * 
     * @param {HTMLElement} form
     * @param {string[]} errors
     */
    showFormErrors(form, errors) {
        // Clear previous errors
        form.querySelectorAll('.error-msg').forEach(el => el.remove());
        form.querySelectorAll('.input-field.error').forEach(el => {
            el.classList.remove('error');
        });

        // Show first error as a toast
        if (errors.length > 0) {
            this.toast(errors[0], 'error');
        }
    },

    /**
     * Clears all error states from a form.
     * 
     * @param {HTMLElement} form
     */
    clearFormErrors(form) {
        form.querySelectorAll('.error-msg').forEach(el => el.remove());
        form.querySelectorAll('.input-field.error').forEach(el => {
            el.classList.remove('error');
        });
    },

    /**
     * Creates a skeleton loader row (for conversation lists, etc.)
     * 
     * @param {number} count  - Number of skeleton rows to show
     * @returns {string}       - HTML string
     */
    skeletonRows(count = 5) {
        return Array.from({ length: count }, () => `
            <div style="display:flex; align-items:center; gap:12px; padding:12px 16px;">
                <div class="skeleton skeleton-avatar avatar-md"></div>
                <div style="flex:1; display:flex; flex-direction:column; gap:8px;">
                    <div class="skeleton skeleton-text" style="width:40%"></div>
                    <div class="skeleton skeleton-text" style="width:70%"></div>
                </div>
            </div>
        `).join('');
    },

    /**
     * Adds a ripple animation to a button on click.
     * Call once during app initialization.
     */
    initRipples() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn');
            if (!btn) return;

            const rect   = btn.getBoundingClientRect();
            const ripple = document.createElement('span');
            const size   = Math.max(rect.width, rect.height);

            ripple.className = 'ripple';
            ripple.style.cssText = `
                width: ${size}px;
                height: ${size}px;
                left: ${e.clientX - rect.left - size / 2}px;
                top: ${e.clientY - rect.top - size / 2}px;
            `;

            btn.appendChild(ripple);
            ripple.addEventListener('animationend', () => ripple.remove());
        });
    },

    /**
     * Scrolls an element to the bottom (for chat windows).
     * 
     * @param {HTMLElement} el
     * @param {boolean} smooth
     */
    scrollToBottom(el, smooth = true) {
        if (!el) return;
        el.scrollTo({
            top:      el.scrollHeight,
            behavior: smooth ? 'smooth' : 'instant'
        });
    }
};