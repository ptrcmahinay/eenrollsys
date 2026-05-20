<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
$user = current_user();
$currentTerm = current_term();
$user_role = $user['role'] ?? 'student';
$flashes = get_flashes();
$show_sidebar = $show_sidebar ?? true;

$inlineNotifs = [];
$inlineNotifCount = 0;
$inlineEntityId = 0;
if ($user_role === 'student' && !empty($user['student_id'])) {
    $inlineEntityId = (int) $user['student_id'];
    $inlineNotifs = get_inline_notifications('student', $inlineEntityId);
    $inlineNotifCount = count($inlineNotifs);
} elseif (in_array($user_role, ['adviser', 'chair', 'registrar', 'admin'], true) && !empty($user['staff_id'])) {
    $inlineEntityId = (int) $user['staff_id'];
    $inlineNotifs = get_inline_notifications($user_role, $inlineEntityId);
    $inlineNotifCount = count($inlineNotifs);
}

$semester_labels = [
    '1'   => 'First Semester',
    '2'   => 'Second Semester',
    'mid' => 'Midyear'
];
$semester_text = $semester_labels[(string)($currentTerm['semester'] ?? '')] ?? '';

function getInitials_tpl($name) {
    $words = explode(' ', trim((string)$name));
    $initials = '';
    foreach ($words as $w) {
        if ($w !== '') $initials .= strtoupper($w[0]);
        if (strlen($initials) >= 2) break;
    }
    return $initials ?: 'U';
}

