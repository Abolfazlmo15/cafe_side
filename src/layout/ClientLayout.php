<?php
// src/layout/ClientLayout.php – Simple layout for customer pages
// =================================================================

class ClientLayout {
    private $title = 'QR Café';
    private $content = '';
    private $extraHead = '';

    public function setTitle($title) {
        $this->title = $title . ' · QR Café';
        return $this;
    }

    public function setContent($content) {
        $this->content = $content;
        return $this;
    }

    public function setExtraHead($html) {
        $this->extraHead = $html;
        return $this;
    }

    public function render() {
        require_once __DIR__ . '/client_template.php';
    }
}