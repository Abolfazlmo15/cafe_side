<?php
// src/layout/components/sidebar.php – Admin sidebar navigation
// =============================================================

$active = $this->activePage ?? 'orders';
?>
<aside class="admin-sidebar" id="adminSidebar" style="
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
">
    <div style="
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: white;
    ">
        <img src="../../assets/images/icons/side_coffee.png" alt="<?= SITE_NAME ?>" style="width:36px; height:36px; border-radius:50%; object-fit:cover; display:block;">
        <span style="color:white;"><?= SITE_NAME ?></span>
    </div>
    <nav>
        <ul style="list-style: none; padding:0; margin:0;">
            <li style="margin-bottom:0.5rem;">
                <a href="<?= URL_ADMIN_ORDERS ?>" style="
                    color: #cbd5e1;
                    text-decoration: none;
                    display: flex;
                    align-items: center;
                    gap: 0.8rem;
                    padding: 0.6rem 0.8rem;
                    border-radius: 0.5rem;
                    transition: 0.2s;
                    <?= $active === 'orders' ? 'background: #6f4e37; color: white;' : '' ?>
                ">
                    <i class="fas fa-clipboard-list" style="width:1.4rem;"></i> Orders
                </a>
            </li>
            <li style="margin-bottom:0.5rem;">
                <a href="<?= URL_ADMIN_QR ?>" style="
                    color: #cbd5e1;
                    text-decoration: none;
                    display: flex;
                    align-items: center;
                    gap: 0.8rem;
                    padding: 0.6rem 0.8rem;
                    border-radius: 0.5rem;
                    transition: 0.2s;
                    <?= $active === 'qr' ? 'background: #6f4e37; color: white;' : '' ?>
                ">
                    <i class="fas fa-qrcode" style="width:1.4rem;"></i> QR Codes
                </a>
            </li>
            <li style="margin-bottom:0.5rem;">
                <a href="<?= URL_ADMIN_ITEMS ?>" style="
                    color: #cbd5e1;
                    text-decoration: none;
                    display: flex;
                    align-items: center;
                    gap: 0.8rem;
                    padding: 0.6rem 0.8rem;
                    border-radius: 0.5rem;
                    transition: 0.2s;
                    <?= $active === 'items' ? 'background: #6f4e37; color: white;' : '' ?>
                ">
                    <i class="fas fa-utensils" style="width:1.4rem;"></i> Items
                </a>
            </li>
            <li style="margin-bottom:0.5rem;">
                <a href="<?= URL_ADMIN_SETTINGS ?>" style="
                    color: #cbd5e1;
                    text-decoration: none;
                    display: flex;
                    align-items: center;
                    gap: 0.8rem;
                    padding: 0.6rem 0.8rem;
                    border-radius: 0.5rem;
                    transition: 0.2s;
                    <?= $active === 'settings' ? 'background: #6f4e37; color: white;' : '' ?>
                ">
                    <i class="fas fa-cog" style="width:1.4rem;"></i> Settings
                </a>
            </li>
            <li style="margin-top:1.5rem; border-top:1px solid #4d3628; padding-top:0.8rem;">
                <a href="<?= URL_LOGOUT ?>" style="
                    color: #cbd5e1;
                    text-decoration: none;
                    display: flex;
                    align-items: center;
                    gap: 0.8rem;
                    padding: 0.6rem 0.8rem;
                    border-radius: 0.5rem;
                    transition: 0.2s;
                ">
                    <i class="fas fa-sign-out-alt" style="width:1.4rem;"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
</aside>