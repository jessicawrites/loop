<?php
/**
 * Loop — Profile Page
 *
 * Renders the current user's profile with editing capability.
 * Stats (conversation count, member since) are fetched server-side.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

$me   = current_user_id();
$pdo  = get_db();

// ── Fetch full user record ─────────────────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT id, username, display_name, email, avatar, bio, created_at
    FROM   users
    WHERE  id = ?
    LIMIT  1
');
$stmt->execute([$me]);
$user = $stmt->fetch();

// ── Fetch conversation count ───────────────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT COUNT(*) FROM conversation_members WHERE user_id = ?
');
$stmt->execute([$me]);
$conv_count = (int) $stmt->fetchColumn();

// ── Format member since ────────────────────────────────────────────────────
$member_since = (new DateTime($user['created_at']))->format('F Y');

// ── Build avatar URL ───────────────────────────────────────────────────────
$avatar_url = avatar_url($user['avatar']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Profile — Loop</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
    <script>
        const t = localStorage.getItem('loop_theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
        window.CSRF_TOKEN = '<?= csrf_token() ?>';
    </script>

    <style>
        /* ── Profile Header Block ────────────────────────────────────── */
        .profile-hero {
            display:        flex;
            flex-direction: column;
            align-items:    center;
            padding:        var(--space-8) var(--space-6) var(--space-6);
            border-bottom:  1px solid var(--color-border);
            background:     var(--color-surface);
            gap:            var(--space-4);
        }

        /* Avatar upload wrapper — the whole circle is tappable */
        .avatar-upload-wrap {
            position: relative;
            cursor:   pointer;
            display:  inline-block;
        }

        .avatar-upload-wrap:hover .avatar-overlay,
        .avatar-upload-wrap:focus-within .avatar-overlay {
            opacity: 1;
        }

        /* Semi-transparent overlay with camera icon */
        .avatar-overlay {
            position:        absolute;
            inset:           0;
            border-radius:   50%;
            background:      rgba(0, 0, 0, 0.45);
            display:         flex;
            align-items:     center;
            justify-content: center;
            opacity:         0;
            transition:      opacity var(--transition-fast);
            color:           white;
        }

        .avatar-upload-input {
            position: absolute;
            inset:    0;
            opacity:  0;
            cursor:   pointer;
            width:    100%;
            height:   100%;
            border-radius: 50%;
        }

        .profile-name {
            font-size:   var(--font-size-xl);
            font-weight: var(--font-weight-bold);
            text-align:  center;
        }

        .profile-username {
            font-size:  var(--font-size-sm);
            color:      var(--color-text-secondary);
            margin-top: calc(-1 * var(--space-3));
        }

        .profile-bio {
            font-size:   var(--font-size-sm);
            color:       var(--color-text-secondary);
            text-align:  center;
            max-width:   280px;
            line-height: var(--line-height-relaxed);
        }

        /* ── Stats Row ───────────────────────────────────────────────── */
        .profile-stats {
            display:         flex;
            justify-content: center;
            gap:             var(--space-8);
            padding:         var(--space-5) var(--space-6);
            border-bottom:   1px solid var(--color-border);
            background:      var(--color-surface);
        }

        .stat-item {
            display:        flex;
            flex-direction: column;
            align-items:    center;
            gap:            2px;
        }
        .stat-value {
            font-size:   var(--font-size-xl);
            font-weight: var(--font-weight-bold);
            color:       var(--color-primary);
        }
        .stat-label {
            font-size: var(--font-size-xs);
            color:     var(--color-text-muted);
        }

        /* ── Settings Sections ───────────────────────────────────────── */
        .settings-section {
            padding:       var(--space-5) var(--space-4) var(--space-2);
        }

        .settings-section-title {
            font-size:     var(--font-size-xs);
            font-weight:   var(--font-weight-semibold);
            color:         var(--color-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding:       0 var(--space-2);
            margin-bottom: var(--space-2);
        }

        .settings-card {
            background:    var(--color-surface);
            border-radius: var(--radius-lg);
            overflow:      hidden;
            box-shadow:    var(--shadow-xs);
        }

        /* Each row inside a settings card */
        .settings-row {
            display:      flex;
            align-items:  center;
            gap:          var(--space-3);
            padding:      var(--space-4);
            border-bottom: 1px solid var(--color-border);
            transition:   background var(--transition-fast);
        }
        .settings-row:last-child { border-bottom: none; }
        .settings-row.tappable { cursor: pointer; }
        .settings-row.tappable:hover  { background: var(--color-bg); }
        .settings-row.tappable:active { background: var(--color-border); }

        .settings-row-icon {
            width:           36px;
            height:          36px;
            border-radius:   var(--radius-sm);
            background:      var(--color-primary-light);
            color:           var(--color-primary);
            display:         flex;
            align-items:     center;
            justify-content: center;
            flex-shrink:     0;
        }
        .settings-row-icon.red   {
            background: #FEE2E2;
            color:      var(--color-error);
        }
        .settings-row-icon.teal  {
            background: #CCFBF1;
            color:      var(--color-secondary);
        }
        .settings-row-icon.amber {
            background: #FEF3C7;
            color:      var(--color-warning);
        }

        .settings-row-body { flex: 1; min-width: 0; }
        .settings-row-title {
            font-size:   var(--font-size-base);
            font-weight: var(--font-weight-medium);
        }
        .settings-row-sub {
            font-size:  var(--font-size-xs);
            color:      var(--color-text-muted);
            margin-top: 2px;
        }

        .settings-chevron { color: var(--color-text-muted); flex-shrink: 0; }

        /* ── Edit Drawer ─────────────────────────────────────────────── */
        /* Slides up from the bottom like a native mobile sheet */
        .drawer-backdrop {
            position:   fixed;
            inset:      0;
            background: rgba(0,0,0,0.4);
            z-index:    var(--z-modal);
            opacity:    0;
            pointer-events: none;
            transition: opacity var(--transition-normal);
        }
        .drawer-backdrop.open {
            opacity:        1;
            pointer-events: auto;
        }

        .drawer {
            position:       fixed;
            bottom:         0;
            left:           50%;
            transform:      translateX(-50%) translateY(100%);
            width:          100%;
            max-width:      var(--max-width-app);
            background:     var(--color-surface);
            border-radius:  var(--radius-xl) var(--radius-xl) 0 0;
            z-index:        calc(var(--z-modal) + 1);
            padding:        var(--space-2) var(--space-6)
                            calc(var(--space-8) + env(safe-area-inset-bottom, 0px));
            transition:     transform var(--transition-normal);
            max-height:     90vh;
            overflow-y:     auto;
        }
        .drawer.open {
            transform: translateX(-50%) translateY(0);
        }

        /* Drag handle visual indicator */
        .drawer-handle {
            width:         40px;
            height:        4px;
            background:    var(--color-border);
            border-radius: var(--radius-full);
            margin:        var(--space-3) auto var(--space-5);
        }

        .drawer-title {
            font-size:     var(--font-size-lg);
            font-weight:   var(--font-weight-semibold);
            margin-bottom: var(--space-5);
        }

        .drawer-form {
            display:        flex;
            flex-direction: column;
            gap:            var(--space-4);
        }

        /* Bio textarea */
        .textarea-field {
            width:         100%;
            padding:       var(--space-3) var(--space-4);
            background:    var(--color-surface);
            border:        1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            color:         var(--color-text-primary);
            font-size:     var(--font-size-base);
            font-family:   var(--font-family);
            line-height:   var(--line-height-relaxed);
            resize:        vertical;
            min-height:    90px;
            transition:    all var(--transition-fast);
        }
        .textarea-field::placeholder { color: var(--color-text-muted); }
        .textarea-field:focus {
            border-color: var(--color-primary);
            box-shadow:   0 0 0 3px rgba(37,99,235,0.12);
            outline:      none;
        }

        .char-count {
            text-align:  right;
            font-size:   var(--font-size-xs);
            color:       var(--color-text-muted);
            margin-top:  calc(-1 * var(--space-2));
        }
        .char-count.near-limit { color: var(--color-warning); }
        .char-count.at-limit   { color: var(--color-error); }
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
        <span class="page-header-title">Profile</span>
    </header>

    <!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
         SCROLLABLE CONTENT
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
    <div class="page-content" style="height: calc(100vh - var(--header-height) - var(--nav-height));">

        <!-- ── Hero block ──────────────────────────────────────────────── -->
        <div class="profile-hero animate-fade-in">

            <!-- Avatar (tappable — opens file picker) -->
            <div class="avatar-upload-wrap" title="Change profile photo">
                <img
                    src="<?= htmlspecialchars($avatar_url) ?>"
                    alt="Your avatar"
                    class="avatar avatar-2xl"
                    id="avatarPreview"
                >
                <div class="avatar-overlay" aria-hidden="true">
                    <!-- Camera icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8
                                 a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                        <circle cx="12" cy="13" r="4"/>
                    </svg>
                </div>
                <!-- Hidden file input triggered by clicking the avatar -->
                <input
                    type="file"
                    id="avatarInput"
                    class="avatar-upload-input"
                    accept="image/jpeg,image/png,image/webp"
                    aria-label="Upload profile photo"
                >
            </div>

            <!-- Name and username -->
            <div style="text-align:center;">
                <h1 class="profile-name" id="profileName">
                    <?= htmlspecialchars($user['display_name']) ?>
                </h1>
                <p class="profile-username">
                    @<?= htmlspecialchars($user['username']) ?>
                </p>
            </div>

            <!-- Bio (only shown if set) -->
            <?php if (!empty($user['bio'])): ?>
            <p class="profile-bio" id="profileBio">
                <?= htmlspecialchars($user['bio']) ?>
            </p>
            <?php else: ?>
            <p class="profile-bio" id="profileBio"
               style="color:var(--color-text-muted); font-style:italic;">
                No bio yet.
            </p>
            <?php endif; ?>

        </div>

        <!-- ── Stats ───────────────────────────────────────────────────── -->
        <div class="profile-stats">
            <div class="stat-item">
                <span class="stat-value"><?= $conv_count ?></span>
                <span class="stat-label">Chats</span>
            </div>
            <div class="stat-item">
                <span class="stat-value">
                    <?= htmlspecialchars($member_since) ?>
                </span>
                <span class="stat-label">Member since</span>
            </div>
        </div>

        <!-- ── Settings: Account ───────────────────────────────────────── -->
        <div class="settings-section">
            <p class="settings-section-title">Account</p>
            <div class="settings-card">

                <!-- Edit profile row -->
                <div class="settings-row tappable" id="openEditBtn" role="button"
                     tabindex="0" aria-label="Edit display name and bio">
                    <div class="settings-row-icon teal">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14
                                     a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                    </div>
                    <div class="settings-row-body">
                        <div class="settings-row-title">Edit Profile</div>
                        <div class="settings-row-sub">
                            Display name and bio
                        </div>
                    </div>
                    <svg class="settings-chevron" xmlns="http://www.w3.org/2000/svg"
                         width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </div>

                <!-- Change password row -->
                <div class="settings-row tappable" id="openPasswordBtn" role="button"
                     tabindex="0" aria-label="Change password">
                    <div class="settings-row-icon amber">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11"
                                  rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </div>
                    <div class="settings-row-body">
                        <div class="settings-row-title">Change Password</div>
                        <div class="settings-row-sub">
                            Update your login password
                        </div>
                    </div>
                    <svg class="settings-chevron" xmlns="http://www.w3.org/2000/svg"
                         width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </div>

                <!-- Email (read-only) -->
                <div class="settings-row">
                    <div class="settings-row-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4
                                     c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                            <polyline points="22,6 12,13 2,6"/>
                        </svg>
                    </div>
                    <div class="settings-row-body">
                        <div class="settings-row-title">Email</div>
                        <div class="settings-row-sub">
                            <?= htmlspecialchars($user['email']) ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Settings: Preferences ───────────────────────────────────── -->
        <div class="settings-section">
            <p class="settings-section-title">Preferences</p>
            <div class="settings-card">

                <!-- Dark mode toggle -->
                <div class="settings-row tappable" id="themeToggleBtn"
                     role="button" tabindex="0" aria-label="Toggle dark mode">
                    <div class="settings-row-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3
                                     7 7 0 0 0 21 12.79z"/>
                        </svg>
                    </div>
                    <div class="settings-row-body">
                        <div class="settings-row-title">Dark Mode</div>
                        <div class="settings-row-sub" id="themeLabel">
                            Currently: Light
                        </div>
                    </div>
                    <!-- Toggle pill -->
                    <div id="themeTogglePill" style="
                        width: 44px; height: 24px;
                        border-radius: var(--radius-full);
                        background: var(--color-border);
                        position: relative;
                        transition: background var(--transition-fast);
                        flex-shrink: 0;
                    ">
                        <div id="themeToggleKnob" style="
                            position: absolute;
                            top: 2px; left: 2px;
                            width: 20px; height: 20px;
                            border-radius: 50%;
                            background: white;
                            box-shadow: var(--shadow-sm);
                            transition: transform var(--transition-fast);
                        "></div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Settings: Danger Zone ───────────────────────────────────── -->
        <div class="settings-section">
            <p class="settings-section-title">Session</p>
            <div class="settings-card">
                <div class="settings-row tappable" id="logoutBtn"
                     role="button" tabindex="0" aria-label="Sign out">
                    <div class="settings-row-icon red">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                    </div>
                    <div class="settings-row-body">
                        <div class="settings-row-title"
                             style="color:var(--color-error);">
                            Sign Out
                        </div>
                        <div class="settings-row-sub">
                            <?= htmlspecialchars($user['username']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom padding so last card clears the nav bar -->
        <div style="height: var(--space-8);"></div>

    </div><!-- /.page-content -->

    <!-- ── Bottom Navigation ───────────────────────────────────────────── -->
    <nav class="bottom-nav" aria-label="Main navigation">
        <a href="<?= APP_URL ?>/pages/home.php"
           class="nav-item" aria-label="Chats">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14
                         a2 2 0 0 0 2 2z"/>
            </svg>
            <span class="nav-label">Chats</span>
        </a>
        <a href="<?= APP_URL ?>/pages/profile.php"
           class="nav-item active" aria-label="Profile">
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


<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     DRAWER: Edit Profile
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<div class="drawer-backdrop" id="editBackdrop"></div>
<div class="drawer" id="editDrawer" role="dialog" aria-label="Edit profile">
    <div class="drawer-handle"></div>
    <h2 class="drawer-title">Edit Profile</h2>

    <form class="drawer-form" id="editProfileForm" novalidate>

        <div class="input-group">
            <label class="input-label" for="editDisplayName">Display Name</label>
            <input
                class="input-field"
                type="text"
                id="editDisplayName"
                name="display_name"
                value="<?= htmlspecialchars($user['display_name']) ?>"
                maxlength="50"
                required
            >
        </div>

        <div class="input-group">
            <label class="input-label" for="editBio">Bio</label>
            <textarea
                class="textarea-field"
                id="editBio"
                name="bio"
                maxlength="160"
                placeholder="Write something about yourself…"
            ><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
            <div class="char-count" id="bioCharCount">
                <?= mb_strlen($user['bio'] ?? '') ?>/160
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-full" id="saveProfileBtn">
            Save Changes
        </button>
        <button type="button" class="btn btn-secondary btn-full"
                id="cancelEditBtn">
            Cancel
        </button>

    </form>
</div>


<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     DRAWER: Change Password
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<div class="drawer-backdrop" id="passwordBackdrop"></div>
<div class="drawer" id="passwordDrawer" role="dialog" aria-label="Change password">
    <div class="drawer-handle"></div>
    <h2 class="drawer-title">Change Password</h2>

    <form class="drawer-form" id="changePasswordForm" novalidate>

        <div class="input-group">
            <label class="input-label" for="currentPassword">
                Current Password
            </label>
            <input class="input-field" type="password" id="currentPassword"
                   name="current_password" placeholder="Your current password"
                   autocomplete="current-password">
        </div>

        <div class="input-group">
            <label class="input-label" for="newPassword">New Password</label>
            <input class="input-field" type="password" id="newPassword"
                   name="new_password" placeholder="At least 8 characters"
                   autocomplete="new-password">
        </div>

        <div class="input-group">
            <label class="input-label" for="confirmNewPassword">
                Confirm New Password
            </label>
            <input class="input-field" type="password" id="confirmNewPassword"
                   name="confirm_password" placeholder="Repeat new password"
                   autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary btn-full"
                id="savePasswordBtn">
            Update Password
        </button>
        <button type="button" class="btn btn-secondary btn-full"
                id="cancelPasswordBtn">
            Cancel
        </button>

    </form>
</div>


<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     SCRIPTS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<script src="<?= APP_URL ?>/assets/js/utils.js"></script>
<script src="<?= APP_URL ?>/assets/js/ui.js"></script>
<script src="<?= APP_URL ?>/assets/js/api.js"></script>
<script>
(function () {

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 1 — Drawer helpers
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Opens a drawer and its backdrop.
     * @param {string} drawerId    - e.g. 'editDrawer'
     * @param {string} backdropId  - e.g. 'editBackdrop'
     */
    function openDrawer(drawerId, backdropId) {
        document.getElementById(drawerId).classList.add('open');
        document.getElementById(backdropId).classList.add('open');
        document.body.style.overflow = 'hidden';  // Prevent background scroll
    }

    function closeDrawer(drawerId, backdropId) {
        document.getElementById(drawerId).classList.remove('open');
        document.getElementById(backdropId).classList.remove('open');
        document.body.style.overflow = '';
    }

    // Edit profile drawer
    document.getElementById('openEditBtn').addEventListener('click', () => {
        openDrawer('editDrawer', 'editBackdrop');
    });
    document.getElementById('openEditBtn').addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') openDrawer('editDrawer', 'editBackdrop');
    });
    document.getElementById('cancelEditBtn').addEventListener('click', () => {
        closeDrawer('editDrawer', 'editBackdrop');
    });
    document.getElementById('editBackdrop').addEventListener('click', () => {
        closeDrawer('editDrawer', 'editBackdrop');
    });

    // Password drawer
    document.getElementById('openPasswordBtn').addEventListener('click', () => {
        openDrawer('passwordDrawer', 'passwordBackdrop');
    });
    document.getElementById('openPasswordBtn').addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') openDrawer('passwordDrawer', 'passwordBackdrop');
    });
    document.getElementById('cancelPasswordBtn').addEventListener('click', () => {
        closeDrawer('passwordDrawer', 'passwordBackdrop');
    });
    document.getElementById('passwordBackdrop').addEventListener('click', () => {
        closeDrawer('passwordDrawer', 'passwordBackdrop');
    });

    // Close any drawer on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        closeDrawer('editDrawer',     'editBackdrop');
        closeDrawer('passwordDrawer', 'passwordBackdrop');
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 2 — Bio character counter
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    const bioField     = document.getElementById('editBio');
    const bioCharCount = document.getElementById('bioCharCount');

    bioField.addEventListener('input', () => {
        const len = bioField.value.length;
        bioCharCount.textContent = `${len}/160`;
        bioCharCount.classList.toggle('near-limit', len >= 130 && len < 160);
        bioCharCount.classList.toggle('at-limit',   len >= 160);
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 3 — Avatar upload with live preview
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    const avatarInput   = document.getElementById('avatarInput');
    const avatarPreview = document.getElementById('avatarPreview');

    // When the user picks a file, show a preview immediately
    // AND auto-upload it — no need to click Save separately.
    avatarInput.addEventListener('change', async () => {
        const file = avatarInput.files[0];
        if (!file) return;

        // ── Client-side size check ─────────────────────────────────────
        if (file.size > 2 * 1024 * 1024) {
            UI.toast('Image must be under 2MB.', 'error');
            avatarInput.value = '';
            return;
        }

        // ── Live preview using FileReader ──────────────────────────────
        // FileReader reads the file locally — no server needed for preview.
        const reader = new FileReader();
        reader.onload = (e) => {
            avatarPreview.src = e.target.result;
            avatarPreview.style.animation = 'scaleIn 0.3s ease';
        };
        reader.readAsDataURL(file);

        // ── Auto-upload ────────────────────────────────────────────────
        // We send just the avatar field — display_name must be included
        // since the API always updates it.
        const fd = new FormData();
        fd.append('avatar',       file);
        fd.append('display_name',
            document.getElementById('editDisplayName').value ||
            '<?= htmlspecialchars($user['display_name']) ?>');
        fd.append('bio', bioField.value);

        UI.toast('Uploading photo…', 'info', 2000);

        const result = await API.updateProfile(fd);

        if (result.success) {
            UI.toast('Profile photo updated.', 'success');
        } else {
            UI.toast(result.message, 'error');
            // Revert preview on failure
            avatarPreview.src = '<?= htmlspecialchars($avatar_url) ?>';
        }

        avatarInput.value = '';  // Reset input so same file can be re-selected
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 4 — Edit profile form
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    document.getElementById('editProfileForm')
        .addEventListener('submit', async (e) => {
        e.preventDefault();

        const displayName = document.getElementById('editDisplayName').value.trim();
        const bio         = bioField.value.trim();

        if (!displayName) {
            UI.toast('Display name cannot be empty.', 'error');
            return;
        }

        const fd = new FormData();
        fd.append('display_name', displayName);
        fd.append('bio',          bio);

        const restore = UI.setLoading(
            document.getElementById('saveProfileBtn'), 'Saving…'
        );

        const result = await API.updateProfile(fd);
        restore();

        if (result.success) {
            // Update the visible name and bio on the page without a reload
            document.getElementById('profileName').textContent = displayName;
            const bioEl = document.getElementById('profileBio');
            bioEl.textContent   = bio || 'No bio yet.';
            bioEl.style.cssText = bio ? '' : 'color:var(--color-text-muted);font-style:italic;';

            closeDrawer('editDrawer', 'editBackdrop');
            UI.toast(result.message, 'success');
        } else {
            UI.toast(result.message, 'error');
        }
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 5 — Change password form
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    document.getElementById('changePasswordForm')
        .addEventListener('submit', async (e) => {
        e.preventDefault();

        const current = document.getElementById('currentPassword').value;
        const newPwd  = document.getElementById('newPassword').value;
        const confirm = document.getElementById('confirmNewPassword').value;

        if (!current) {
            UI.toast('Enter your current password.', 'error'); return;
        }
        if (newPwd.length < 8) {
            UI.toast('New password must be at least 8 characters.', 'error'); return;
        }
        if (newPwd !== confirm) {
            UI.toast('New passwords do not match.', 'error'); return;
        }

        const fd = new FormData();
        fd.append('current_password', current);
        fd.append('new_password',     newPwd);
        fd.append('confirm_password', confirm);

        const restore = UI.setLoading(
            document.getElementById('savePasswordBtn'), 'Updating…'
        );

        const result = await fetch('/loop/api/users/password.php', {
            method:      'POST',
            headers:     { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body:        fd
        }).then(r => r.json());

        restore();

        if (result.success) {
            document.getElementById('changePasswordForm').reset();
            closeDrawer('passwordDrawer', 'passwordBackdrop');
            UI.toast(result.message, 'success');
        } else {
            UI.toast(result.message, 'error');
        }
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 6 — Dark mode toggle
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    const themeBtn   = document.getElementById('themeToggleBtn');
    const themeLabel = document.getElementById('themeLabel');
    const pill       = document.getElementById('themeTogglePill');
    const knob       = document.getElementById('themeToggleKnob');

    function syncThemeUI() {
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        themeLabel.textContent    = `Currently: ${dark ? 'Dark' : 'Light'}`;
        pill.style.background     = dark ? 'var(--color-primary)' : 'var(--color-border)';
        knob.style.transform      = dark ? 'translateX(20px)' : 'translateX(0)';
    }

    syncThemeUI();  // Set correct state on load

    themeBtn.addEventListener('click', () => {
        // App.toggleTheme() is defined in app.js
        // We load it via a small inline call here since app.js
        // isn't loaded on this page yet — we inline the logic:
        const current = document.documentElement.getAttribute('data-theme');
        const next    = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('loop_theme', next);
        syncThemeUI();
    });

    themeBtn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') themeBtn.click();
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SECTION 7 — Logout
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    document.getElementById('logoutBtn').addEventListener('click', async () => {
        const result = await API.logout();
        if (result.success) {
            window.location.href = result.data.redirect;
        }
    });

})();
</script>

</body>
</html>