// Sidebar state from cookie/session
$sidebarCollapsed = ($_COOKIE['sidebar'] ?? 'expanded') === 'collapsed';
?>
<!DOCTYPE html>
<html lang="en" class="<?= $sidebarCollapsed ? 'pre-collapsed' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? 'Portal') ?> — <?= h(page_title_suffix()) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= h(app_url('includes/style.css')) ?>">
    <style>tr[data-href]{cursor:pointer;}tr[data-href]:hover{background:#f8fafc;}</style>
    <script>
        // Apply collapsed state BEFORE paint to avoid flash
        (function(){
            var s = localStorage.getItem('sidebar');
            if (s === 'collapsed') document.documentElement.classList.add('pre-collapsed');
        })();
    </script>
    <style>
        html.pre-collapsed * { transition: none !important; }
    </style>
</head>
<body>
<?php if (!empty($modals)): ?>
    <?php foreach ($modals as $modal): ?>
        <?= $modal ?>
    <?php endforeach; ?>
<?php endif; ?>
<?php if ($show_sidebar): ?>
<div class="layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">

        <div class="sidebar-header">
            <span class="material-symbols-outlined sidebar-icon">
                school
            </span>
            <span class="brand"><?= h(setting('system_name', 'E-EnrollSys')) ?></span>
            <!-- toggle is in header; sidebar header just shows brand -->
        </div>

        <div class="sub-brand">
            <?= h(str_replace('_', ' ', ucfirst($user_role))) ?>
        </div>

        <nav>
            <?php include __DIR__ . '/sidebar_items.php'; ?>
        </nav>

        <div class="sidebar-footer">
            <a class="menu-item" href="<?= h(app_url('includes/settings.php')) ?>">
                <span class="material-symbols-outlined sidebar-icon">settings</span>
                <span class="sidebar-text">Settings</span>
            </a>
            <a class="menu-item" href="<?= h(app_url('auth/logout.php')) ?>">
                <span class="material-symbols-outlined sidebar-icon">logout</span>
                <span class="sidebar-text">Logout</span>
            </a>
            <!-- ['label' => 'Settings', 'path' => 'includes/settings.php', 'icon' => 'settings'], -->
        </div>

    </aside>

    <!-- ===== HEADER ===== -->
    <header>
        <div class="header-left">
            <button id="toggleSidebar" class="header-button" title="Toggle sidebar">
                <span class="material-symbols-outlined sidebar-icon">menu</span>
            </button>
            <div class="term-pill">
                <?php if ($currentTerm): ?>
                    <?= h('A.Y. ' . $currentTerm['year_label'] . ' · ' . $semester_text) ?>
                <?php else: ?>
                    No active term
                <?php endif; ?>
            </div>
        </div>

        <div class="header-right" ">
            <!-- Notification bell (links to student notifications if student role) -->
            <?php
            $unreadNotifCount = 0;
            if (($user['role'] ?? '') === 'student' && !empty($user['student_id'])) {
                try {
                    $nRow = fetch_one(
                        'SELECT COUNT(*) AS cnt FROM student_notifications WHERE student_id = :sid AND dismissed = 0',
                        ['sid' => (int) $user['student_id']]
                    );
                    $unreadNotifCount = (int) ($nRow['cnt'] ?? 0);
                } catch (\Throwable $e) {}
            }
            ?>
            <?php if (($user['role'] ?? '') === 'student'): ?>
            <a href="<?= h(app_url('student/notifications.php')) ?>" class="header-button" style="position:relative;text-decoration:none;" title="Notifications">
                <span class="material-symbols-outlined">notifications</span>
                <?php if ($unreadNotifCount > 0): ?>
                    <span class="notif-count" style="position:absolute;top:2px;right:2px;min-width:18px;height:18px;background:#22c55e;border-radius:50%;border:2px solid white;font-size:10px;font-weight:700;color:#fff;display:flex;align-items:center;justify-content:center;line-height:1;padding:0 4px;">
                        <?= $unreadNotifCount > 9 ? '9+' : $unreadNotifCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <?php else: ?>
            <button class="header-button">
                <span class="material-symbols-outlined">notifications</span>
            </button>
            <?php endif; ?>

            <button onclick="toggleTheme()" class="header-button">
                <span class="material-symbols-outlined">dark_mode</span>
            </button>

            <!-- User chip -->
            <div class="user-chip">
                <div class="avatar">
                    <?php if (!empty($_SESSION['user']['profile_pic'])): ?>
                        <img src="<?= h(app_url('uploads/' . $_SESSION['user']['profile_pic'])) ?>"
                             style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    <?php else: ?>
                        <?= h(getInitials_tpl($user['display_name'] ?? 'User')) ?>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <span class="user-name"><?= h($user['display_name'] ?? 'User') ?></span>
                    <span class="user-email"><?= h($user['email'] ?? '') ?></span>
                </div>
            </div>
        </div>

    </header>

    <!-- ===== MAIN ===== -->
    <main class="main">

        <?php if ($flashes !== []): ?>
        <div class="flash-stack">
            <?php foreach ($flashes as $flash): ?>
                <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($inlineNotifs)): ?>
        <div class="inline-notif-stack">
            <?php foreach ($inlineNotifs as $notif): ?>
            <div class="inline-notif <?= h(inline_notification_badge_class((string) $notif['type'])) ?>" data-notif-id="<?= h($notif['id']) ?>">
                <div class="inline-notif-icon">
                    <span class="material-symbols-outlined"><?= h(inline_notification_icon((string) $notif['type'])) ?></span>
                </div>
                <div class="inline-notif-body">
                    <div class="inline-notif-title"><?= h($notif['subject']) ?></div>
                    <div class="inline-notif-text"><?= h($notif['body']) ?></div>
                    <div class="inline-notif-time"><?= h(date('M j, Y g:i A', strtotime($notif['created_at']))) ?></div>
                </div>
                <button class="inline-notif-dismiss" onclick="dismissNotif(<?= (int) $notif['id'] ?>)" title="Dismiss">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <script>
        function dismissNotif(id) {
            var el = document.querySelector('.inline-notif[data-notif-id="' + id + '"]');
            if (!el) return;
            var formData = new URLSearchParams();
            formData.append('notif_id', id);
            fetch('<?= h(app_url('includes/dismiss_notif.php')) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            }).then(function(r) {
                if (r.ok) {
                    el.style.transition = 'all .3s';
                    el.style.opacity = '0';
                    el.style.transform = 'translateX(20px)';
                    el.style.maxHeight = el.offsetHeight + 'px';
                    setTimeout(function() {
                        el.style.maxHeight = '0';
                        el.style.padding = '0';
                        el.style.margin = '0';
                        el.style.overflow = 'hidden';
                    }, 300);
                    setTimeout(function() { el.remove(); updateNotifBadge(-1); }, 600);
                }
            }).catch(function() { el.remove(); updateNotifBadge(-1); });
        }
        function updateNotifBadge(delta) {
            var badges = document.querySelectorAll('.notif-count, .sidebar-badge, .header-button[title="Notifications"] .notif-count');
            badges.forEach(function(badge) {
                var count = parseInt(badge.textContent) || 0;
                count = Math.max(0, count + delta);
                if (count <= 0) { badge.style.display = 'none'; }
                else { badge.textContent = count > 9 ? '9+' : count; }
            });
        }
        </script>
        <?php endif; ?>
        <?php renderBreadcrumbs($page_title, $_SESSION['role'] ?? '', $breadcrumbs ?? []); ?>
        <?= $main_content ?>

    </main>

