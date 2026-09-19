<?php
// src/layout/AdminLayout.php – Admin layout with sidebar, header, footer
// =========================================================================

class AdminLayout {
    private $title = 'Admin – BrewVerse';
    private $activePage = 'orders'; // orders | qr | items | settings
    private $content = '';
    public $extraActions = '';

    public function setTitle($title) {
        // Use SITE_NAME constant from config.php
        $this->title = 'Admin – ' . $title . ' · ' . SITE_NAME;
        return $this;
    }

    public function setActive($page) {
        $this->activePage = $page;
        return $this;
    }

    public function setContent($content) {
        $this->content = $content;
        return $this;
    }

    public function render() {
        require_once __DIR__ . '/admin_template.php';
    }
}