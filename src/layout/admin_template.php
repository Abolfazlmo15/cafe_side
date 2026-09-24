<?php
// Migration pending check. Cheap: glob + one small query.
$__pendingMigrationCount = 0;
$__pendingMigrationList  = [];
try {
    require_once __DIR__ . '/../database.php';
    $__pdoCheck = getDbConnection();
    $__versionsDir = __DIR__ . '/../migrations/versions/';
    $__files = glob($__versionsDir . '*.php');
    if ($__files) {
        $__applied = $__pdoCheck
            ->query("SELECT migration FROM migrations")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($__files as $__f) {
            $__cn = 'Migration_' . pathinfo($__f, PATHINFO_FILENAME);
            if (!in_array($__cn, $__applied)) {
                $__pendingMigrationList[] = $__cn;
            }
        }
    }
    $__pendingMigrationCount = count($__pendingMigrationList);
} catch (Throwable $__e) {
    // Table missing or DB down. Fail quiet.
    $__pendingMigrationCount = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($this->title) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { height: 100%; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f8f5f2;
            overflow-x: hidden;
        }
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
            min-height: 100dvh;
        }

        .sidebar {
            width: 260px;
            background: #2d1b0e;
            color: #fff;
            padding: 2rem 1.5rem;
            height: 100vh;
            height: 100dvh;
            overflow-y: auto;
            overscroll-behavior: contain;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            margin-left: 0;
            transition: margin-left 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            z-index: 100;
            border-right: 1px solid #4d3628;
        }
        body.nav-collapsed .sidebar {
            margin-left: -260px;
        }

        .sidebar .logo {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #fff;
        }
        .sidebar .logo img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
        }
        .sidebar nav ul { list-style: none; }
        .sidebar nav ul li { margin-bottom: 0.5rem; }
        .sidebar nav ul li a {
            color: #cbd5e1;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.6rem 0.8rem;
            border-radius: 0.5rem;
            transition: 0.2s;
        }
        .sidebar nav ul li a:hover { background: #4d3628; color: #fff; }
        .sidebar nav ul li a.active { background: #6f4e37; color: #fff; }
        .sidebar nav ul li a i { width: 1.4rem; }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #f8f5f2;
            min-width: 0;
            min-height: 100vh;
            min-height: 100dvh;
        }

        .admin-header {
            background: #fff;
            padding: 0.6rem 2rem;
            border-bottom: 1px solid #f0e4db;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.8rem 2rem;
        }
        .admin-header .left {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        .toggle-btn {
            background: none;
            border: none;
            font-size: 1.6rem;
            color: #2d1b0e;
            cursor: pointer;
            padding: 0.4rem 0.6rem;
            display: flex;
            align-items: center;
            border-radius: 0.5rem;
            transition: background 0.15s, transform 0.12s;
            font-family: inherit;
            line-height: 1;
            pointer-events: auto;
        }
        .toggle-btn:hover { background: #f3e8e0; }
        .toggle-btn:active { transform: scale(0.94); }
        .toggle-btn i { pointer-events: none; }

        .page-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: #2d1b0e;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .page-title img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 0.4rem;
        }

        .admin-actions {
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.6rem 1rem;
            padding: 0.2rem 0 0.4rem 0;
            border-top: 1px solid #f0e4db;
            margin-top: 0.2rem;
        }

        .page-container {
            flex: 1;
            padding: 1.5rem 2rem;
            background: #f8f5f2;
        }
        .page-container .container {
            max-width: 1400px;
            margin: 0 auto;
            background: #fff;
            padding: 1.5rem 2rem;
            border-radius: 1.5rem;
            border: 1px solid #f0e4db;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .admin-footer {
            background: #f8f5f2;
            border-top: 1px solid #f0e4db;
            padding: 0.8rem 2rem;
            margin-top: auto;
            text-align: center;
            color: #5a3f2e;
            font-size: 0.85rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .admin-footer img {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            object-fit: cover;
            display: inline-block;
            vertical-align: middle;
            margin-right: 0.4rem;
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 99;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.22s ease, visibility 0.22s ease;
            pointer-events: none;
        }
        body.nav-open .sidebar-overlay {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        @media (max-width: 1024px) {
            .sidebar {
                position: fixed;
                top: 0;
                bottom: 0;
                left: 0;
                height: auto;
                max-height: 100dvh;
                width: 260px;
                margin-left: -280px;
                z-index: 100;
                box-shadow: 4px 0 24px rgba(0, 0, 0, 0.18);
                padding-top: max(2rem, calc(2rem + env(safe-area-inset-top)));
                padding-bottom: max(2rem, calc(2rem + env(safe-area-inset-bottom)));
                transition: margin-left 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            }
            body.nav-collapsed .sidebar {
                margin-left: -280px;
            }
            body.nav-open .sidebar {
                margin-left: 0;
            }

            .admin-header { padding: 0.6rem 1rem; }
            .page-title { font-size: 1.15rem; }
            .page-title img { width: 28px; height: 28px; }

            .page-container { padding: 1rem; }
            .page-container .container { padding: 1rem; }

            .admin-footer {
                padding: 0.6rem 1rem;
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .page-title { font-size: 1rem; }
            .page-title img { width: 24px; height: 24px; }
            .toggle-btn { font-size: 1.4rem; }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php $active = $this->activePage ?? 'orders'; ?>

        <aside class="sidebar" id="adminSidebar">
            <div class="logo">
                <img src="../../assets/images/icons/side_coffee.png" alt="<?= SITE_NAME ?>">
                <?= SITE_NAME ?>
            </div>
            <nav>
                <ul>
                    <li><a href="<?= URL_ADMIN_ORDERS ?>" class="<?= $active === 'orders' ? 'active' : '' ?>"><i class="fas fa-clipboard-list"></i> Orders</a></li>
                    <li><a href="<?= URL_ADMIN_QR ?>" class="<?= $active === 'qr' ? 'active' : '' ?>"><i class="fas fa-qrcode"></i> QR Codes</a></li>
                    <li><a href="<?= URL_ADMIN_ITEMS ?>" class="<?= $active === 'items' ? 'active' : '' ?>"><i class="fas fa-utensils"></i> Items</a></li>
                    <li><a href="<?= URL_ADMIN_ANALYTICS ?>" class="<?= $active === 'analytics' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Analytics</a></li>
                    <li><a href="<?= URL_ADMIN_SETTINGS ?>" class="<?= $active === 'settings' ? 'active' : '' ?>"><i class="fas fa-cog"></i> Settings</a></li>
                    <li style="margin-top:1.5rem; border-top:1px solid #4d3628; padding-top:0.8rem;">
                        <a href="<?= URL_LOGOUT ?>"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!--
            OVERLAY — inline onclick.
            This element covers everything outside the sidebar when it's open.
            Any click on it closes the sidebar. No script block needed.
        -->
        <div class="sidebar-overlay"
             id="sidebarOverlay"
             onclick="document.body.classList.remove('nav-open');"></div>

        <div class="main-content">

            <?php if ($__pendingMigrationCount > 0): ?>
                    <div style="background:#fef3c7;border-bottom:1px solid #f59e0b;padding:0.7rem 2rem;color:#78350f;font-size:0.9rem;display:flex;align-items:center;gap:0.8rem;flex-wrap:wrap;">
                        <i class="fas fa-triangle-exclamation" style="font-size:1.1rem;"></i>
                        <strong><?= $__pendingMigrationCount ?> migration<?= $__pendingMigrationCount > 1 ? 's' : '' ?> pending.</strong>
                        <span style="color:#92400e;"><?= htmlspecialchars(implode(', ', $__pendingMigrationList)) ?></span>
                        <a href="<?= BASE_URL ?>/public/_migrate.php?token=<?= urlencode(env('AI_CRON_TOKEN', '')) ?>"
                        target="_blank"
                        style="margin-left:auto;background:#6f4e37;color:#fff;padding:0.35rem 0.9rem;border-radius:0.4rem;text-decoration:none;font-weight:600;font-size:0.85rem;">
                            Run now
                        </a>
                    </div>
            <?php endif; ?>

            <header class="admin-header">
                <div class="left">
                    <button class="toggle-btn"
                            type="button"
                            id="sidebarToggleBtn"
                            aria-label="Toggle sidebar"
                            onclick="
                                var w = window.innerWidth;
                                var b = document.body;
                                if (w <= 1024) {
                                    b.classList.toggle('nav-open');
                                } else {
                                    b.classList.toggle('nav-collapsed');
                                    try { localStorage.setItem('admin_sidebar_collapsed', b.classList.contains('nav-collapsed') ? 'true' : 'false'); } catch(e) {}
                                }
                            ">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="page-title">
                        <img src="../../assets/images/icons/side_coffee.png" alt="<?= SITE_NAME ?>">
                        <?= $this->title ?>
                    </div>
                </div>
                <div class="admin-actions">
                    <?php if (isset($this->extraActions)): ?>
                        <?= $this->extraActions ?>
                    <?php endif; ?>
                </div>
            </header>

            <div class="page-container">
                <div class="container">
                    <?= $this->content ?>
                </div>
            </div>

            <footer class="admin-footer">
                <span>
                    <img src="../../assets/images/icons/side_coffee.png" alt="<?= SITE_NAME ?>">
                    &copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.
                </span>
                <span>Powered by <strong style="color:#6f4e37;"><?= SITE_NAME ?></strong> v1.0</span>
            </footer>
        </div>
    </div>

    <script>
    // Escape closes the mobile sidebar. Nav-link close. Restore desktop state.
    (function () {
        try {
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && window.innerWidth <= 1024) {
                    document.body.classList.remove('nav-open');
                }
            });

            var links = document.querySelectorAll('.sidebar nav a');
            for (var i = 0; i < links.length; i++) {
                links[i].addEventListener('click', function () {
                    if (window.innerWidth <= 1024) {
                        setTimeout(function () {
                            document.body.classList.remove('nav-open');
                        }, 0);
                    }
                });
            }

            if (window.innerWidth > 1024) {
                var wasCollapsed = localStorage.getItem('admin_sidebar_collapsed') === 'true';
                if (wasCollapsed) document.body.classList.add('nav-collapsed');
            }
        } catch (err) {
            console.warn('[sidebar-helper] init failed:', err);
        }
    })();
    