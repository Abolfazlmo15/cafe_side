// assets/js/client/menu-vue.js – Vue 3 app for the customer menu
// ====================================================================
// Phase 3: Page Visibility API + exponential backoff + reconnect indicator.

function startMenuApp() {
    if (typeof Vue === 'undefined') {
        console.error('Vue 3 not loaded');
        return;
    }

    const { createApp, reactive, computed } = Vue;
    const initial = window.__INITIAL_DATA__ || {};

    // ================================================================
    // ====== POLL CONFIG ======
    // ================================================================
    const POLL = {
        baseMs:        5000,    // normal interval
        initialMs:     2000,    // first poll delay after mount
        maxMs:         60000,   // backoff ceiling
        multiplier:    2,       // backoff factor
    };

    // ================================================================
    // ====== SANITIZATION HELPERS ======
    // ================================================================
    function sanitizeCart(rawCart, validIds) {
        const clean = {};
        if (!rawCart || typeof rawCart !== 'object') return clean;
        Object.keys(rawCart).forEach(function (key) {
            const id = parseInt(key, 10);
            const qty = parseInt(rawCart[key], 10);
            if (isNaN(id) || id <= 0) return;
            if (isNaN(qty) || qty <= 0) return;
            if (validIds && !validIds[String(id)]) return;
            clean[String(id)] = qty;
        });
        return clean;
    }

    function buildValidIds(menu) {
        const ids = {};
        if (menu && Array.isArray(menu.items)) {
            menu.items.forEach(it => { ids[String(it.id)] = true; });
        }
        return ids;
    }

    const validIds = buildValidIds(initial.menu);

    // ================================================================
    // ====== STORE ======
    // ================================================================
    const store = reactive({
        table: {
            number: initial.table || 0,
            valid: initial.tableValid !== false,
            invalidMessage: initial.tableInvalidMessage || ''
        },
        deviceToken: initial.deviceToken || '',
        menu: {
            version: initial.version || 0,
            categories: (initial.menu && initial.menu.categories) || ['All'],
            items: (initial.menu && initial.menu.items) || []
        },
        cart: sanitizeCart(initial.cart, validIds),
        orders: {
            pending: (initial.orders && initial.orders.pending_order_ids) || [],
            ready: (initial.orders && initial.orders.ready_order_ids) || [],
            activeItems: (initial.orders && initial.orders.active_items) || []
        },
        ui: {
            activeCategory: 'All',
            searchQuery: '',
            imageModalSrc: null,
            errorMessage: initial.tableValid === false
                ? (initial.tableInvalidMessage || '')
                : ''
        },
        polling: {
            inFlight:         false,
            failures:         0,
            currentIntervalMs: POLL.baseMs,
            timerId:          null,
            isVisible:        typeof document !== 'undefined' ? !document.hidden : true,
            lastSuccessAt:    null,
        }
    });

    window.__STORE__ = store;

    function formatPrice(n) {
        return ('' + n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // ================================================================
    // ====== COMPONENTS ======
    // ================================================================

    const CategoryPills = {
        template: `
            <div class="categories-wrap" id="categoryContainer">
                <button v-for="cat in store.menu.categories"
                        :key="cat"
                        type="button"
                        class="cat-pill"
                        :class="{ active: store.ui.activeCategory === cat }"
                        @click="store.ui.activeCategory = cat">
                    {{ cat }}
                </button>
            </div>
        `,
        setup() { return { store }; }
    };

    const MenuCard = {
        props: ['item'],
        template: `
            <div class="coffee-card" :data-id="item.id" :data-category="item.category">
                <div class="card-img">
                    <img v-if="item.image" :src="item.image" :alt="item.name"
                         class="menu-item-img" :data-full="item.image"
                         @click="store.ui.imageModalSrc = item.image">
                    <i v-else class="fas fa-mug-saucer"></i>
                </div>
                <div class="card-body">
                    <h4 class="card-title">{{ item.name }}</h4>
                    <p class="card-desc">{{ item.description || 'Served with love' }}</p>
                    <div class="card-footer">
                        <span class="price">{{ formatPrice(item.price) }} T</span>
                        <div class="qty-controls">
                            <button class="qty-minus" type="button" @click="decrement">-</button>
                            <input type="number" class="qty-input" :value="qty"
                                   min="0" max="99" :data-id="item.id"
                                   @input="onInput" @change="onInput">
                            <button class="qty-plus" type="button" @click="increment">+</button>
                        </div>
                    </div>
                </div>
            </div>
        `,
        setup(props) {
            const qty = computed(() => store.cart[String(props.item.id)] || 0);
            function setQty(v) {
                v = Math.max(0, Math.min(99, parseInt(v, 10) || 0));
                const key = String(props.item.id);
                if (v === 0) {
                    delete store.cart[key];
                } else {
                    store.cart[key] = v;
                }
            }
            return {
                store, qty, formatPrice,
                increment: () => setQty(qty.value + 1),
                decrement: () => setQty(qty.value - 1),
                onInput: (e) => setQty(e.target.value)
            };
        }
    };

    const MenuGrid = {
        components: { MenuCard },
        template: `
            <div id="menuContainer">
                <div v-if="groupedItems.length === 0" class="no-items">No menu items available.</div>
                <div v-for="group in groupedItems" :key="group.category"
                     class="category-group" :data-category="group.category">
                    <h3 class="category-title">{{ group.category }}</h3>
                    <div class="coffee-grid">
                        <MenuCard v-for="item in group.items" :key="item.id" :item="item"></MenuCard>
                    </div>
                </div>
            </div>
        `,
        setup() {
            const groupedItems = computed(() => {
                const activeCat = store.ui.activeCategory;
                const query = store.ui.searchQuery.toLowerCase().trim();
                const filtered = store.menu.items.filter(item => {
                    const matchesCat = (activeCat === 'All' || item.category === activeCat);
                    const matchesSearch = !query
                        || item.name.toLowerCase().includes(query)
                        || (item.description || '').toLowerCase().includes(query);
                    return matchesCat && matchesSearch;
                });
                const groups = {};
                filtered.forEach(item => {
                    if (!groups[item.category]) groups[item.category] = [];
                    groups[item.category].push(item);
                });
                return Object.keys(groups).sort().map(cat => ({ category: cat, items: groups[cat] }));
            });
            return { store, groupedItems };
        }
    };

    const Banner = {
        template: `
            <div v-if="hasOrders" class="pending-banner" id="orderBanner">
                <i class="fas fa-clock"></i>
                <div>
                    <template v-if="pending.length > 0">
                        <strong>Your active orders:</strong>
                        <span style="color:#78350f;"> {{ activeItemsText }}</span><br>
                        <span style="font-size:0.85rem; color:#92400e;">Orders: <span v-html="orderListHtml"></span></span>
                    </template>
                    <template v-else>
                        <strong>All your orders are ready!</strong>
                        <span style="font-size:0.85rem; color:#065f46;">Orders: <span v-html="orderListHtml"></span></span>
                    </template>
                </div>
            </div>
        `,
        setup() {
            const pending = computed(() => store.orders.pending);
            const hasOrders = computed(() => store.orders.pending.length > 0 || store.orders.ready.length > 0);
            const activeItemsText = computed(() =>
                store.orders.activeItems.map(i => i.name + ' ×' + i.qty).join(', '));
            const orderListHtml = computed(() => {
                const parts = [];
                store.orders.pending.forEach(id => parts.push('<span style="color:#dc2626;font-weight:600;">#' + id + '</span>'));
                store.orders.ready.forEach(id => parts.push('<span style="color:#22c55e;font-weight:600;">#' + id + '</span>'));
                return parts.join(' ');
            });
            return { pending, hasOrders, activeItemsText, orderListHtml };
        }
    };

    const ErrorAlert = {
        template: `
            <div v-if="message" class="alert alert-error dismissible" id="errorAlert">
                <i class="fas fa-exclamation-circle"></i>
                <span>{{ message }}</span>
                <button class="close-btn" @click="store.ui.errorMessage = ''" aria-label="Close">&times;</button>
            </div>
        `,
        setup() {
            const message = computed(() => store.ui.errorMessage);
            return { store, message };
        }
    };

    const ImageModal = {
        template: `
            <div v-if="src" class="image-modal active">
                <div class="modal-content">
                    <button class="modal-close" @click="store.ui.imageModalSrc = null">&times;</button>
                    <img :src="src" alt="Full view">
                </div>
            </div>
        `,
        setup() {
            const src = computed(() => store.ui.imageModalSrc);
            return { store, src };
        }
    };

    const CartBar = {
        template: `
            <div v-if="itemCount > 0" class="cart-bar">
                <div class="cart-bar-info">
                    <span class="cart-bar-count">{{ itemCount }} {{ itemCount === 1 ? 'item' : 'items' }}</span>
                    <span class="cart-bar-sep">·</span>
                    <span class="cart-bar-total">{{ formatPrice(total) }} T</span>
                </div>
                <button type="button" class="cart-bar-btn" @click="submitOrder">
                    <i class="fas fa-paper-plane"></i> Place Order
                </button>
            </div>
        `,
        setup() {
            const itemCount = computed(() => {
                let n = 0;
                Object.keys(store.cart).forEach(id => n += parseInt(store.cart[id], 10) || 0);
                return n;
            });
            const total = computed(() => {
                let sum = 0;
                Object.keys(store.cart).forEach(id => {
                    const qty = parseInt(store.cart[id], 10) || 0;
                    if (qty <= 0) return;
                    const item = store.menu.items.find(it => String(it.id) === String(id));
                    if (item) sum += item.price * qty;
                });
                return sum;
            });
            function submitOrder() {
                if (!store.table.valid) {
                    alert('Ordering is disabled – invalid table.');
                    return;
                }
                const clean = sanitizeCart(store.cart, validIds);
                if (Object.keys(clean).length === 0) {
                    alert('Please select at least one item.');
                    return;
                }
                document.getElementById('itemsJson').value = JSON.stringify(clean);
                document.getElementById('orderForm').submit();
            }
            return { store, itemCount, total, submitOrder, formatPrice };
        }
    };

    // --- NEW in Phase 3: reconnecting indicator ---
    const ReconnectingIndicator = {
        template: `
            <div v-if="show" class="reconnecting-indicator">
                <i class="fas fa-circle-notch fa-spin"></i>
                <span>Reconnecting…</span>
            </div>
        `,
        setup() {
            const show = computed(() => store.polling.failures >= 2);
            return { show };
        }
    };

    const MenuApp = {
        components: { CategoryPills, MenuGrid, Banner, ErrorAlert, ImageModal, CartBar, ReconnectingIndicator },
        template: `
            <div>
                <ReconnectingIndicator></ReconnectingIndicator>
                <Banner></Banner>
                <ErrorAlert></ErrorAlert>
                <CategoryPills></CategoryPills>
                <MenuGrid></MenuGrid>
                <ImageModal></ImageModal>
                <CartBar></CartBar>
            </div>
        `
    };

    // ================================================================
    // ====== SEARCH (vanilla → store) ======
    // ================================================================
    const sTog = document.getElementById('searchToggle');
    const sWrap = document.getElementById('searchWrap');
    const sInput = document.getElementById('searchInput');
    if (sTog && sWrap && sInput) {
        sTog.addEventListener('click', () => {
            sWrap.classList.toggle('active');
            if (sWrap.classList.contains('active')) sInput.focus();
        });
        sInput.addEventListener('input', () => { store.ui.searchQuery = sInput.value; });
    }

    // ================================================================
    // ====== ABOUT DRAWER (vanilla) ======
    // ================================================================
    const drawer = document.getElementById('aboutDrawer');
    const dOverlay = document.getElementById('drawerOverlay');
    const dClose = document.getElementById('drawerClose');
    const aTog = document.getElementById('aboutToggle');
    if (drawer && dOverlay && dClose && aTog) {
        aTog.addEventListener('click', () => {
            drawer.classList.add('open');
            dOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
        function closeD() {
            drawer.classList.remove('open');
            dOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        dClose.addEventListener('click', closeD);
        dOverlay.addEventListener('click', closeD);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeD(); });
    }
    function esc(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    function updateAbout(data) {
        const body = document.querySelector('#aboutDrawer .drawer-body');
        if (!body || !data) return;
        let h = '';
        if (data.welcome) h += '<p><strong>' + esc(data.welcome) + '</strong></p>';
        if (data.offerings) {
            const o = data.offerings.split('\n').map(s => s.trim()).filter(s => s);
            if (o.length) { h += '<h3>☕ Our Offerings</h3><ul>'; o.forEach(i => h += '<li>' + esc(i) + '</li>'); h += '</ul>'; }
        }
        h += '<h3>📍 Visit Us</h3>';
        if (data.location) h += '<p><i class="fas fa-map-pin"></i> ' + esc(data.location) + '</p>';
        if (data.hours) h += '<p><i class="fas fa-clock"></i> ' + esc(data.hours) + '</p>';
        if (data.phone) h += '<p><i class="fas fa-phone"></i> ' + esc(data.phone) + '</p>';
        if (data.email) h += '<p><i class="fas fa-envelope"></i> ' + esc(data.email) + '</p>';
        body.innerHTML = h;
    }

    // ================================================================
    // ====== SCROLL TOP ======
    // ================================================================
    const scrollBtn = document.getElementById('scrollTopBtn');
    if (scrollBtn) {
        let tick = false;
        function chk() {
            const y = window.scrollY;
            const dH = document.documentElement.scrollHeight - window.innerHeight;
            if (y > dH * 0.5) scrollBtn.classList.add('visible');
            else scrollBtn.classList.remove('visible');
        }
        window.addEventListener('scroll', () => {
            if (!tick) { window.requestAnimationFrame(() => { chk(); tick = false; }); tick = true; }
        });
        scrollBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
        chk();
    }

    // ================================================================
    // ====== POLLING (Phase 3 version) ======
    // ================================================================
    function stopPolling() {
        if (store.polling.timerId !== null) {
            clearTimeout(store.polling.timerId);
            store.polling.timerId = null;
        }
    }

    function scheduleNext(delayMs) {
        stopPolling();
        store.polling.timerId = setTimeout(runPollCycle, delayMs);
    }

    function runPollCycle() {
        store.polling.timerId = null;
        pollServer().finally(() => {
            if (store.polling.isVisible) {
                scheduleNext(store.polling.currentIntervalMs);
            }
        });
    }

    function pollServer() {
        if (store.polling.inFlight) return Promise.resolve();
        store.polling.inFlight = true;

        const url = 'menu.php?api=poll'
            + '&version=' + encodeURIComponent(store.menu.version)
            + '&table='   + encodeURIComponent(store.table.number)
            + '&token='   + encodeURIComponent(store.deviceToken)
            + '&_='       + Date.now();

        return fetch(url)
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                store.polling.failures = 0;
                store.polling.currentIntervalMs = POLL.baseMs;
                store.polling.lastSuccessAt = Date.now();

                if (data.unchanged) return;

                if (data.version) store.menu.version = data.version;

                if (data.menu) {
                    store.menu.items = data.menu.items || [];
                    store.menu.categories = data.menu.categories || ['All'];

                    const newValid = buildValidIds(data.menu);
                    for (const k in validIds) delete validIds[k];
                    Object.assign(validIds, newValid);

                    const cleaned = sanitizeCart(store.cart, validIds);
                    for (const k in store.cart) delete store.cart[k];
                    Object.assign(store.cart, cleaned);

                    if (store.ui.activeCategory !== 'All'
                        && !store.menu.categories.includes(store.ui.activeCategory)) {
                        store.ui.activeCategory = 'All';
                    }
                }

                if (data.table) {
                    store.table.valid = !!data.table.valid;
                    if (!store.table.valid) store.ui.errorMessage = data.table.message || '';
                }

                if (data.orders) {
                    store.orders.pending = data.orders.pending_order_ids || [];
                    store.orders.ready = data.orders.ready_order_ids || [];
                    store.orders.activeItems = data.orders.active_items || [];
                }

                if (data.about) updateAbout(data.about);
            })
            .catch(err => {
                store.polling.failures++;
                const backoff = POLL.baseMs * Math.pow(POLL.multiplier, store.polling.failures);
                store.polling.currentIntervalMs = Math.min(backoff, POLL.maxMs);

                console.warn(
                    '[menu-vue] Poll failed (attempt ' + store.polling.failures + ') — '
                    + 'next retry in ' + store.polling.currentIntervalMs + 'ms:',
                    err
                );
            })
            .finally(() => {
                store.polling.inFlight = false;
            });
    }

    // ---- Page Visibility API ----
    document.addEventListener('visibilitychange', () => {
        const nowVisible = !document.hidden;
        if (nowVisible === store.polling.isVisible) return;

        store.polling.isVisible = nowVisible;

        if (nowVisible) {
            store.polling.failures = 0;
            store.polling.currentIntervalMs = POLL.baseMs;
            runPollCycle();
        } else {
            stopPolling();
        }
    });

    // ================================================================
    // ====== MOUNT VUE ======
    // ================================================================
    const mountEl = document.getElementById('vue-menu-root');
    if (mountEl) {
        try {
            createApp(MenuApp).mount('#vue-menu-root');
            console.log('[menu-vue] Vue mounted. Cart:', store.cart);
        } catch (err) {
            console.error('[menu-vue] Mount failed:', err);
        }
    } else {
        console.error('[menu-vue] #vue-menu-root not found');
    }

    if (store.table.number > 0 && store.deviceToken) {
        scheduleNext(POLL.initialMs);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startMenuApp);
} else {
    startMenuApp();
}