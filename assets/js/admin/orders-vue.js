// assets/js/admin/orders-vue.js – Vue 3 admin orders (Phase 5, fixed)
// =========================================================================
// Phase 5: real-time console — chime, toast, row flash, title badge,
// optimistic mark-ready with rollback, smoother calendar.

function startOrdersApp() {
    if (typeof Vue === 'undefined') {
        console.error('[orders-vue] Vue 3 not loaded');
        return;
    }

    const { createApp, reactive, computed } = Vue;
    const initial = window.__ORDERS_DATA__ || {};

    const POLL = {
        baseMs:     5000,
        initialMs:  2000,
        maxMs:      60000,
        multiplier: 2,
    };

    const BASE_TITLE = document.title;

    // ================================================================
    // ====== AUDIO (Web Audio API — no asset file needed) ======
    // ================================================================
    let audioCtx = null;
    let audioUnlocked = false;

    function unlockAudio() {
        if (audioUnlocked) return;
        try {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            audioUnlocked = true;
        } catch (e) {
            console.warn('[orders-vue] Audio unlock failed:', e);
        }
    }
    document.addEventListener('click', unlockAudio, { once: true });
    document.addEventListener('keydown', unlockAudio, { once: true });
    document.addEventListener('touchstart', unlockAudio, { once: true });

    function playNewOrderChime() {
        if (!store.soundEnabled) return;
        if (!audioUnlocked || !audioCtx) return;
        try {
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const now = audioCtx.currentTime;
            function tone(freq, startAt, duration, peakGain) {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, now + startAt);
                gain.gain.exponentialRampToValueAtTime(peakGain, now + startAt + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + startAt + duration);
                osc.connect(gain).connect(audioCtx.destination);
                osc.start(now + startAt);
                osc.stop(now + startAt + duration + 0.05);
            }
            tone(880,  0.00, 0.28, 0.14);
            tone(1320, 0.16, 0.32, 0.12);
        } catch (e) {
            console.warn('[orders-vue] Chime failed:', e);
        }
    }

    // ================================================================
    // ====== STORE ======
    // ================================================================
    const store = reactive({
        date:           initial.date || '',
        version:        initial.version || 0,
        orders:         initial.orders || [],
        dateList:       initial.dateList || [],
        dateListJalali: initial.dateListJalali || [],
        minDate:        initial.minDate || '',
        maxDate:        initial.maxDate || '',

        calendarOpen:       false,
        calendarYear:       initial.jalaliYear || 1400,
        calendarMonth:      initial.jalaliMonth || 1,
        calendarDays:       [],
        calendarFirstDay:   0,
        calendarDayNames:   ['Su','Mo','Tu','We','Th','Fr','Sa'],
        calendarMonthName:  '',
        calendarLoading:    false,
        calendarDirection:  'next',

        showCompleted: localStorage.getItem('orders_showCompleted') !== 'false',
        openOrderId:   null,
        markingReady:  null,

        soundEnabled:  localStorage.getItem('orders_sound_enabled') !== 'false',

        newOrderToast: null,
        flashingIds:   [],

        polling: {
            inFlight:          false,
            failures:          0,
            currentIntervalMs: POLL.baseMs,
            timerId:           null,
            isVisible:         typeof document !== 'undefined' ? !document.hidden : true,
        },
    });

    window.__ORDERS_STORE__ = store;

    const seenOrderIds = new Set((initial.orders || []).map(o => o.id));

    // ================================================================
    // ====== HELPERS ======
    // ================================================================
    function formatPrice(n) {
        return ('' + n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function navigateToDate(isoDate) {
        window.location.href = '?date=' + encodeURIComponent(isoDate);
    }

    function toggleShowCompleted() {
        store.showCompleted = !store.showCompleted;
        localStorage.setItem('orders_showCompleted', store.showCompleted ? 'true' : 'false');
    }

    function toggleSound() {
        store.soundEnabled = !store.soundEnabled;
        localStorage.setItem('orders_sound_enabled', store.soundEnabled ? 'true' : 'false');
        if (store.soundEnabled) {
            unlockAudio();
            playNewOrderChime();
        }
    }

    function openOrderModal(orderId) { store.openOrderId = orderId; }
    function closeOrderModal()       { store.openOrderId = null; }

    // Row class helper — returns a plain object so Vue never chokes on
    // inline array+object syntax inside the template.
    function rowClass(order) {
        const cls = {};
        if (order.is_ready) cls.ready = true;
        else                cls.pending = true;
        if (store.flashingIds.indexOf(order.id) !== -1) cls['row-flash'] = true;
        return cls;
    }

    // ================================================================
    // ====== MARK READY (optimistic) ======
    // ================================================================
    async function markReady(orderId) {
        if (!confirm('Mark order #' + orderId + ' as ready?')) return;
        if (store.markingReady) return;

        const order = store.orders.find(o => o.id === orderId);
        if (!order || order.is_ready) return;

        const wasReady = order.is_ready;
        order.is_ready = true;
        store.markingReady = orderId;

        const fd = new FormData();
        fd.append('id', String(orderId));

        try {
            const res = await fetch('orders.php?api=mark_ready', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (!data.success) {
                order.is_ready = wasReady;
                alert('Could not mark order ready. Please try again.');
            }
        } catch (err) {
            console.warn('[orders-vue] mark_ready failed:', err);
            order.is_ready = wasReady;
            alert('Network error. Please try again.');
        } finally {
            store.markingReady = null;
            updateDocumentTitle();
        }
    }

    // ================================================================
    // ====== NEW ORDER ALERTS ======
    // ================================================================
    function detectNewOrders(incomingOrders) {
        if (!Array.isArray(incomingOrders)) return;
        const pending = incomingOrders.filter(o => !o.is_ready);
        const newPending = pending.filter(o => !seenOrderIds.has(o.id));

        incomingOrders.forEach(o => seenOrderIds.add(o.id));

        if (newPending.length === 0) return;

        const latest = newPending.reduce((a, b) => (a.id > b.id ? a : b));

        playNewOrderChime();
        showNewOrderToast(latest, newPending.length);
        flashRows(newPending.map(o => o.id));
    }

    function showNewOrderToast(latestOrder, count) {
        if (store.newOrderToast && store.newOrderToast.timeoutId) {
            clearTimeout(store.newOrderToast.timeoutId);
        }
        const timeoutId = setTimeout(() => {
            if (store.newOrderToast && store.newOrderToast.latestId === latestOrder.id) {
                store.newOrderToast = null;
            }
        }, 8000);
        store.newOrderToast = {
            latestId:  latestOrder.id,
            table:     latestOrder.table,
            count:     count,
            timeoutId: timeoutId,
        };
    }

    function dismissToast() {
        if (store.newOrderToast && store.newOrderToast.timeoutId) {
            clearTimeout(store.newOrderToast.timeoutId);
        }
        store.newOrderToast = null;
    }

    function viewNewOrder() {
        if (!store.newOrderToast) return;
        const id = store.newOrderToast.latestId;
        dismissToast();
        store.openOrderId = id;
    }

    function flashRows(ids) {
        ids.forEach(id => {
            if (store.flashingIds.indexOf(id) === -1) store.flashingIds.push(id);
        });
        setTimeout(() => {
            store.flashingIds = store.flashingIds.filter(id => ids.indexOf(id) === -1);
        }, 3000);
    }

    function updateDocumentTitle() {
        const pendingCount = store.orders.filter(o => !o.is_ready).length;
        document.title = pendingCount > 0
            ? '(' + pendingCount + ') ' + BASE_TITLE
            : BASE_TITLE;
    }

    // ================================================================
    // ====== COMPUTED ======
    // ================================================================
    const visibleOrders = computed(() => {
        if (store.showCompleted) return store.orders;
        return store.orders.filter(o => !o.is_ready);
    });
    const pendingCount = computed(() => store.orders.filter(o => !o.is_ready).length);
    const readyCount   = computed(() => store.orders.filter(o =>  o.is_ready).length);

    // ================================================================
    // ====== CALENDAR ======
    // ================================================================
    function toggleCalendar() {
        store.calendarOpen = !store.calendarOpen;
        if (store.calendarOpen) loadCalendar(store.calendarYear, store.calendarMonth);
    }

    function loadCalendar(year, month) {
        store.calendarLoading = true;
        const url = 'orders.php?api=calendar'
            + '&jYear=' + encodeURIComponent(year)
            + '&jMonth=' + encodeURIComponent(month)
            + '&selected=' + encodeURIComponent(store.date)
            + '&_=' + Date.now();

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (data.error) { console.warn('Calendar error:', data.error); return; }
                store.calendarYear      = data.year;
                store.calendarMonth     = data.month;
                store.calendarMonthName = data.monthName;
                store.calendarFirstDay  = data.firstDayOfWeek;
                store.calendarDayNames  = data.dayNames;
                store.calendarDays      = data.days;
            })
            .catch(err => console.warn('[orders-vue] Calendar fetch failed:', err))
            .finally(() => { store.calendarLoading = false; });
    }

    function changeCalendarMonth(delta) {
        let m = store.calendarMonth + delta;
        let y = store.calendarYear;
        if (m > 12) { m = 1; y++; }
        if (m < 1)  { m = 12; y--; }
        store.calendarYear  = y;
        store.calendarMonth = m;
        store.calendarDirection = delta > 0 ? 'next' : 'prev';
        loadCalendar(y, m);
    }

    function pickCalendarDay(day) {
        if (day.disabled) return;
        navigateToDate(day.gregorian);
    }

    function jumpToToday() {
        navigateToDate(store.maxDate);
    }

    // ================================================================
    // ====== POLLING ======
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

        const url = 'orders.php?api=poll'
            + '&date=' + encodeURIComponent(store.date)
            + '&version=' + encodeURIComponent(store.version)
            + '&_=' + Date.now();

        return fetch(url)
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(data => {
                store.polling.failures = 0;
                store.polling.currentIntervalMs = POLL.baseMs;

                if (data.unchanged) return;
                if (typeof data.version === 'number') store.version = data.version;
                if (Array.isArray(data.orders)) {
                    detectNewOrders(data.orders);
                    store.orders = data.orders;
                    updateDocumentTitle();
                }
            })
            .catch(err => {
                store.polling.failures++;
                const backoff = POLL.baseMs * Math.pow(POLL.multiplier, store.polling.failures);
                store.polling.currentIntervalMs = Math.min(backoff, POLL.maxMs);
                console.warn(
                    '[orders-vue] Poll failed (attempt ' + store.polling.failures + ') — '
                    + 'next retry in ' + store.polling.currentIntervalMs + 'ms:',
                    err
                );
            })
            .finally(() => { store.polling.inFlight = false; });
    }

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
    // ====== COMPONENTS ======
    // ================================================================

    const ReconnectingIndicator = {
        template: '<div v-if="show" class="reconnecting-indicator"><i class="fas fa-circle-notch fa-spin"></i><span>Reconnecting…</span></div>',
        setup() {
            const show = computed(() => store.polling.failures >= 2);
            return { show };
        }
    };

    // Toast — no <transition> wrapper. Uses a CSS animation on mount.
    const NewOrderToast = {
        template: [
            '<div v-if="toast" class="new-order-toast">',
            '  <div class="toast-icon"><i class="fas fa-bell"></i></div>',
            '  <div class="toast-body">',
            '    <div class="toast-title">',
            '      <template v-if="toast.count > 1">{{ toast.count }} new orders</template>',
            '      <template v-else>New order #{{ toast.latestId }}</template>',
            '    </div>',
            '    <div class="toast-sub">Table {{ toast.table }} · just now</div>',
            '  </div>',
            '  <div class="toast-actions">',
            '    <button type="button" class="toast-btn toast-btn-view" @click="view">View</button>',
            '    <button type="button" class="toast-btn toast-btn-close" @click="dismiss" aria-label="Dismiss">&times;</button>',
            '  </div>',
            '</div>'
        ].join('\n'),
        setup() {
            const toast = computed(() => store.newOrderToast);
            function dismiss() { dismissToast(); }
            function view() { viewNewOrder(); }
            return { toast, dismiss, view };
        }
    };

    const JalaliCalendar = {
        template: [
            '<div v-if="store.calendarOpen" class="calendar-popup" @click.stop>',
            '  <div class="calendar-header">',
            '    <button type="button" @click="prevMonth" aria-label="Previous month">',
            '      <i class="fas fa-chevron-left"></i>',
            '    </button>',
            '    <span class="calendar-title">{{ store.calendarMonthName }} {{ store.calendarYear }}</span>',
            '    <button type="button" @click="nextMonth" aria-label="Next month">',
            '      <i class="fas fa-chevron-right"></i>',
            '    </button>',
            '  </div>',
            '  <div class="calendar-body" :class="directionClass">',
            '    <div v-if="store.calendarLoading" class="calendar-loading">',
            '      <i class="fas fa-circle-notch fa-spin"></i>',
            '    </div>',
            '    <div class="calendar-grid" :class="{ \'is-loading\': store.calendarLoading }">',
            '      <div v-for="name in store.calendarDayNames" :key="\'h-\' + name" class="calendar-day-name">',
            '        {{ name }}',
            '      </div>',
            '      <div v-for="i in store.calendarFirstDay" :key="\'b-\' + i" class="day-cell blank"></div>',
            '      <div v-for="day in store.calendarDays"',
            '           :key="day.jalali"',
            '           class="day-cell"',
            '           :class="dayClass(day)"',
            '           @click="pick(day)">',
            '        {{ day.day }}',
            '      </div>',
            '    </div>',
            '  </div>',
            '  <div class="calendar-footer">',
            '    <button type="button" class="calendar-today-btn" @click="goToday">',
            '      <i class="fas fa-calendar-day"></i> Jump to today',
            '    </button>',
            '  </div>',
            '  <div class="calendar-legend">',
            '    <span><span class="dot green"></span> All ready</span>',
            '    <span><span class="dot red"></span> Some pending</span>',
            '    <span><span class="dot gray"></span> No orders</span>',
            '  </div>',
            '</div>'
        ].join('\n'),
        setup() {
            const directionClass = computed(() => 'slide-' + store.calendarDirection);

            function dayClass(day) {
                const cls = {};
                cls[day.status] = true;
                if (day.disabled)  cls.disabled = true;
                if (day.isToday)   cls.today = true;
                if (day.isSelected) cls.selected = true;
                return cls;
            }

            return {
                store,
                directionClass,
                dayClass,
                prevMonth: () => changeCalendarMonth(-1),
                nextMonth: () => changeCalendarMonth(1),
                pick: pickCalendarDay,
                goToday: jumpToToday,
            };
        }
    };

    const Toolbar = {
        components: { JalaliCalendar },
        template: [
            '<div class="orders-toolbar">',
            '  <div class="toolbar-left" style="position:relative;">',
            '    <span class="toolbar-label"><i class="fas fa-calendar"></i> Date:</span>',
            '    <select class="date-selector" :value="store.date" @change="onDateChange">',
            '      <option v-for="(d, i) in store.dateList" :key="d" :value="d">',
            '        {{ store.dateListJalali[i] }}{{ d === store.maxDate ? \' (Today)\' : \'\' }}',
            '      </option>',
            '    </select>',
            '    <button type="button"',
            '            class="btn-calendar-toggle"',
            '            :class="{ active: store.calendarOpen }"',
            '            @click="toggleCalendar"',
            '            aria-label="Open Jalali calendar">',
            '      <i class="fas fa-calendar-alt"></i>',
            '    </button>',
            '    <button class="btn-today" @click="goToday">Today</button>',
            '    <JalaliCalendar></JalaliCalendar>',
            '  </div>',
            '  <div class="toolbar-center">',
            '    <span class="stat-pill stat-pending">',
            '      <i class="fas fa-hourglass-half"></i> Pending: {{ pendingCount }}',
            '    </span>',
            '    <span class="stat-pill stat-ready">',
            '      <i class="fas fa-check-circle"></i> Ready: {{ readyCount }}',
            '    </span>',
            '  </div>',
            '  <div class="toolbar-right">',
            '    <button class="btn-sound"',
            '            :class="{ active: store.soundEnabled }"',
            '            @click="toggleSound"',
            '            :title="store.soundEnabled ? \'Sound on\' : \'Sound off\'"',
            '            :aria-label="store.soundEnabled ? \'Mute new order sound\' : \'Enable new order sound\'">',
            '      <i :class="store.soundEnabled ? \'fas fa-bell\' : \'fas fa-bell-slash\'"></i>',
            '    </button>',
            '    <button class="btn-toggle-completed"',
            '            :class="{ active: store.showCompleted }"',
            '            @click="toggleShowCompleted">',
            '      <i :class="store.showCompleted ? \'fas fa-eye-slash\' : \'fas fa-eye\'"></i>',
            '      {{ store.showCompleted ? \'Hide Completed\' : \'Show Completed\' }}',
            '    </button>',
            '  </div>',
            '</div>'
        ].join('\n'),
        setup() {
            function onDateChange(e) { navigateToDate(e.target.value); }
            function goToday() { navigateToDate(store.maxDate); }
            return {
                store, pendingCount, readyCount,
                onDateChange, goToday,
                toggleShowCompleted, toggleCalendar, toggleSound,
            };
        }
    };

    const OrdersTable = {
        template: [
            '<div class="orders-table-wrap">',
            '  <table class="order-table" id="orderTable">',
            '    <thead>',
            '      <tr>',
            '        <th>Order #</th>',
            '        <th>Table</th>',
            '        <th>Items</th>',
            '        <th>Note</th>',
            '        <th>Time</th>',
            '        <th>Status</th>',
            '        <th>Action</th>',
            '      </tr>',
            '    </thead>',
            '    <tbody>',
            '      <tr v-if="visibleOrders.length === 0">',
            '        <td colspan="7" class="empty-row">',
            '          <i class="fas fa-inbox"></i> No orders on this date.',
            '        </td>',
            '      </tr>',
            '      <tr v-for="order in visibleOrders"',
            '          :key="order.id"',
            '          :class="rowClass(order)"',
            '          :data-ready="order.is_ready ? \'1\' : \'0\'"',
            '          :data-id="order.id">',
            '        <td data-label="Order #"><strong>#{{ order.id }}</strong></td>',
            '        <td data-label="Table"><i class="fas fa-chair"></i> {{ order.table }}</td>',
            '        <td data-label="Items" class="order-items" @click="openModal(order.id)">',
            '          <div v-for="item in order.items" :key="item.id" class="item-line">',
            '            <strong>{{ item.name }}</strong> ×{{ item.qty }}',
            '            <span class="item-subtotal">({{ formatPrice(item.subtotal) }} T)</span>',
            '          </div>',
            '          <div class="order-summary">',
            '            <i class="fas fa-cubes"></i> {{ order.total_qty }} items',
            '            <i class="fas fa-coins" style="margin-left:0.8rem;"></i>',
            '            {{ formatPrice(order.total_price) }} T',
            '            <span class="expand-hint"><i class="fas fa-expand"></i></span>',
            '          </div>',
            '        </td>',
            '        <td data-label="Note">{{ order.note || \'—\' }}</td>',
            '        <td data-label="Time" class="order-time">{{ order.time }}</td>',
            '        <td data-label="Status">',
            '          <span class="badge" :class="badgeClass(order)">',
            '            {{ order.is_ready ? \'Ready\' : \'Pending\' }}',
            '          </span>',
            '        </td>',
            '        <td data-label="Action">',
            '          <button v-if="!order.is_ready"',
            '                  class="btn-ready"',
            '                  :disabled="store.markingReady === order.id"',
            '                  @click="markReady(order.id)">',
            '            <i class="fas fa-check"></i>',
            '            {{ store.markingReady === order.id ? \'Saving...\' : \'Ready\' }}',
            '          </button>',
            '          <span v-else class="done-label"><i class="fas fa-check-circle"></i> Done</span>',
            '        </td>',
            '      </tr>',
            '    </tbody>',
            '  </table>',
            '</div>'
        ].join('\n'),
        setup() {
            function badgeClass(order) {
                return order.is_ready ? 'badge-ready' : 'badge-pending';
            }
            return {
                store,
                visibleOrders,
                formatPrice,
                markReady,
                rowClass,
                badgeClass,
                openModal: openOrderModal,
            };
        }
    };

    const OrderItemsModal = {
        template: [
            '<div v-if="order" class="order-modal active" @click.self="close">',
            '  <div class="order-modal-content">',
            '    <button class="order-modal-close" @click="close">&times;</button>',
            '    <h2><i class="fas fa-receipt"></i> Order #{{ order.id }}</h2>',
            '    <div class="order-meta">',
            '      <span><i class="fas fa-chair"></i> Table {{ order.table }}</span>',
            '      <span><i class="fas fa-clock"></i> {{ order.time }}</span>',
            '      <span>',
            '        <i class="fas fa-circle" :style="dotStyle(order)"></i>',
            '        {{ order.is_ready ? \'Ready\' : \'Pending\' }}',
            '      </span>',
            '    </div>',
            '    <ul>',
            '      <li v-for="item in order.items" :key="item.id">',
            '        <span class="item-name">{{ item.name }}</span>',
            '        <span class="item-qty">× {{ item.qty }}</span>',
            '        <span class="item-subtotal">{{ formatPrice(item.subtotal) }} T</span>',
            '      </li>',
            '    </ul>',
            '    <div class="order-total">',
            '      <span><i class="fas fa-cubes"></i> {{ order.total_qty }} items</span>',
            '      <span><i class="fas fa-coins"></i> {{ formatPrice(order.total_price) }} T</span>',
            '    </div>',
            '    <div v-if="order.note" class="order-note">',
            '      <i class="fas fa-pen"></i> {{ order.note }}',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n'),
        setup() {
            const order = computed(() => {
                if (store.openOrderId === null) return null;
                return store.orders.find(o => o.id === store.openOrderId) || null;
            });
            function dotStyle(o) {
                return { color: o.is_ready ? '#22c55e' : '#f59e0b', fontSize: '0.6rem' };
            }
            return { order, close: closeOrderModal, formatPrice, dotStyle };
        }
    };

    const OrdersApp = {
        components: {
            ReconnectingIndicator,
            NewOrderToast,
            Toolbar,
            OrdersTable,
            OrderItemsModal,
        },
        template: '<div><ReconnectingIndicator></ReconnectingIndicator><NewOrderToast></NewOrderToast><Toolbar></Toolbar><OrdersTable></OrdersTable><OrderItemsModal></OrderItemsModal></div>'
    };

    // ================================================================
    // ====== GLOBAL HANDLERS ======
    // ================================================================
    document.addEventListener('click', function (e) {
        if (!store.calendarOpen) return;
        const popup = document.querySelector('.calendar-popup');
        const btn = document.querySelector('.btn-calendar-toggle');
        if (popup && !popup.contains(e.target) && btn && !btn.contains(e.target)) {
            store.calendarOpen = false;
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && store.calendarOpen) store.calendarOpen = false;
        if (e.key === 'Escape' && store.openOrderId !== null) store.openOrderId = null;
    });

    // ================================================================
    // ====== MOUNT ======
    // ================================================================
    const mountEl = document.getElementById('vue-orders-root');
    if (mountEl) {
        try {
            createApp(OrdersApp).mount('#vue-orders-root');
            console.log('[orders-vue] Mounted (Phase 5). Version:', store.version);
        } catch (err) {
            console.error('[orders-vue] Mount failed:', err);
        }
    }

    updateDocumentTitle();
    scheduleNext(POLL.initialMs);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startOrdersApp);
} else {
    startOrdersApp();
}