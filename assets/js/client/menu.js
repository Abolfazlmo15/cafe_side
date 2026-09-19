document.addEventListener('DOMContentLoaded', function() {
    // ============================================================
    // IMAGE MODAL
    // ============================================================
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    const modalClose = document.getElementById('modalClose');

    function openModal(src) {
        modalImg.src = src;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeModal() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        setTimeout(() => { modalImg.src = ''; }, 300);
    }

    function attachModalListeners() {
        document.querySelectorAll('.menu-item-img').forEach(img => {
            img.removeEventListener('click', img._clickHandler);
            img._clickHandler = function(e) {
                e.stopPropagation();
                const fullSrc = this.dataset.full;
                if (fullSrc) openModal(fullSrc);
            };
            img.addEventListener('click', img._clickHandler);
        });
    }
    attachModalListeners();

    modalClose.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) { if (e.target === this) closeModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal.classList.contains('active')) closeModal(); });

    // ============================================================
    // QUANTITY CONTROLS (event delegation)
    // ============================================================
    const menuContainer = document.getElementById('menuContainer');

    menuContainer.addEventListener('click', function(e) {
        const target = e.target;
        if (target.classList.contains('qty-minus') || target.classList.contains('qty-plus')) {
            const input = target.closest('.qty-controls').querySelector('.qty-input');
            if (!input) return;
            let val = parseInt(input.value) || 0;
            if (target.classList.contains('qty-minus')) {
                if (val > 0) input.value = val - 1;
            } else {
                if (val < 99) input.value = val + 1;
            }
        }
    });

    menuContainer.addEventListener('change', function(e) {
        if (e.target.classList.contains('qty-input')) {
            let val = parseInt(e.target.value) || 0;
            if (val < 0) e.target.value = 0;
            if (val > 99) e.target.value = 99;
        }
    });

    // ============================================================
    // FORM SUBMISSION
    // ============================================================
    document.getElementById('orderForm').addEventListener('submit', function(e) {
        // Check if table is valid before allowing submission
        if (!window.tableValid) {
            e.preventDefault();
            alert('Ordering is disabled – invalid table.');
            return;
        }
        const qtyInputs = document.querySelectorAll('.qty-input');
        const items = {};
        let hasItems = false;
        qtyInputs.forEach(input => {
            const qty = parseInt(input.value) || 0;
            if (qty > 0) {
                items[input.dataset.id] = qty;
                hasItems = true;
            }
        });
        if (!hasItems) {
            e.preventDefault();
            alert('Please select at least one item.');
            return;
        }
        document.getElementById('itemsJson').value = JSON.stringify(items);
    });

    // ============================================================
    // POLLING: BANNER (only if table valid)
    // ============================================================
    function updateBanner() {
        const table = window.tableNumber || 0;
        const token = window.deviceToken || '';
        if (table <= 0 || !token || !window.tableValid) return;
        fetch('menu.php?banner=1&table=' + table + '&token=' + encodeURIComponent(token) + '&_=' + Date.now())
            .then(r => r.text())
            .then(html => {
                const container = document.getElementById('bannerContainer');
                if (container) container.innerHTML = html.trim() !== '' ? html : '';
            })
            .catch(err => console.warn('Banner polling error:', err));
    }
    setInterval(updateBanner, 5000);

    // ============================================================
    // CATEGORY PILLS – REBUILD FUNCTION (fixed persistence)
    // ============================================================
    let activeCategory = 'all';
    let searchQuery = '';

    function rebuildCategories(categories) {
        const container = document.getElementById('categoryContainer');
        if (!container) return;

        // Use the stored activeCategory, NOT the DOM
        let activeCat = activeCategory;
        // If activeCat is not in the new list, reset to 'all'
        if (activeCat !== 'all' && !categories.includes(activeCat)) {
            activeCat = 'all';
        }
        // Update the global variable
        activeCategory = activeCat;

        // Build new pills HTML
        let html = '<button class="cat-pill active" data-cat="all">All</button>';
        categories.forEach(cat => {
            const activeClass = (cat === activeCat) ? 'active' : '';
            html += `<button class="cat-pill ${activeClass}" data-cat="${cat}">${cat}</button>`;
        });
        container.innerHTML = html;

        // Reattach click listeners
        container.querySelectorAll('.cat-pill').forEach(pill => {
            pill.addEventListener('click', function() {
                container.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                activeCategory = this.dataset.cat;
                applyFilters();
            });
        });

        // Update the global category list
        window.allCategories = categories;

        // Reapply current filter (search + category)
        applyFilters();
    }

    // ============================================================
    // UPDATE TABLE STATUS UI
    // ============================================================
    function updateTableStatus(valid, message) {
        // Update global flag
        window.tableValid = valid;

        // Find error alert
        let alert = document.getElementById('errorAlert');
        const submitBtn = document.getElementById('submitOrderBtn');
        const invalidMsg = document.getElementById('tableInvalidMsg');

        if (valid) {
            // Table is valid – remove error alert if exists
            if (alert) {
                alert.style.transition = 'opacity 0.3s ease';
                alert.style.opacity = '0';
                setTimeout(function() {
                    if (alert.parentNode) alert.remove();
                }, 300);
            }
            // Enable submit button
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
            }
            if (invalidMsg) invalidMsg.remove();
        } else {
            // Table invalid – show error alert
            if (!alert) {
                // Create the alert
                const alertHtml = `
                    <div class="alert alert-error dismissible" id="errorAlert">
                        <i class="fas fa-exclamation-circle"></i>
                        <span id="errorMessageText">${message || 'Please use a valid table QR code. If you need assistance, ask our staff.'}</span>
                        <button class="close-btn" id="errorCloseBtn" aria-label="Close">&times;</button>
                    </div>
                `;
                // Insert after banner container
                const bannerContainer = document.getElementById('bannerContainer');
                bannerContainer.insertAdjacentHTML('afterend', alertHtml);
                // Reattach close button listener
                const newCloseBtn = document.getElementById('errorCloseBtn');
                if (newCloseBtn) {
                    newCloseBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const alertEl = document.getElementById('errorAlert');
                        if (alertEl) {
                            alertEl.style.transition = 'opacity 0.3s ease';
                            alertEl.style.opacity = '0';
                            setTimeout(function() { alertEl.remove(); }, 300);
                        }
                    });
                }
            } else {
                // Update message
                const msgSpan = document.getElementById('errorMessageText');
                if (msgSpan) msgSpan.textContent = message || 'Please use a valid table QR code. If you need assistance, ask our staff.';
            }
            // Disable submit button
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.cursor = 'not-allowed';
            }
            // Show invalid message if not present
            if (!invalidMsg && submitBtn) {
                const p = document.createElement('p');
                p.id = 'tableInvalidMsg';
                p.style.cssText = 'color:#991b1b; font-size:0.85rem; margin-top:0.3rem;';
                p.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Ordering is disabled – invalid table.';
                submitBtn.parentNode.appendChild(p);
            }
        }
    }

    // ============================================================
    // POLLING: MENU ITEMS (preserve quantities, update categories + table status)
    // ============================================================
    function updateMenu() {
        // 1. Save current quantities
        const quantities = {};
        document.querySelectorAll('.qty-input').forEach(input => {
            const id = input.dataset.id;
            const val = parseInt(input.value) || 0;
            if (val > 0) quantities[id] = val;
        });

        // 2. Fetch new menu HTML + table status – include the table number!
        const table = window.tableNumber || 0;
        fetch('menu.php?menu_items=1&table=' + table + '&_=' + Date.now())
            .then(r => r.text())
            .then(html => {
                if (menuContainer) {
                    // Save the refresh notice
                    const notice = menuContainer.querySelector('.menu-refresh-notice');
                    menuContainer.innerHTML = html;
                    // Restore notice if removed
                    if (notice && !menuContainer.querySelector('.menu-refresh-notice')) {
                        menuContainer.appendChild(notice);
                    }

                    // 3. Restore quantities
                    document.querySelectorAll('.qty-input').forEach(input => {
                        const id = input.dataset.id;
                        if (quantities[id] !== undefined) {
                            input.value = quantities[id];
                        }
                    });

                    // 4. Re‑attach modal listeners
                    attachModalListeners();

                    // 5. Extract category data from the hidden div
                    const categoryDataDiv = menuContainer.querySelector('#categoryData');
                    if (categoryDataDiv) {
                        try {
                            const categories = JSON.parse(categoryDataDiv.dataset.categories);
                            categoryDataDiv.remove();
                            rebuildCategories(categories);
                        } catch (e) {
                            console.warn('Failed to parse category data:', e);
                        }
                    } else {
                        console.warn('No categoryData found in AJAX response.');
                    }

                    // 6. Extract table status from hidden div
                    const tableStatusDiv = document.getElementById('tableStatus');
                    if (tableStatusDiv) {
                        const valid = tableStatusDiv.dataset.valid === '1';
                        const message = tableStatusDiv.dataset.message || '';
                        updateTableStatus(valid, message);
                        // Remove the div after processing
                        tableStatusDiv.remove();
                    } else {
                        // If no tableStatus, assume valid (fallback)
                        // But we can also keep the current state
                    }

                    // 7. Re-apply filters (search + category)
                    applyFilters();
                }
            })
            .catch(err => console.warn('Menu polling error:', err));
    }
    setInterval(updateMenu, 5000);

    // ============================================================
    // FILTERS (search + category)
    // ============================================================
    function applyFilters() {
        const cards = document.querySelectorAll('.coffee-card');
        const groups = document.querySelectorAll('.category-group');

        cards.forEach(card => {
            const cat = card.dataset.category;
            const name = card.querySelector('.card-title')?.textContent?.toLowerCase() || '';
            const desc = card.querySelector('.card-desc')?.textContent?.toLowerCase() || '';
            const matchesCategory = (activeCategory === 'all' || cat === activeCategory);
            const matchesSearch = name.includes(searchQuery.toLowerCase()) || desc.includes(searchQuery.toLowerCase());
            card.style.display = (matchesCategory && matchesSearch) ? '' : 'none';
        });

        groups.forEach(group => {
            const visibleCards = group.querySelectorAll('.coffee-card[style*="display: none"]');
            const allCards = group.querySelectorAll('.coffee-card');
            if (visibleCards.length === allCards.length) {
                group.style.display = 'none';
            } else {
                group.style.display = '';
            }
        });
    }

    // ============================================================
    // SEARCH
    // ============================================================
    const searchInput = document.getElementById('searchInput');
    const searchToggle = document.getElementById('searchToggle');
    const searchWrap = document.getElementById('searchWrap');

    searchInput.addEventListener('input', function() {
        searchQuery = this.value;
        applyFilters();
    });

    searchToggle.addEventListener('click', function() {
        searchWrap.classList.toggle('active');
        if (searchWrap.classList.contains('active')) {
            searchInput.focus();
        }
    });

    // ============================================================
    // CATEGORY PILLS – INITIAL SETUP
    // ============================================================
    const initialPills = document.querySelectorAll('.cat-pill');
    initialPills.forEach(pill => {
        pill.addEventListener('click', function() {
            initialPills.forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            activeCategory = this.dataset.cat;
            applyFilters();
        });
    });

    // ============================================================
    // ABOUT DRAWER
    // ============================================================
    const drawer = document.getElementById('aboutDrawer');
    const drawerOverlay = document.getElementById('drawerOverlay');
    const drawerClose = document.getElementById('drawerClose');
    const aboutToggle = document.getElementById('aboutToggle');

    function openDrawer() {
        drawer.classList.add('open');
        drawerOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
        drawer.classList.remove('open');
        drawerOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    aboutToggle.addEventListener('click', openDrawer);
    drawerClose.addEventListener('click', closeDrawer);
    drawerOverlay.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer();
    });

    // ============================================================
    // POLLING: ABOUT CONTENT
    // ============================================================
    function updateAboutContent() {
        fetch('menu.php?about_content=1&_=' + Date.now())
            .then(r => r.json())
            .then(data => {
                const drawerBody = document.querySelector('#aboutDrawer .drawer-body');
                if (!drawerBody) return;

                // Build HTML from JSON data
                let html = '';
                if (data.welcome) {
                    html += '<p><strong>' + escapeHtml(data.welcome) + '</strong></p>';
                }

                if (data.offerings) {
                    const offerings = data.offerings.split('\n').map(s => s.trim()).filter(s => s);
                    if (offerings.length > 0) {
                        html += '<h3>☕ Our Offerings</h3><ul>';
                        offerings.forEach(item => {
                            html += '<li>' + escapeHtml(item) + '</li>';
                        });
                        html += '</ul>';
                    }
                }

                html += '<h3>📍 Visit Us</h3>';
                if (data.location) html += '<p><i class="fas fa-map-pin"></i> ' + escapeHtml(data.location) + '</p>';
                if (data.hours) html += '<p><i class="fas fa-clock"></i> ' + escapeHtml(data.hours) + '</p>';
                if (data.phone) html += '<p><i class="fas fa-phone"></i> ' + escapeHtml(data.phone) + '</p>';
                if (data.email) html += '<p><i class="fas fa-envelope"></i> ' + escapeHtml(data.email) + '</p>';

                drawerBody.innerHTML = html;
            })
            .catch(err => console.warn('About content polling error:', err));
    }

    // Helper to escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Poll every 5 seconds (same as menu)
    setInterval(updateAboutContent, 5000);

    // ============================================================
    // SCROLL‑TO‑TOP BUTTON
    // ============================================================
    const scrollBtn = document.getElementById('scrollTopBtn');
    if (scrollBtn) {
        function toggleScrollButton() {
            const scrollY = window.scrollY;
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            const threshold = docHeight * 0.5;
            if (scrollY > threshold) {
                scrollBtn.classList.add('visible');
            } else {
                scrollBtn.classList.remove('visible');
            }
        }

        let ticking = false;
        window.addEventListener('scroll', function() {
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    toggleScrollButton();
                    ticking = false;
                });
                ticking = true;
            }
        });

        scrollBtn.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        toggleScrollButton();
    }

    // ============================================================
    // CLOSE ERROR ALERT (with nice styling & animation)
    // ============================================================
    // We'll handle the close button in the updateTableStatus function,
    // but also keep a global listener for the initial one.
    const initialCloseBtn = document.getElementById('errorCloseBtn');
    if (initialCloseBtn) {
        initialCloseBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const alert = document.getElementById('errorAlert');
            if (alert) {
                alert.style.transition = 'opacity 0.3s ease';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 300);
            }
        });
    }

    // ============================================================
    // INIT
    // ============================================================
    // Pre-populate category data from initial page load if available
    if (window.allCategories) {
        window._menuCategories = window.allCategories;
    }
    // Apply initial filters
    applyFilters();
    // Trigger first menu update to sync table status
    updateMenu();
    // Fetch about content initially
    updateAboutContent();
});