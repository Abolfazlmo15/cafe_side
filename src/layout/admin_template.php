<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($this->title) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ----- RESET & BASE ----- */
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f8f5f2;
            min-height: 100vh;
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ----- SIDEBAR ----- */
        .sidebar {
            width: 260px;
            background: #2d1b0e;
            color: white;
            padding: 2rem 1.5rem;
            height: 100vh;
            overflow-y: auto;
            flex-shrink: 0;
            transition: margin-left 0.3s ease;
            margin-left: 0;
            z-index: 1000;
            position: sticky;
            top: 0;
            border-right: 1px solid #4d3628;
        }
        .sidebar.closed {
            margin-left: -260px;
        }
        .sidebar .logo {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
        }
        .sidebar .logo img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
        }
        .sidebar nav ul {
            list-style: none;
        }
        .sidebar nav ul li {
            margin-bottom: 0.5rem;
        }
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
        .sidebar nav ul li a:hover {
            background: #4d3628;
            color: white;
        }
        .sidebar nav ul li a.active {
            background: #6f4e37;
            color: white;
        }
        .sidebar nav ul li a i {
            width: 1.4rem;
        }

        /* ----- MAIN CONTENT ----- */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #f8f5f2;
            min-height: 100vh;
        }

        /* ----- ADMIN HEADER ----- */
        .admin-header {
            background: white;
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
        .admin-header .left .toggle-btn {
            background: none;
            border: none;
            font-size: 1.6rem;
            color: #2d1b0e;
            cursor: pointer;
            padding: 0.2rem 0.4rem;
            display: flex;
            align-items: center;
        }
        .admin-header .left .page-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: #2d1b0e;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .admin-header .left .page-title img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            display: inline-block;
            vertical-align: middle;
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

        /* ----- PAGE CONTAINER ----- */
        .page-container {
            flex: 1;
            padding: 1.5rem 2rem;
            background: #f8f5f2;
        }
        .page-container .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 1.5rem 2rem;
            border-radius: 1.5rem;
            border: 1px solid #f0e4db;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        /* ----- FOOTER ----- */
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

        /* ----- SIDEBAR OVERLAY (mobile) ----- */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.3);
            z-index: 999;
        }
        .sidebar-overlay.active {
            display: block;
        }

        /* ----- RESPONSIVE (mobile) ----- */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                margin-left: -260px;
                box-shadow: 2px 0 12px rgba(0,0,0,0.15);
            }
            .sidebar.open {
                margin-left: 0;
            }
            .admin-header {
                padding: 0.6rem 1rem;
            }
            .admin-header .left .page-title {
                font-size: 1.2rem;
            }
            .page-container {
                padding: 1rem;
            }
            .page-container .container {
                padding: 1rem;
            }
            .admin-footer {
                padding: 0.6rem 1rem;
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- ===== SIDEBAR (hardcoded) ===== -->
        <?php
        $active = $this->activePage ?? 'orders';
        ?>
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
                    <li><a href="<?= URL_ADMIN_SETTINGS ?>" class="<?= $active === 'settings' ? 'active' : '' ?>"><i class="fas fa-cog"></i> Settings</a></li>
                    <li style="margin-top:1.5rem; border-top:1px solid #4d3628; padding-top:0.8rem;">
                        <a href="<?= URL_LOGOUT ?>"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </li>
                </ul>
            </nav>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <div class="main-content">
            <!-- ===== HEADER (hardcoded) ===== -->
            <header class="admin-header">
                <div class="left">
                    <button class="toggle-btn" id="sidebarToggle" onclick="toggleSidebar()">
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

            <!-- ===== FOOTER (hardcoded) ===== -->
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
        // ----- SIDEBAR TOGGLE -----
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('open');
                overlay.classList.toggle('active');
                const isOpen = sidebar.classList.contains('open');
                localStorage.setItem('sidebarOpen', isOpen ? 'true' : 'false');
            } else {
                sidebar.classList.toggle('closed');
                overlay.classList.remove('active');
                const isClosed = sidebar.classList.contains('closed');
                localStorage.setItem('sidebarClosed', isClosed ? 'true' : 'false');
            }
        }

        (function() {
            if (window.innerWidth <= 768) {
                const savedOpen = localStorage.getItem('sidebarOpen');
                if (savedOpen === 'true') {
                    sidebar.classList.add('open');
                    overlay.classList.add('active');
                } else {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                }
            } else {
                const savedClosed = localStorage.getItem('sidebarClosed');
                if (savedClosed === 'true') {
                    sidebar.classList.add('closed');
                } else {
                    sidebar.classList.remove('closed');
                }
                overlay.classList.remove('active');
            }
        })();

        overlay.addEventListener('click', function() {
            if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
                toggleSidebar();
            }
        });

        document.querySelectorAll('.sidebar nav ul li a').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
                    toggleSidebar();
                }
            });
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                const savedClosed = localStorage.getItem('sidebarClosed');
                if (savedClosed === 'true') {
                    sidebar.classList.add('closed');
                } else {
                    sidebar.classList.remove('closed');
                }
            } else {
                const savedOpen = localStorage.getItem('sidebarOpen');
                if (savedOpen === 'true') {
                    sidebar.classList.add('open');
                    overlay.classList.add('active');
                } else {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                }
            }
        });
    </script>
</body>
</html>