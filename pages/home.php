<?php
/**
 * Loop — Home Screen
 *
 * Shows the conversation list.
 * All data loads via AJAX after the shell renders.
 * This keeps the initial page load instant.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/constants.php';
require_login();

$user = current_user();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Loop</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
    <script>
        const t = localStorage.getItem('loop_theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
        window.CSRF_TOKEN = '<?= csrf_token() ?>';
    </script>

    <style>
        /* ── Conversation List ───────────────────────────────────────── */
        .conv-list {
            list-style: none;
        }

        .conv-item {
            display:     flex;
            align-items: center;
            gap:         var(--space-3);
            padding:     var(--space-3) var(--space-4);
            cursor:      pointer;
            transition:  background var(--transition-fast);
            position:    relative;
            text-decoration: none;
            color:           inherit;
        }
        .conv-item:hover,
        .conv-item:focus {
            background: var(--color-bg);
            outline:    none;
        }
        .conv-item:active {
            background: var(--color-border);
        }

        /* Thin separator between rows */
        .conv-item + .conv-item::before {
            content:    '';
            position:   absolute;
            top:        0;
            left:       calc(46px + var(--space-3) + var(--space-4));
            right:      0;
            height:     1px;
            background: var(--color-border);
        }

        .conv-body {
            flex:       1;
            min-width:  0;   /* Allows text-overflow: ellipsis to work */
        }

        .conv-top {
            display:         flex;
            justify-content: space-between;
            align-items:     baseline;
            gap:             var(--space-2);
            margin-bottom:   3px;
        }

        .conv-name {
            font-weight:   var(--font-weight-semibold);
            font-size:     var(--font-size-base);
            white-space:   nowrap;
            overflow:      hidden;
            text-overflow: ellipsis;
            flex:          1;
            min-width:     0;
        }

        .conv-time {
            font-size:  var(--font-size-xs);
            color:      var(--color-text-muted);
            flex-shrink: 0;
        }
        /* Make time blue when there are unread messages */
        .conv-item.has-unread .conv-time {
            color: var(--color-primary);
        }

        .conv-bottom {
            display:     flex;
            align-items: center;
            gap:         var(--space-2);
        }

        .conv-preview {
            font-size:     var(--font-size-sm);
            color:         var(--color-text-secondary);
            white-space:   nowrap;
            overflow:      hidden;
            text-overflow: ellipsis;
            flex:          1;
            min-width:     0;
        }
        /* Bold preview when unread */
        .conv-item.has-unread .conv-preview {
            color:       var(--color-text-primary);
            font-weight: var(--font-weight-medium);
        }

        /* ── Search Overlay ──────────────────────────────────────────── */
        .search-overlay {
            position:   fixed;
            inset:      0;
            background: var(--color-bg);
            z-index:    var(--z-modal);
            display:    flex;
            flex-direction: column;
            max-width:  var(--max-width-app);
            left:       50%;
            transform:  translateX(-50%);

            /* Hidden by default */
            opacity:    0;
            pointer-events: none;
            transition: opacity var(--transition-normal);
        }
        .search-overlay.open {
            opacity:        1;
            pointer-events: auto;
        }

        .search-header {
            display:      flex;
            align-items:  center;
            gap:          var(--space-3);
            padding:      var(--space-3) var(--space-4);
            border-bottom: 1px solid var(--color-border);
            background:   var(--color-surface);
        }

        .search-input-wrap {
            flex:          1;
            position:      relative;
            display:       flex;
            align-items:   center;
        }

        .search-icon {
            position: absolute;
            left:     12px;
            color:    var(--color-text-muted);
            pointer-events: none;
        }

        .search-field {
            width:         100%;
            padding:       var(--space-2) var(--space-4) var(--space-2) 38px;
            background:    var(--color-bg);
            border:        1.5px solid var(--color-border);
            border-radius: var(--radius-full);
            font-size:     var(--font-size-base);
            color:         var(--color-text-primary);
            transition:    border-color var(--transition-fast);
            min-height:    40px;
        }
        .search-field:focus {
            border-color: var(--color-primary);
            outline:      none;
        }
        .search-field::placeholder { color: var(--color-text-muted); }

        .search-cancel {
            color:       var(--color-primary);
            font-size:   var(--font-size-base);
            font-weight: var(--font-weight-medium);
            white-space: nowrap;
            padding:     var(--space-2);
            border-radius: var(--radius-sm);
            transition:  opacity var(--transition-fast);
        }
        .search-cancel:hover { opacity: 0.75; }

        .search-results {
            flex:       1;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .search-hint {
            padding:    var(--space-10) var(--space-6);
            text-align: center;
            color:      var(--color-text-muted);
            font-size:  var(--font-size-sm);
        }

        /* User result row */
        .user-result {
            display:     flex;
            align-items: center;
            gap:         var(--space-3);
            padding:     var(--space-3) var(--space-4);
            cursor:      pointer;
            transition:  background var(--transition-fast);
            border:      none;
            width:       100%;
            text-align:  left;
            background:  none;
        }
        .user-result:hover { background: var(--color-bg); }
        .user-result:active { background: var(--color-border); }

        .user-result-info { flex: 1; min-width: 0; }

        .user-result-name {
            font-weight:   var(--font-weight-semibold);
            font-size:     var(--font-size-base);
            white-space:   nowrap;
            overflow:      hidden;
            text-overflow: ellipsis;
        }

        .user-result-username {
            font-size: var(--font-size-sm);
            color:     var(--color-text-secondary);
        }

        /* ── Notification Dropdown ───────────────────────────────────── */
        .notif-item {
            display:      flex;
            gap:          var(--space-3);
            padding:      var(--space-3);
            border-bottom: 1px solid var(--color-border);
            cursor:       pointer;
            transition:   background var(--transition-fast);
            text-decoration: none;
            color: inherit;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover  { background: var(--color-bg); }
        .notif-item.unread { background: var(--color-primary-light); }

        .notif-item-dot {
            width:         8px;
            height:        8px;
            border-radius: 50%;
            background:    var(--color-primary);
            flex-shrink:   0;
            margin-top:    6px;
            opacity:       0;
        }
        .notif-item.unread .notif-item-dot { opacity: 1; }

        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-text {
            font-size:   var(--font-size-sm);
            line-height: var(--line-height-normal);
            white-space: nowrap;
            overflow:    hidden;
            text-overflow: ellipsis;
        }
        .notif-item-time {
            font-size:  var(--font-size-xs);
            color:      var(--color-text-muted);
            margin-top: 2px;
        }
        .notif-empty {
            padding:    var(--space-8) var(--space-4);
            text-align: center;
            color:      var(--color-text-muted);
            font-size:  var(--font-size-sm);
        }
    </style>
</head>
<body>
<div class="app-shell">

    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
         HEADER
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <header class="page-header">

        <!-- Current user avatar → tapping opens profile (M5) -->
        <a href="<?= APP_URL ?>/pages/profile.php"
           aria-label="Your profile"
           style="flex-shrink:0;">
            <div class="avatar-wrap">
                <img
                    src="<?= htmlspecialchars(
                        $user['avatar']
                            ? AVATARS_URL . $user['avatar']
                            : APP_URL . '/assets/images/avatars/default.svg'
                    ) ?>"
                    alt="<?= htmlspecialchars($user['display_name']) ?>"
                    class="avatar avatar-sm"
                    id="headerAvatar"
                >
            </div>
        </a>

        <span class="page-header-title">Loop</span>

        <!-- Notification bell -->
        <div style="position: relative;">
            <button
                class="page-header-action"
                id="notifBellBtn"
                aria-label="Notifications"
                title="Notifications"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <span class="badge" id="notifBadge"
                      style="position:absolute; top:2px; right:2px; display:none;
                             min-width:16px; height:16px; font-size:9px;">
                </span>
            </button>

            <!-- Dropdown panel -->
            <div id="notifDropdown" style="
                display: none;
                position: absolute;
                top: calc(100% + 8px);
                right: 0;
                width: 300px;
                max-height: 360px;
                overflow-y: auto;
                background: var(--color-surface);
                border-radius: var(--radius-lg);
                box-shadow: var(--shadow-lg);
                z-index: var(--z-dropdown);
            ">
                <div id="notifList"></div>
            </div>
        </div>

        <!-- Search / New chat button -->
        <button
            class="page-header-action"
            id="openSearchBtn"
            aria-label="New conversation"
            title="Start a new chat"
        >
            <!-- Compose / pencil icon -->
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/>
                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
        </button>

    </header>

    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
         MAIN CONTENT — Conversation List
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <main class="page-content" id="mainContent">

        <!-- Skeleton shown while AJAX loads -->
        <div id="convSkeleton">
            <!-- Rendered by JS -->
        </div>

        <!-- The real list, hidden until data arrives -->
        <ul class="conv-list stagger-children" id="convList" style="display:none;">
            <!-- Rendered by JS -->
        </ul>

        <!-- Empty state — shown when user has no conversations yet -->
        <div id="emptyState" style="display:none;">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <!-- Chat bubble icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                </div>
                <h3 class="empty-state-title">No conversations yet</h3>
                <p class="empty-state-text">
                    Tap the pencil icon above to find someone and start chatting.
                </p>
            </div>
        </div>

    </main>

    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
         BOTTOM NAVIGATION
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <nav class="bottom-nav" aria-label="Main navigation">

        <!-- Chats (active) -->
        <a href="<?= APP_URL ?>/pages/home.php"
           class="nav-item active" aria-label="Chats">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <span class="nav-label">Chats</span>
        </a>

        <!-- Profile -->
        <a href="<?= APP_URL ?>/pages/profile.php"
           class="nav-item" aria-label="Profile">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            <span class="nav-label">Profile</span>
        </a>

    </nav>

</div><!-- /.app-shell -->


<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     SEARCH OVERLAY — slides in when "New Chat" is tapped
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<div class="search-overlay" id="searchOverlay" role="dialog" aria-label="Find people">

    <div class="search-header">
        <div class="search-input-wrap">
            <!-- Search icon -->
            <svg class="search-icon" xmlns="http://www.w3.org/2000/svg"
                 width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input
                type="search"
                class="search-field"
                id="searchField"
                placeholder="Search by name or username…"
                autocomplete="off"
                autocapitalize="none"
                spellcheck="false"
                aria-label="Search users"
            >
        </div>
        <button class="search-cancel" id="closeSearchBtn">Cancel</button>
    </div>

    <div class="search-results" id="searchResults">
        <p class="search-hint">
            Type at least 2 characters to search.
        </p>
    </div>

</div>


<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     SCRIPTS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<script src="<?= APP_URL ?>/assets/js/utils.js"></script>
<script src="<?= APP_URL ?>/assets/js/ui.js"></script>
<script src="<?= APP_URL ?>/assets/js/api.js"></script>
<script>
/**
 * Home Page Logic
 *
 * Responsibilities:
 * 1. Load and render the conversation list on page load
 * 2. Handle the search overlay open/close
 * 3. Search users and render results
 * 4. Create or open a conversation when a user is tapped
 */
(function () {

    // ── DOM references ─────────────────────────────────────────────────
    const convList      = document.getElementById('convList');
    const convSkeleton  = document.getElementById('convSkeleton');
    const emptyState    = document.getElementById('emptyState');
    const searchOverlay = document.getElementById('searchOverlay');
    const searchField   = document.getElementById('searchField');
    const searchResults = document.getElementById('searchResults');
    const openSearchBtn = document.getElementById('openSearchBtn');
    const closeSearchBtn= document.getElementById('closeSearchBtn');

    // The logged-in user's ID — passed from PHP so JS knows who "me" is
    const CURRENT_USER_ID = <?= (int) $user['id'] ?>;

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 1 — Conversation List
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Loads conversations from the API and renders them.
     * Called on page load and periodically for updates.
     */
    async function loadConversations() {
        const result = await API.fetchConversations();

        // Hide skeleton regardless of outcome
        convSkeleton.innerHTML = '';

        if (!result.success) {
            UI.toast('Could not load conversations.', 'error');
            return;
        }

        const convs = result.data.conversations;

        if (convs.length === 0) {
            emptyState.style.display = 'block';
            convList.style.display   = 'none';
            return;
        }

        emptyState.style.display = 'none';
        convList.style.display   = 'block';

        // Only touch the DOM if the data actually changed — prevents the
        // entire list from re-animating every 3s on every poll cycle,
        // even when nothing new has happened.
        const newHtml = convs.map(renderConvItem).join('');
        if (newHtml !== convList.dataset.lastHtml) {
            convList.innerHTML = newHtml;
            convList.dataset.lastHtml = newHtml;
        }
    }

    /**
     * Renders a single conversation row as an HTML string.
     *
     * @param {object} conv  - Conversation data from API
     * @returns {string}     - HTML for one <li> row
     */
    function renderConvItem(conv) {
        const hasUnread  = conv.unread_count > 0;
        const unreadHtml = hasUnread
            ? `<span class="badge"
                     style="animation: badgeBounce 0.4s ease;"
                     aria-label="${conv.unread_count} unread messages">
                    ${Utils.formatCount(conv.unread_count)}
               </span>`
            : '';

        // "You: " prefix when the last message was sent by the logged-in user
        const sentByMe = conv.last_sender_id === CURRENT_USER_ID;
        const preview  = (sentByMe ? 'You: ' : '') +
                          Utils.escapeHtml(conv.last_message_preview);

        // Online dot
        const onlineDot = conv.is_online
            ? `<span class="online-dot" aria-label="Online"></span>`
            : '';

        return `
            <li class="animate-slide-up">
                <a class="conv-item ${hasUnread ? 'has-unread' : ''}"
                   href="<?= APP_URL ?>/pages/chat.php?id=${conv.conversation_id}"
                   aria-label="Chat with ${Utils.escapeHtml(conv.other_display_name)}">

                    <!-- Avatar -->
                    <div class="avatar-wrap">
                        <img
                            src="${Utils.escapeHtml(conv.other_avatar_url)}"
                            alt="${Utils.escapeHtml(conv.other_display_name)}"
                            class="avatar avatar-md"
                            onerror="this.src='<?= APP_URL ?>/assets/images/avatars/default.svg'"
                        >
                        ${onlineDot}
                    </div>

                    <!-- Text body -->
                    <div class="conv-body">
                        <div class="conv-top">
                            <span class="conv-name">
                                ${Utils.escapeHtml(conv.other_display_name)}
                            </span>
                            <span class="conv-time">${conv.time_label}</span>
                        </div>
                        <div class="conv-bottom">
                            <span class="conv-preview">${preview}</span>
                            ${unreadHtml}
                        </div>
                    </div>

                </a>
            </li>
        `;
    }

    // Show skeleton immediately while data loads
    convSkeleton.innerHTML = UI.skeletonRows(6);
    loadConversations();

    // ── Poll for updates ────────────────────────────────────────────────
    // Keeps the conversation list, unread badges, and online dots fresh
    // without requiring the user to manually refresh or re-navigate.
    Poller.start(<?= POLLING_INTERVAL ?>, () => {
        loadConversations();
        loadNotifications();
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 5 — Notifications
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    const notifBellBtn  = document.getElementById('notifBellBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadge    = document.getElementById('notifBadge');
    const notifList     = document.getElementById('notifList');

    /**
     * Fetches notifications and updates the badge count.
     * Pass markRead=true only when the dropdown is actually opened —
     * routine background polls shouldn't silently clear the badge
     * before the user has looked at it.
     */
    async function loadNotifications(markRead = false) {
        const url = markRead
            ? '/loop/api/notifications/fetch.php?mark_read=1'
            : '/loop/api/notifications/fetch.php';

        const result = await API.request(url);
        if (!result.success) return;

        const { notifications, unread_count } = result.data;

        // Badge
        if (unread_count > 0) {
            notifBadge.textContent   = Utils.formatCount(unread_count);
            notifBadge.style.display = 'flex';
        } else {
            notifBadge.style.display = 'none';
        }

        // Dropdown content
        if (notifications.length === 0) {
            notifList.innerHTML = `<div class="notif-empty">No notifications yet.</div>`;
            return;
        }

        notifList.innerHTML = notifications.map(n => `
            <a class="notif-item ${n.is_read ? '' : 'unread'}"
               href="<?= APP_URL ?>/pages/chat.php?id=${n.reference_id}">
                <span class="notif-item-dot"></span>
                <div class="notif-item-body">
                    <div class="notif-item-text">${Utils.escapeHtml(n.message)}</div>
                    <div class="notif-item-time">${n.time_label}</div>
                </div>
            </a>
        `).join('');
    }

    function toggleNotifDropdown() {
        const isOpen = notifDropdown.style.display === 'block';
        notifDropdown.style.display = isOpen ? 'none' : 'block';

        // Mark all as read the moment the user actually opens the panel
        if (!isOpen) {
            loadNotifications(true);
        }
    }

    notifBellBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleNotifDropdown();
    });

    // Close dropdown when clicking anywhere else on the page
    document.addEventListener('click', (e) => {
        if (!notifDropdown.contains(e.target) && e.target !== notifBellBtn) {
            notifDropdown.style.display = 'none';
        }
    });

    // Initial load (unread count only — dropdown stays closed)
    loadNotifications(false);

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 2 — Search Overlay
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    function openSearch() {
        searchOverlay.classList.add('open');
        // Small delay so the CSS transition plays before focus
        setTimeout(() => searchField.focus(), 150);
    }

    function closeSearch() {
        searchOverlay.classList.remove('open');
        searchField.value    = '';
        searchResults.innerHTML = `<p class="search-hint">
            Type at least 2 characters to search.
        </p>`;
    }

    openSearchBtn.addEventListener('click',  openSearch);
    closeSearchBtn.addEventListener('click', closeSearch);

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && searchOverlay.classList.contains('open')) {
            closeSearch();
        }
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 3 — User Search
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Debounced search — waits 350ms after the user stops typing
     * before firing the API call. Prevents hammering the server
     * on every single keystroke.
     */
    const doSearch = Utils.debounce(async (query) => {
        if (query.length < 2) {
            searchResults.innerHTML = `<p class="search-hint">
                Type at least 2 characters to search.
            </p>`;
            return;
        }

        // Show loading state
        searchResults.innerHTML = UI.skeletonRows(3);

        const result = await API.searchUsers(query);

        if (!result.success) {
            searchResults.innerHTML = `<p class="search-hint">
                Something went wrong. Try again.
            </p>`;
            return;
        }

        const users = result.data.users;

        if (users.length === 0) {
            searchResults.innerHTML = `
                <div class="empty-state" style="padding: var(--space-12) var(--space-6);">
                    <div class="empty-state-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </div>
                    <p class="empty-state-text">
                        No users found for "<strong>${Utils.escapeHtml(query)}</strong>"
                    </p>
                </div>
            `;
            return;
        }

        searchResults.innerHTML = users.map(renderUserResult).join('');
    }, 350);

    searchField.addEventListener('input', (e) => {
        doSearch(e.target.value.trim());
    });

    /**
     * Renders a single user result row.
     *
     * @param {object} user
     * @returns {string}
     */
    function renderUserResult(user) {
        return `
            <button
                class="user-result animate-fade-in"
                data-user-id="${(int = user.id, int)}"
                aria-label="Start chat with ${Utils.escapeHtml(user.display_name)}"
            >
                <img
                    src="${Utils.escapeHtml(user.avatar_url)}"
                    alt="${Utils.escapeHtml(user.display_name)}"
                    class="avatar avatar-md"
                    onerror="this.src='<?= APP_URL ?>/assets/images/avatars/default.svg'"
                >
                <div class="user-result-info">
                    <div class="user-result-name">
                        ${Utils.escapeHtml(user.display_name)}
                    </div>
                    <div class="user-result-username">
                        @${Utils.escapeHtml(user.username)}
                    </div>
                </div>
                <!-- Arrow icon -->
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     style="color:var(--color-text-muted); flex-shrink:0;">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
        `;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 4 — Start / Open Conversation
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Event delegation — one listener handles clicks on ALL user result buttons.
     * Much more efficient than adding a listener to every button individually.
     */
    searchResults.addEventListener('click', async (e) => {
        const btn = e.target.closest('.user-result');
        if (!btn) return;

        const userId = parseInt(btn.dataset.userId, 10);
        if (!userId) return;

        // Show loading on the button
        const restore = UI.setLoading(btn, 'Opening…');

        // Find or create a conversation with this user
        const result = await API.request('/loop/api/messages/conversations.php', {
            method: 'POST',
            body:   (() => {
                const fd = new FormData();
                fd.append('user_id', userId);
                return fd;
            })()
        });

        restore();

        if (result.success) {
            // Navigate to the chat page
            window.location.href =
                `<?= APP_URL ?>/pages/chat.php?id=${result.data.conversation_id}`;
        } else {
            UI.toast(result.message || 'Could not open conversation.', 'error');
        }
    });

})();
</script>

</body>
</html>