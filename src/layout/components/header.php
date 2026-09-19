<?php
// src/layout/components/header.php – Admin header
// =================================================

// This file is included from admin_template.php.
// Variables available: $this (the AdminLayout object)
?>
<header class="admin-header" style="
    background: white;
    border-bottom: 1px solid #f0e4db;
    padding: 0.6rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.8rem 2rem;
">
    <div style="display:flex; align-items:center; gap:0.8rem;">
        <button class="sidebar-toggle" id="sidebarToggle" style="
            background: none;
            border: none;
            font-size: 1.6rem;
            color: #2d1b0e;
            cursor: pointer;
            padding: 0.2rem 0.4rem;
            display: flex;
            align-items: center;
        ">
            <i class="fas fa-bars"></i>
        </button>
        <div style="font-size:1.4rem; font-weight:600; color:#2d1b0e; display:flex; align-items:center; gap:0.4rem;">
            <img src="../../assets/images/icons/side_coffee.png" alt="<?= SITE_NAME ?>" style="width:32px; height:32px; border-radius:50%; object-fit:cover; display:block;">
            <?= SITE_NAME ?>
        </div>
    </div>
    <div class="header-actions" style="display:flex; align-items:center; gap:0.8rem; flex-wrap:wrap;">
        <?php if (isset($this->extraActions)): ?>
            <?= $this->extraActions ?>
        <?php endif; ?>
    </div>
</header>