</div><!-- .layout -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('toggleSidebar');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (!btn || !sidebar) return;

    function openMobileSidebar() {
        sidebar.classList.add('open');
        overlay?.classList.add('show');
    }

    function closeMobileSidebar() {
        sidebar.classList.remove('open');
        overlay?.classList.remove('show');
    }

    function toggleSidebar() {
        const isMobile = window.innerWidth <= 768;

        if (isMobile) {
            sidebar.classList.contains('open')
                ? closeMobileSidebar()
                : openMobileSidebar();
        } else {
            document.documentElement.classList.toggle('collapsed');

            localStorage.setItem(
                'sidebar',
                document.documentElement.classList.contains('collapsed')
                    ? 'collapsed'
                    : 'expanded'
            );
        }
    }

    btn.addEventListener('click', toggleSidebar);
    overlay?.addEventListener('click', closeMobileSidebar);

    // restore desktop collapsed state
    if (localStorage.getItem('sidebar') === 'collapsed') {
        document.documentElement.classList.add('collapsed');
    }
});


function toggleTheme() {
    document.body.classList.toggle('dark-mode');

    localStorage.setItem(
        'theme',
        document.body.classList.contains('dark-mode') ? 'dark' : 'light'
    );
}

// load saved theme
(function () {
    if (localStorage.getItem('theme') === 'dark') {
        document.body.classList.add('dark-mode');
    }
})();


document.addEventListener("click", (e) => {
    // OPEN
    if (e.target.closest("[data-open]")) {
        const id = e.target.closest("[data-open]").dataset.open;
        document.getElementById(id)?.classList.add("active");
    }

    // CLOSE button
    if (e.target.closest("[data-close]")) {
        const id = e.target.closest("[data-close]").dataset.close;
        document.getElementById(id)?.classList.remove("active");
    }

    // CLICK OUTSIDE MODAL
    document.querySelectorAll(".modal").forEach(modal => {
        modal.addEventListener("click", (ev) => {
            if (ev.target === modal) {
                modal.classList.remove("active");
            }
        });
    });
});

// ── Clickable table rows (data-href) ──
document.addEventListener("click", (e) => {
    const row = e.target.closest("tr[data-href]");
    if (!row) return;
    const tag = e.target.closest("a, button, input, select, textarea, label, .row-actions, .dt-bulk-bar, .dt-toolbar, .dt-footer");
    if (tag) return;
    const href = row.getAttribute("data-href");
    if (href) window.location.href = href;
});

// ── Notification badge polling ──
<?php if ($user_role === 'student' && !empty($user['student_id'])): ?>
(function pollNotifBadge() {
    fetch('<?= h(app_url('includes/notif_count.php')) ?>')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var count = parseInt(data.count) || 0;
            var badges = document.querySelectorAll('.sidebar-badge');
            badges.forEach(function(b) {
                var parentLink = b.closest('a');
                if (parentLink && parentLink.querySelector('.sidebar-text') && parentLink.querySelector('.sidebar-text').textContent.trim() === 'Notifications') {
                    if (count <= 0) { b.style.display = 'none'; }
                    else { b.style.display = ''; b.textContent = count > 9 ? '9+' : count; }
                }
            });
        })
        .catch(function() {});
    setTimeout(pollNotifBadge, 30000);
})();
<?php endif; ?>
</script>

<?php else: ?>
    <?= $main_content ?>
<?php endif; ?>

<script src="<?= h(app_url('includes/datatable.js')) ?>" defer></script>
</body>
</html>
