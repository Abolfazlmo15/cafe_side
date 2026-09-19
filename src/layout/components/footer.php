<?php
// src/layout/components/footer.php – Global footer for admin
// ===========================================================
?>
<footer class="admin-footer" style="
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
">
    <span>
        <img src="../../assets/images/icons/side_coffee.png" alt="<?= SITE_NAME ?>" style="width:20px; height:20px; border-radius:50%; object-fit:cover; display:inline-block; vertical-align:middle; margin-right:0.4rem;">
        &copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.
    </span>
    <span>Powered by <strong style="color:#6f4e37;"><?= SITE_NAME ?></strong> v1.0</span>
</footer>