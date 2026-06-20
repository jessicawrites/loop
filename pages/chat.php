<?php
/**
 * Loop — Chat Interface
 *
 * Loads the conversation shell + most recent messages server-side
 * (fast first paint), then JS takes over for sending and polling.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

$me  = current_user_id();
$pdo = get_db();

$conversation_id = (int) ($_GET['id'] ?? 0);
if (!$conversation_id) {
    redirect(APP_URL . '/pages/home.php');
}

// ── Verify membership + fetch the OTHER user's profile in one query ────────
// If this returns nothing, either the conversation doesn't exist or
// the logged-in user isn't a member of it — either way, kick them out.
$stmt = $pdo->prepare('
    SELECT
        c.id AS conversation_id,
        u.id AS other_user_id,
        u.display_name AS other_display_name,
        u.username AS other_username,
        u.avatar AS other_avatar,
        CASE
            WHEN os.is_online = 1
            AND  os.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            THEN 1 ELSE 0
        END AS is_online,
        os.last_seen
    FROM conversations c
    INNER JOIN conversation_members me_member
        ON me_member.conversation_id = c.id AND me_member.user_id = ?
    INNER JOIN conversation_members other_member
        ON other_member.conversation_id = c.id AND other_member.user_id != ?
    INNER JOIN users u ON u.id = other_member.user_id
    LEFT  JOIN online_status os ON os.user_id = u.id
    WHERE c.id = ?
    LIMIT 1
');
$stmt->execute([$me, $me, $conversation_id]);
$conv = $stmt->fetch();

if (!$conv) {
    // Not a member, or conversation doesn't exist — silently redirect.
    // We don't reveal *why* (security: don't confirm conversation IDs exist).
    redirect(APP_URL . '/pages/home.php');
}

$other_avatar_url = avatar_url($conv['other_avatar']);

// ── Fetch initial messages (most recent 50, ascending for display) ─────────
$stmt = $pdo->prepare('
    SELECT * FROM (
        SELECT
            m.id, m.conversation_id, m.sender_id, m.content,
            m.message_type, m.image_path, m.is_read, m.created_at,
            u.display_name AS sender_name
        FROM   messages m
        INNER  JOIN users u ON u.id = m.sender_id
        WHERE  m.conversation_id = ?
        ORDER  BY m.created_at DESC
        LIMIT  ?
    ) recent
    ORDER BY created_at ASC
');
$stmt->bindValue(1, $conversation_id, PDO::PARAM_INT);
$stmt->bindValue(2, MESSAGES_PER_PAGE, PDO::PARAM_INT);
$stmt->execute();
$messages = $stmt->fetchAll();

// Build image_url for each, same shape as the AJAX endpoints —
// keeps appendMessage() in JS able to treat initial + polled messages identically.
foreach ($messages as &$m) {
    $m['image_url'] = ($m['message_type'] === 'image' && $m['image_path'])
        ? MESSAGE_IMAGES_URL . $m['image_path']
        : null;
}
unset($m);

// Mark unread messages as read immediately on page load (server-side,
// before JS even runs — avoids a flash of unread state)
try {
    $stmt = $pdo->prepare('
        UPDATE messages SET is_read = 1
        WHERE  conversation_id = ? AND sender_id != ? AND is_read = 0
    ');
    $stmt->execute([$conversation_id, $me]);

    $stmt = $pdo->prepare('
        UPDATE conversation_members SET last_read_at = NOW()
        WHERE  conversation_id = ? AND user_id = ?
    ');
    $stmt->execute([$conversation_id, $me]);
} catch (PDOException $e) {
    error_log('Failed to mark read on chat load: ' . $e->getMessage());
}

$last_message_id = !empty($messages) ? end($messages)['id'] : 0;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title><?= htmlspecialchars($conv['other_display_name']) ?> — Loop</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
    <script>
        const t = localStorage.getItem('loop_theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
        window.CSRF_TOKEN = '<?= csrf_token() ?>';
    </script>

    <style>
        /* ── Chat Header ─────────────────────────────────────────────── */
        .chat-header-user {
            display:     flex;
            align-items: center;
            gap:         var(--space-3);
            flex:        1;
            min-width:   0;
        }
        .chat-header-info { min-width: 0; }
        .chat-header-name {
            font-size:     var(--font-size-base);
            font-weight:   var(--font-weight-semibold);
            white-space:   nowrap;
            overflow:      hidden;
            text-overflow: ellipsis;
        }
        .chat-header-status {
            font-size: var(--font-size-xs);
            color:     var(--color-text-muted);
        }
        .chat-header-status.online { color: var(--color-secondary); }

        /* ── Message Area ────────────────────────────────────────────── */
        .messages-area {
            height:     calc(100vh - var(--header-height) - var(--composer-height, 72px));
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding:    var(--space-4) var(--space-3);
            display:    flex;
            flex-direction: column;
            gap:        2px;
            background: var(--color-bg);
        }

        /* Date divider between message groups from different days */
        .date-divider {
            display:         flex;
            align-items:     center;
            justify-content: center;
            margin:          var(--space-4) 0 var(--space-2);
        }
        .date-divider span {
            background:    var(--color-border);
            color:         var(--color-text-secondary);
            font-size:     var(--font-size-xs);
            font-weight:   var(--font-weight-medium);
            padding:       4px var(--space-3);
            border-radius: var(--radius-full);
        }

        /* ── Message Row ─────────────────────────────────────────────── */
        .msg-row {
            display: flex;
            margin-bottom: 2px;
            max-width: 100%;
        }
        .msg-row.sent     { justify-content: flex-end; }
        .msg-row.received { justify-content: flex-start; }

        /* Tighten spacing between consecutive messages from same sender */
        .msg-row.grouped { margin-top: -2px; }

        .msg-bubble {
            max-width:   75%;
            padding:     var(--space-2) var(--space-3);
            border-radius: var(--radius-lg);
            font-size:   var(--font-size-base);
            line-height: var(--line-height-normal);
            word-wrap:   break-word;
            white-space: pre-wrap;   /* Preserve line breaks the user typed */
            position:    relative;
        }

        .msg-row.sent .msg-bubble {
            background:        var(--bubble-sent);
            color:              var(--bubble-sent-text);
            border-bottom-right-radius: 4px;
        }
        .msg-row.received .msg-bubble {
            background:        var(--bubble-received);
            color:              var(--bubble-received-text);
            box-shadow:         var(--shadow-xs);
            border-bottom-left-radius: 4px;
        }

        .msg-meta {
            display:     flex;
            align-items: center;
            gap:         3px;
            font-size:   10px;
            margin-top:  3px;
            opacity:     0.7;
        }
        .msg-row.sent .msg-meta     { justify-content: flex-end; }
        .msg-row.received .msg-meta { justify-content: flex-start; }

        /* Read receipt check icon */
        .read-tick { display: inline-flex; }
        .read-tick.read svg { color: var(--color-secondary); }

        /* ── Composer (Input Bar) ────────────────────────────────────── */
        .composer {
            position:      fixed;
            bottom:        0;
            left:          50%;
            transform:     translateX(-50%);
            width:         100%;
            max-width:     var(--max-width-app);
            background:    var(--color-surface);
            border-top:    1px solid var(--color-border);
            padding:       var(--space-2) var(--space-3);
            padding-bottom: calc(var(--space-2) + env(safe-area-inset-bottom, 0px));
            display:       flex;
            align-items:   flex-end;
            gap:           var(--space-2);
            z-index:       var(--z-sticky);
        }

        .composer-input {
            flex:          1;
            min-height:    44px;
            max-height:    120px;
            padding:       var(--space-3) var(--space-4);
            background:    var(--color-bg);
            border:        1.5px solid var(--color-border);
            border-radius: var(--radius-xl);
            font-size:     var(--font-size-base);
            font-family:   var(--font-family);
            line-height:   1.4;
            resize:        none;
            overflow-y:    auto;
            transition:    border-color var(--transition-fast);
        }
        .composer-input:focus {
            border-color: var(--color-primary);
            outline:      none;
        }
        .composer-input::placeholder { color: var(--color-text-muted); }

        .composer-send {
            width:           44px;
            height:          44px;
            border-radius:   50%;
            background:      var(--color-primary);
            color:           white;
            display:         flex;
            align-items:     center;
            justify-content: center;
            flex-shrink:     0;
            transition:      all var(--transition-fast);
        }
        .composer-send:hover { background: var(--color-primary-hover); }
        .composer-send:active { transform: scale(0.92); }
        .composer-send:disabled {
            background: var(--color-border);
            cursor:     not-allowed;
        }
        .composer-send svg { width: 18px; height: 18px; }

        /* ── Image Messages ──────────────────────────────────────────── */
        .msg-image {
            max-width:     260px;
            max-height:    320px;
            border-radius: var(--radius-md);
            cursor:        pointer;
            display:       block;
        }
        .msg-row.sent .msg-bubble.has-image,
        .msg-row.received .msg-bubble.has-image {
            padding: 4px;
            background: transparent;
            box-shadow: none;
        }

        /* ── Pending Image Preview (before sending) ──────────────────── */
        .image-preview-bar {
            display:    none;
            align-items: center;
            gap:        var(--space-3);
            padding:    var(--space-2) var(--space-3);
            background: var(--color-bg);
            border-top: 1px solid var(--color-border);
        }
        .image-preview-bar.active { display: flex; }
        .image-preview-thumb {
            width:         48px;
            height:        48px;
            border-radius: var(--radius-sm);
            object-fit:    cover;
        }
        .image-preview-info {
            flex: 1;
            font-size: var(--font-size-sm);
            color: var(--color-text-secondary);
        }
        .image-preview-remove {
            color: var(--color-error);
            width: 32px; height: 32px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .image-preview-remove:hover { background: var(--color-border); }

        /* ── Lightbox (full-size image viewer) ───────────────────────── */
        .lightbox {
            position:   fixed;
            inset:      0;
            background: rgba(0,0,0,0.9);
            z-index:    var(--z-overlay);
            display:    none;
            align-items: center;
            justify-content: center;
            padding:    var(--space-6);
        }
        .lightbox.open { display: flex; }
        .lightbox img {
            max-width:  100%;
            max-height: 100%;
            border-radius: var(--radius-md);
        }
        .lightbox-close {
            position: absolute;
            top: var(--space-5);
            right: var(--space-5);
            color: white;
            width: 40px; height: 40px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
        }

        /* ── Typing indicator (visual only — no backend yet) ─────────── */

        /* ── Typing indicator (visual only — no backend yet) ─────────── */
        .typing-bubble {
            display:     inline-flex;
            gap:         3px;
            padding:     var(--space-3);
        }
        .typing-bubble span {
            width:         6px;
            height:        6px;
            background:    var(--color-text-muted);
            border-radius: 50%;
            animation:     pulse 1.2s ease-in-out infinite;
        }
        .typing-bubble span:nth-child(2) { animation-delay: 0.2s; }
        .typing-bubble span:nth-child(3) { animation-delay: 0.4s; }
    </style>
</head>
<body>
<div class="app-shell">

    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
         HEADER
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <header class="page-header">
        <a href="<?= APP_URL ?>/pages/home.php"
           class="page-header-action" aria-label="Back to chats">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>

        <div class="chat-header-user">
            <div class="avatar-wrap">
                <img
                    src="<?= htmlspecialchars($other_avatar_url) ?>"
                    alt="<?= htmlspecialchars($conv['other_display_name']) ?>"
                    class="avatar avatar-sm"
                    onerror="this.src='<?= APP_URL ?>/assets/images/avatars/default.svg'"
                >
                <?php if ($conv['is_online']): ?>
                    <span class="online-dot" id="headerOnlineDot" aria-label="Online"></span>
                <?php endif; ?>
            </div>
            <div class="chat-header-info">
                <div class="chat-header-name">
                    <?= htmlspecialchars($conv['other_display_name']) ?>
                </div>
                <div class="chat-header-status <?= $conv['is_online'] ? 'online' : '' ?>"
                     id="headerStatus">
                    <?= $conv['is_online'] ? 'Online' : 'Offline' ?>
                </div>
            </div>
        </div>
    </header>

    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
         MESSAGES
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <main class="messages-area" id="messagesArea">

        <?php if (empty($messages)): ?>
            <div class="empty-state" style="margin: auto;">
                <div class="empty-state-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                </div>
                <h3 class="empty-state-title">No messages yet</h3>
                <p class="empty-state-text">
                    Say hello to <?= htmlspecialchars($conv['other_display_name']) ?>!
                </p>
            </div>
        <?php else: ?>
            <!-- Rendered server-side for instant first paint.
                 id="initialMessages" lets JS know not to re-render these. -->
            <div id="initialMessages"
                 data-rendered="true"></div>
        <?php endif; ?>

    </main>

    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
         PENDING IMAGE PREVIEW (shown above composer when an image is picked)
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <div class="image-preview-bar" id="imagePreviewBar">
        <img class="image-preview-thumb" id="imagePreviewThumb" alt="Selected image">
        <span class="image-preview-info">Ready to send</span>
        <button class="image-preview-remove" id="imagePreviewRemove" aria-label="Remove image">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>

    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
         COMPOSER
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <div class="composer">
        <button class="page-header-action" id="composerImageBtn"
                aria-label="Attach image" title="Attach image"
                style="flex-shrink:0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21 15 16 10 5 21"/>
            </svg>
        </button>
        <input type="file" id="composerImageInput" accept="image/jpeg,image/png,image/webp"
               style="display:none;">

        <textarea
            class="composer-input"
            id="composerInput"
            placeholder="Message…"
            rows="1"
            maxlength="4000"
            aria-label="Type a message"
        ></textarea>
        <button class="composer-send" id="composerSend" aria-label="Send message" disabled>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <line x1="22" y1="2" x2="11" y2="13"/>
                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
        </button>
    </div>

</div><!-- /.app-shell -->

<!-- Lightbox — full-size image viewer -->
<div class="lightbox" id="lightbox">
    <button class="lightbox-close" id="lightboxClose" aria-label="Close">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
             viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
    </button>
    <img id="lightboxImg" src="" alt="Full size image">
</div>

<script src="<?= APP_URL ?>/assets/js/utils.js"></script>
<script src="<?= APP_URL ?>/assets/js/ui.js"></script>
<script src="<?= APP_URL ?>/assets/js/api.js"></script>
<script>
(function () {

    // ── Constants passed from PHP ────────────────────────────────────────
    const CONVERSATION_ID  = <?= (int) $conversation_id ?>;
    const CURRENT_USER_ID  = <?= (int) $me ?>;
    let   lastMessageId    = <?= (int) $last_message_id ?>;

    // Initial messages from PHP, passed as JSON so JS renders them
    // the exact same way it renders polled messages — one render path,
    // zero duplicated logic between "server HTML" and "client HTML".
    const initialMessages = <?= json_encode($messages, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // ── DOM references ─────────────────────────────────────────────────
    const messagesArea  = document.getElementById('messagesArea');
    const composerInput = document.getElementById('composerInput');
    const composerSend  = document.getElementById('composerSend');

    let pollTimer  = null;
    let isSending  = false;
    let lastRenderedDate = null;   // Tracks date dividers
    let lastRenderedSender = null; // Tracks message grouping

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 1 — Rendering
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Formats a MySQL datetime into a short clock time, e.g. "14:32".
     */
    function formatTime(dateString) {
        const d = new Date(dateString.replace(' ', 'T'));
        return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    }

    /**
     * Formats a date for the day divider, e.g. "Today", "Yesterday", "14 June".
     */
    function formatDateDivider(dateString) {
        const d     = new Date(dateString.replace(' ', 'T'));
        const today = new Date();
        const yest  = new Date();
        yest.setDate(yest.getDate() - 1);

        const sameDay = (a, b) =>
            a.toDateString() === b.toDateString();

        if (sameDay(d, today)) return 'Today';
        if (sameDay(d, yest))  return 'Yesterday';
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long' });
    }

    /**
     * Appends a single message to the DOM.
     * Handles date dividers and visual grouping of consecutive messages
     * from the same sender (tighter spacing, like real chat apps).
     *
     * @param {object} msg
     * @param {boolean} animate - whether to play the pop-in animation
     */
    function appendMessage(msg, animate = false) {
        const msgDateKey = msg.created_at.split(' ')[0]; // "YYYY-MM-DD"

        // ── Date divider ──────────────────────────────────────────────
        if (msgDateKey !== lastRenderedDate) {
            const divider = document.createElement('div');
            divider.className = 'date-divider';
            divider.innerHTML = `<span>${formatDateDivider(msg.created_at)}</span>`;
            messagesArea.appendChild(divider);
            lastRenderedDate   = msgDateKey;
            lastRenderedSender = null;   // Reset grouping after a date break
        }

        const isSent   = msg.sender_id === CURRENT_USER_ID;
        const grouped  = msg.sender_id === lastRenderedSender;

        const row = document.createElement('div');
        row.className = `msg-row ${isSent ? 'sent' : 'received'} ${grouped ? 'grouped' : ''}`;
        row.dataset.messageId = msg.id;

        // Read receipt — only shown on messages I sent
        const readTickHtml = isSent ? `
            <span class="read-tick ${msg.is_read ? 'read' : ''}">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5"
                     stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </span>
        ` : '';

        const isImage = msg.message_type === 'image' && msg.image_url;

        const bubbleHtml = isImage
            ? `<div class="msg-bubble has-image">
                   <img class="msg-image" src="${Utils.escapeHtml(msg.image_url)}"
                        alt="Sent image" loading="lazy">
               </div>`
            : `<div class="msg-bubble">${Utils.escapeHtml(msg.content)}</div>`;

        row.innerHTML = `
            <div class="${animate ? 'animate-message' : ''}" style="max-width:75%;">
                ${bubbleHtml}
                <div class="msg-meta">
                    <span>${formatTime(msg.created_at)}</span>
                    ${readTickHtml}
                </div>
            </div>
        `;

        messagesArea.appendChild(row);
        lastRenderedSender = msg.sender_id;
    }

    /**
     * Renders a batch of messages (used for both initial load and polling).
     */
    function renderMessages(messages, animate = false) {
        messages.forEach(msg => appendMessage(msg, animate));
    }

    /**
     * Scrolls the message area to the very bottom.
     */
    function scrollToBottom(smooth = true) {
        messagesArea.scrollTo({
            top: messagesArea.scrollHeight,
            behavior: smooth ? 'smooth' : 'instant'
        });
    }

    // ── Render initial messages on page load ─────────────────────────────
    if (initialMessages.length > 0) {
        renderMessages(initialMessages, false);
        // Instant (not smooth) scroll on first load — we want to land
        // at the bottom immediately, not animate down to it.
        requestAnimationFrame(() => scrollToBottom(false));
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 2 — Sending Messages
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Auto-resizes the textarea as the user types, up to a max height
     * (capped by CSS max-height + overflow-y: auto).
     */
    function autoResize() {
        composerInput.style.height = 'auto';
        composerInput.style.height = composerInput.scrollHeight + 'px';
    }

    composerInput.addEventListener('input', () => {
        autoResize();
        composerSend.disabled = composerInput.value.trim().length === 0;
    });

    /**
     * Sends the current composer content.
     * Uses an optimistic-ish flow: we disable input immediately so the
     * user can't double-send, but we wait for the server's response
     * (with the real message ID + timestamp) before rendering — this
     * keeps lastMessageId always accurate for polling.
     */
    async function sendMessage() {
        const content = composerInput.value.trim();
        const imageFile = composerImageInput.files[0] || null;

        if (!content && !imageFile) return;
        if (isSending) return;

        isSending = true;
        composerSend.disabled = true;
        composerInput.disabled = true;
        composerImageBtn.disabled = true;

        // Build the request body manually (FormData) since this can now
        // include a binary file, not just text fields.
        const fd = new FormData();
        fd.append('conversation_id', CONVERSATION_ID);
        fd.append('content', content);
        if (imageFile) fd.append('image', imageFile);

        const result = await API.request('/loop/api/messages/send.php', {
            method: 'POST',
            body:   fd
        });

        composerInput.disabled = false;
        composerImageBtn.disabled = false;
        isSending = false;

        if (result.success) {
            composerInput.value = '';
            autoResize();
            clearImagePreview();

            const msg = result.data.message;
            appendMessage(msg, true);   // animate = true, pops in
            lastMessageId = msg.id;
            scrollToBottom(true);
        } else {
            UI.toast(result.message || 'Could not send message.', 'error');
            composerSend.disabled = false;
        }

        composerInput.focus();
    }

    composerSend.addEventListener('click', sendMessage);

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 2b — Image Picker
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    const composerImageBtn   = document.getElementById('composerImageBtn');
    const composerImageInput = document.getElementById('composerImageInput');
    const imagePreviewBar    = document.getElementById('imagePreviewBar');
    const imagePreviewThumb  = document.getElementById('imagePreviewThumb');
    const imagePreviewRemove = document.getElementById('imagePreviewRemove');

    composerImageBtn.addEventListener('click', () => composerImageInput.click());

    composerImageInput.addEventListener('change', () => {
        const file = composerImageInput.files[0];
        if (!file) return;

        if (file.size > 5 * 1024 * 1024) {
            UI.toast('Image must be under 5MB.', 'error');
            composerImageInput.value = '';
            return;
        }

        // Local preview — no upload yet, just shows what's about to be sent
        const reader = new FileReader();
        reader.onload = (e) => {
            imagePreviewThumb.src = e.target.result;
            imagePreviewBar.classList.add('active');
        };
        reader.readAsDataURL(file);

        // Allow sending with an image even if the text field is empty
        composerSend.disabled = false;
    });

    function clearImagePreview() {
        composerImageInput.value = '';
        imagePreviewThumb.src = '';
        imagePreviewBar.classList.remove('active');
        composerSend.disabled = composerInput.value.trim().length === 0;
    }

    imagePreviewRemove.addEventListener('click', clearImagePreview);

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 2c — Lightbox (tap an image to view full size)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    const lightbox      = document.getElementById('lightbox');
    const lightboxImg    = document.getElementById('lightboxImg');
    const lightboxClose  = document.getElementById('lightboxClose');

    // Event delegation — handles clicks on any .msg-image, including
    // ones rendered later by polling, without needing per-image listeners.
    messagesArea.addEventListener('click', (e) => {
        const img = e.target.closest('.msg-image');
        if (!img) return;
        lightboxImg.src = img.src;
        lightbox.classList.add('open');
    });

    lightboxClose.addEventListener('click', () => lightbox.classList.remove('open'));
    lightbox.addEventListener('click', (e) => {
        if (e.target === lightbox) lightbox.classList.remove('open');
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') lightbox.classList.remove('open');
    });

    // Enter sends, Shift+Enter inserts a newline
    composerInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 3 — Polling for New Messages
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Checks whether the user is scrolled near the bottom.
     * If they've scrolled up to read old messages, we don't want to
     * yank them back down when a new message silently arrives — but
     * for this version we keep it simple and always scroll on new
     * incoming messages, matching common chat app behaviour.
     */
    function isNearBottom() {
        const threshold = 120;
        return messagesArea.scrollHeight - messagesArea.scrollTop - messagesArea.clientHeight < threshold;
    }

    /**
     * Updates the chat header's online dot and status label to reflect
     * the other user's current presence, without a page reload.
     *
     * @param {boolean} isOnline
     */
    function updateHeaderStatus(isOnline) {
        const statusEl = document.getElementById('headerStatus');
        const avatarWrap = document.querySelector('.chat-header-user .avatar-wrap');
        let dot = document.getElementById('headerOnlineDot');

        statusEl.textContent = isOnline ? 'Online' : 'Offline';
        statusEl.classList.toggle('online', isOnline);

        if (isOnline && !dot) {
            // Add the dot if it doesn't exist yet
            dot = document.createElement('span');
            dot.className = 'online-dot';
            dot.id = 'headerOnlineDot';
            dot.setAttribute('aria-label', 'Online');
            avatarWrap.appendChild(dot);
        } else if (!isOnline && dot) {
            // Remove the dot if the user has gone offline
            dot.remove();
        }
    }

    /**
     * Updates read-receipt ticks on my own previously-sent messages
     * based on the full list of currently-read message IDs returned
     * by the server on every poll.
     *
     * @param {number[]} readMessageIds
     */
    function updateReadReceipts(readMessageIds) {
        if (!readMessageIds || readMessageIds.length === 0) return;

        readMessageIds.forEach(id => {
            const row = messagesArea.querySelector(`[data-message-id="${id}"]`);
            if (!row) return;

            const tick = row.querySelector('.read-tick');
            if (tick && !tick.classList.contains('read')) {
                tick.classList.add('read');
            }
        });
    }

    async function poll() {
        // Don't poll while the tab is hidden — saves requests and battery
        if (document.hidden) return;

        const result = await API.fetchMessages(CONVERSATION_ID, lastMessageId);

        if (!result.success) return;   // Fail silently — next poll will retry

        const newMessages    = result.data.messages;
        const readMessageIds = result.data.read_message_ids || [];
        const otherIsOnline  = result.data.other_is_online;

        // Always check for read-receipt updates, even if no new messages
        // arrived — the other person reading an old message doesn't
        // produce a "new message," only a status change on an old one.
        updateReadReceipts(readMessageIds);

        // Always refresh the header's online/offline indicator too —
        // this can change independently of any message activity.
        updateHeaderStatus(otherIsOnline);

        if (newMessages.length === 0) return;

        const wasNearBottom = isNearBottom();

        renderMessages(newMessages, true);
        lastMessageId = newMessages[newMessages.length - 1].id;

        // Only auto-scroll if the user was already near the bottom —
        // respects someone who's scrolled up to read history.
        if (wasNearBottom) {
            scrollToBottom(true);
        } else {
            UI.toast('New message', 'info', 2000);
        }
    }

    // Poll on the interval defined in constants.php (3000ms default)
    Poller.start(<?= POLLING_INTERVAL ?>, poll);

})();
</script>

</body>
</html>