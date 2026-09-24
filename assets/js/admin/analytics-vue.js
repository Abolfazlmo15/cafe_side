// assets/js/admin/analytics-vue.js
// Vue 3 analytics dashboard.
// Phase 4 + 3.2 + AI curation + Jalali calendar + friendly errors.
// ASCII-only source.

// =============================================================
// Jalali date utilities - client-side, no dependencies.
// =============================================================
var JalaliUtil = (function () {
    var MONTHS = ['Farvardin','Ordibehesht','Khordad','Tir','Mordad','Shahrivar',
                  'Mehr','Aban','Azar','Dey','Bahman','Esfand'];
    var SHORT  = ['Far','Ord','Kho','Tir','Mor','Sha',
                  'Meh','Aba','Aza','Dey','Bah','Esf'];

    function isLeapJalali(jy) {
        var r = jy % 33;
        return [1,5,9,13,17,22,26,30].indexOf(r) !== -1;
    }
    function isLeapGregorian(gy) {
        return ((gy % 4 === 0) && (gy % 100 !== 0)) || (gy % 400 === 0);
    }

    function gToJ(gYear, gMonth, gDay) {
        var gDays = [0,31,59,90,120,151,181,212,243,273,304,334];
        var gy = gYear - 1600;
        var gm = gMonth - 1;
        var gd = gDay - 1;

        var gDayNo = 365 * gy
            + Math.floor((gy + 3) / 4)
            - Math.floor((gy + 99) / 100)
            + Math.floor((gy + 399) / 400);
        gDayNo += gDays[gm];
        if (gm > 1 && ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0)) gDayNo++;
        gDayNo += gd;

        var jDayNo = gDayNo - 79;
        var jNp = Math.floor(jDayNo / 12053);
        jDayNo %= 12053;

        var jy = 979 + 33 * jNp + 4 * Math.floor(jDayNo / 1461);
        jDayNo %= 1461;

        if (jDayNo >= 366) {
            jy += Math.floor((jDayNo - 1) / 365);
            jDayNo = (jDayNo - 1) % 365;
        }

        var jMonths = [31,31,31,31,31,31,30,30,30,30,30,29];
        var jm = 1, jd = 1;
        for (var i = 0; i < 12; i++) {
            if (jDayNo < jMonths[i]) { jm = i + 1; jd = jDayNo + 1; break; }
            jDayNo -= jMonths[i];
        }
        return [jy, jm, jd];
    }

    function jToG(jYear, jMonth, jDay) {
        var jMonths = [31,31,31,31,31,31,30,30,30,30,30,29];
        if (isLeapJalali(jYear)) jMonths[11] = 30;

        var jDayNo = 0;
        for (var y = 1; y < jYear; y++) jDayNo += isLeapJalali(y) ? 366 : 365;
        for (var m = 0; m < jMonth - 1; m++) jDayNo += jMonths[m];
        jDayNo += jDay - 1;

        var gEpochDays = 226894;
        var gDayNo = jDayNo + gEpochDays;

        var gYear = 1;
        while (gDayNo >= 365) {
            var daysInYear = isLeapGregorian(gYear) ? 366 : 365;
            if (gDayNo < daysInYear) break;
            gDayNo -= daysInYear;
            gYear++;
        }

        var gMonths = [31,28,31,30,31,30,31,31,30,31,30,31];
        if (isLeapGregorian(gYear)) gMonths[1] = 29;
        var gMonth = 1;
        for (var i = 0; i < 12; i++) {
            if (gDayNo < gMonths[i]) break;
            gDayNo -= gMonths[i];
            gMonth++;
        }
        var gDay = gDayNo + 1;
        return [gYear, gMonth, gDay];
    }

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    function isoToParts(iso) {
        if (!iso || typeof iso !== 'string') return null;
        var p = iso.split('-');
        if (p.length < 3) return null;
        var gy = parseInt(p[0], 10), gm = parseInt(p[1], 10), gd = parseInt(p[2], 10);
        if (isNaN(gy) || isNaN(gm) || isNaN(gd)) return null;
        var j = gToJ(gy, gm, gd);
        return { year: j[0], month: j[1], day: j[2] };
    }

    function partsToIso(jy, jm, jd) {
        var g = jToG(jy, jm, jd);
        return g[0] + '-' + pad2(g[1]) + '-' + pad2(g[2]);
    }

    function daysInMonth(jy, jm) {
        if (jm <= 6) return 31;
        if (jm <= 11) return 30;
        return isLeapJalali(jy) ? 30 : 29;
    }

    function firstDayOfWeek(jy, jm) {
        var g = jToG(jy, jm, 1);
        var d = new Date(g[0], g[1] - 1, g[2]);
        return d.getDay();
    }

    return {
        numeric: function (iso) {
            var j = isoToParts(iso);
            if (!j) return iso || '';
            return j.year + '/' + pad2(j.month) + '/' + pad2(j.day);
        },
        long: function (iso) {
            var j = isoToParts(iso);
            if (!j) return iso || '';
            return j.day + ' ' + MONTHS[j.month - 1] + ' ' + j.year;
        },
        short: function (iso) {
            var j = isoToParts(iso);
            if (!j) return iso || '';
            return j.day + ' ' + SHORT[j.month - 1];
        },
        toParts: isoToParts,
        toIso: partsToIso,
        daysInMonth: daysInMonth,
        firstDayOfWeek: firstDayOfWeek,
        isLeapJalali: isLeapJalali,
        monthName: function (m) { return MONTHS[m - 1] || ''; },
        monthShort: function (m) { return SHORT[m - 1] || ''; }
    };
})();
window.JalaliUtil = JalaliUtil;

// =============================================================
// Friendly error mapping.
// =============================================================
function friendlyError(raw) {
    if (!raw) return 'Something went wrong. Please try again.';
    var e = String(raw).toLowerCase();
    if (e.indexOf('no configured providers') !== -1 ||
        e.indexOf('all providers failed') !== -1) {
        return 'The AI service is temporarily unavailable. Please try again in a few minutes.';
    }
    if (e.indexOf('too many') !== -1 || e.indexOf('rate limit') !== -1 || e.indexOf('429') !== -1) {
        return 'You have reached the hourly AI request limit. Please wait and try again later.';
    }
    if (e.indexOf('insufficient balance') !== -1 || e.indexOf('quota') !== -1) {
        return 'The AI service quota has been exhausted. Please contact the administrator.';
    }
    if (e.indexOf('network') !== -1 || e.indexOf('fetch') !== -1) {
        return 'Connection problem. Please check your network and try again.';
    }
    if (e.indexOf('timeout') !== -1) {
        return 'The AI request took too long. Please try again.';
    }
    if (e.indexOf('unknown report') !== -1) {
        return 'This report is not available right now. Please refresh the page.';
    }
    if (e.indexOf('invalid start') !== -1 ||
        e.indexOf('invalid end') !== -1 ||
        e.indexOf('invalid date') !== -1) {
        return 'Please choose a valid date range.';
    }
    return 'Something went wrong. Please try again in a few minutes.';
}
window.friendlyError = friendlyError;

// =============================================================
// Vue app
// =============================================================
function startAnalyticsApp() {
    console.log('[analytics-vue] starting');

    if (typeof Vue === 'undefined')   { console.error('[analytics-vue] Vue missing');   return; }
    if (typeof Chart === 'undefined') { console.error('[analytics-vue] Chart missing'); return; }

    const { createApp, reactive, computed, nextTick, onMounted, onUnmounted, ref } = Vue;
    const initial = window.__ANALYTICS_DATA__ || {};

    const store = reactive({
        start: initial.start || '',
        end:   initial.end   || '',
        mode:  initial.mode  || 'sql',
        sqlReports: initial.reports || {},
        aiReports:  null,
        loading: false,
        aiSummaries: {},
        aiLoading:   {},
        aiVisible:   {},
        aiReview: null,
        aiReviewLoading: false,
    });

    window.__ANALYTICS_STORE__ = store;
    console.log('[analytics-vue] store ready. Reports:', Object.keys(store.sqlReports));

    // ---- Helpers ----

    function formatNumber(n) {
        return ('' + n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    const currentReports = computed(function () {
        if (store.mode === 'ai' && store.aiReports) return store.aiReports;
        return store.sqlReports;
    });

    const rangeText = computed(function () {
        if (!store.start || !store.end) return '';
        var a = new Date(store.start + 'T00:00:00');
        var b = new Date(store.end + 'T00:00:00');
        var days = Math.round((b - a) / 86400000) + 1;
        var startJ = JalaliUtil.long(store.start);
        var endJ   = JalaliUtil.long(store.end);
        if (days === 1) return startJ;
        var sp = startJ.split(' ');
        var ep = endJ.split(' ');
        var left = (sp[2] === ep[2]) ? (sp[0] + ' ' + sp[1]) : startJ;
        return left + ' to ' + endJ + '  (' + days + ' days)';
    });

    // ---- Data loading ----

    async function fetchReports() {
        if (store.loading) return;
        store.loading = true;
        try {
            const url = 'analytics.php?api=reports'
                + '&mode='  + store.mode
                + '&start=' + encodeURIComponent(store.start)
                + '&end='   + encodeURIComponent(store.end);
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (data.mode === 'ai') store.aiReports = data.reports || null;
            else store.sqlReports = data.reports || {};
        } catch (err) {
            console.error('[analytics-vue] fetch failed:', err);
        } finally {
            store.loading = false;
        }
    }

    async function recompute() {
        if (store.loading) return;
        store.loading = true;
        try {
            const url = 'analytics.php?api=recompute'
                + '&mode='  + store.mode
                + '&start=' + encodeURIComponent(store.start)
                + '&end='   + encodeURIComponent(store.end);
            const res = await fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (data.mode === 'ai') {
                store.aiReports = data.reports || null;
            } else {
                store.sqlReports = data.reports || {};
                store.aiReports  = null;
            }
            store.aiSummaries = {};
            store.aiVisible   = {};
            store.aiReview    = null;
        } catch (err) {
            console.error('[analytics-vue] recompute failed:', err);
            alert('Could not refresh the reports. Please check your connection and try again.');
        } finally {
            store.loading = false;
        }
    }

    function applyRange(days) {
        const end = new Date();
        const start = new Date();
        start.setDate(end.getDate() - (days - 1));
        store.end   = end.toISOString().slice(0, 10);
        store.start = start.toISOString().slice(0, 10);
        store.aiSummaries = {};
        store.aiVisible   = {};
        store.aiReview    = null;
        store.aiReports   = null;
        fetchReports();
    }

    function applyCustomRange() {
        if (!store.start || !store.end) return;
        if (store.start > store.end) { alert('Start date must be before end date.'); return; }
        store.aiSummaries = {};
        store.aiVisible   = {};
        store.aiReview    = null;
        store.aiReports   = null;
        fetchReports();
    }

    function setMode(mode) {
        if (mode !== 'sql' && mode !== 'ai') return;
        if (store.mode === mode) return;
        store.mode = mode;
        if (mode === 'ai' && !store.aiReports) fetchReports();
    }

    // ---- AI narration ----

    async function fetchExplain(key, force) {
        try {
            const url = 'analytics.php?api=explain'
                + '&key='   + encodeURIComponent(key)
                + '&start=' + encodeURIComponent(store.start)
                + '&end='   + encodeURIComponent(store.end)
                + (force ? '&force=1' : '');
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            if (data.ok) {
                store.aiSummaries[key] = { text: data.text || '', provider: data.provider || null, cached: !!data.cached };
            } else {
                store.aiSummaries[key] = { error: friendlyError(data.error) };
            }
        } catch (err) {
            console.error('[analytics-vue] explain failed:', err);
            store.aiSummaries[key] = { error: friendlyError('network') };
        } finally {
            store.aiLoading[key] = false;
        }
    }

    function toggleExplain(key, force) {
        if (store.aiSummaries[key] && !force) { store.aiVisible[key] = !store.aiVisible[key]; return; }
        if (store.aiLoading[key]) return;
        if (force) store.aiSummaries[key] = null;
        store.aiLoading[key] = true;
        store.aiVisible[key] = true;
        fetchExplain(key, force);
    }

    // ---- Executive review ----

    async function fetchAiReview(force) {
        if (store.aiReviewLoading) return;
        store.aiReviewLoading = true;
        try {
            const url = 'analytics.php?api=summary_review'
                + '&start=' + encodeURIComponent(store.start)
                + '&end='   + encodeURIComponent(store.end)
                + (force ? '&force=1' : '');
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            if (data.ok) {
                store.aiReview = { text: data.text || '', provider: data.provider || null, cached: !!data.cached };
            } else {
                store.aiReview = { error: friendlyError(data.error) };
            }
        } catch (err) {
            console.error('[analytics-vue] review failed:', err);
            store.aiReview = { error: friendlyError('network') };
        } finally {
            store.aiReviewLoading = false;
        }
    }

    function regenerateReview() { store.aiReview = null; fetchAiReview(true); }

    // ---- Charts ----

    const charts = { revenue: null, topItems: null };

    function renderRevenueChart() {
        const canvas = document.getElementById('chart-revenue');
        if (!canvas) return;
        const trend = currentReports.value.revenue_trend && currentReports.value.revenue_trend.data;
        if (!trend || !Array.isArray(trend.days)) return;

        const labels  = trend.days.map(function (d) { return JalaliUtil.short(d.date); });
        const revenue = trend.days.map(function (d) { return d.revenue; });
        const orders  = trend.days.map(function (d) { return d.orders; });

        if (charts.revenue) charts.revenue.destroy();
        charts.revenue = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Revenue (T)', data: revenue, borderColor: '#6f4e37',
                      backgroundColor: 'rgba(111,78,55,0.10)', borderWidth: 2, tension: 0.35,
                      fill: true, pointRadius: 2, pointHoverRadius: 5, yAxisID: 'y' },
                    { label: 'Orders', data: orders, borderColor: '#22c55e',
                      backgroundColor: 'rgba(34,197,94,0)', borderWidth: 2, tension: 0.35,
                      pointRadius: 2, pointHoverRadius: 5, yAxisID: 'y1' },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { family: 'Inter' } } },
                    tooltip: { callbacks: { label: function (ctx) {
                        return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString();
                    } } },
                },
                scales: {
                    y:  { position: 'left',  beginAtZero: true,
                          ticks: { callback: function (v) { return v.toLocaleString(); } } },
                    y1: { position: 'right', beginAtZero: true,
                          grid: { drawOnChartArea: false }, ticks: { precision: 0 } },
                    x:  { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                },
            },
        });
    }

    function renderTopItemsChart() {
        const canvas = document.getElementById('chart-top-items');
        if (!canvas) return;
        const top = currentReports.value.top_items && currentReports.value.top_items.data;
        if (!top || !Array.isArray(top.items)) return;

        const labels = top.items.map(function (i) { return i.name; });
        const qty    = top.items.map(function (i) { return i.qty; });

        if (charts.topItems) charts.topItems.destroy();
        charts.topItems = new Chart(canvas, {
            type: 'bar',
            data: { labels: labels, datasets: [{
                label: 'Quantity sold', data: qty,
                backgroundColor: 'rgba(111,78,55,0.75)', borderColor: '#6f4e37',
                borderWidth: 1, borderRadius: 4,
            }] },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) {
                        return ctx.parsed.x.toLocaleString() + ' sold';
                    } } },
                },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 } },
                    y: { ticks: { font: { family: 'Inter' } } },
                },
            },
        });
    }

    function renderAllCharts() {
        nextTick(function () { renderRevenueChart(); renderTopItemsChart(); });
    }

    window.addEventListener('resize', function () {
        if (charts.revenue)  { try { charts.revenue.resize();  } catch (e) {} }
        if (charts.topItems) { try { charts.topItems.resize(); } catch (e) {} }
    });

    // ---- Jalali calendar component ----

    const JalaliCalendar = {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: `
            <div class="jalali-picker" ref="wrap" @click.stop>
                <button type="button" class="jalali-picker-btn" @click="toggle">
                    <i class="fas fa-calendar"></i>
                    <span>{{ displayValue }}</span>
                </button>
                <div v-if="open" class="calendar-popup">
                    <div class="calendar-header">
                        <button type="button" @click="prevMonth" aria-label="Previous month">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="calendar-title">{{ monthName }} {{ viewYear }}</span>
                        <button type="button" @click="nextMonth" aria-label="Next month">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <div class="calendar-grid">
                        <div v-for="name in dayNames" :key="'h-' + name" class="calendar-day-name">{{ name }}</div>
                        <div v-for="i in firstWeekday" :key="'b-' + i" class="day-cell blank"></div>
                        <div v-for="day in days" :key="day.iso"
                             class="day-cell"
                             :class="{ today: day.isToday, selected: day.isSelected }"
                             @click="pick(day)">
                            {{ day.day }}
                        </div>
                    </div>
                    <div class="calendar-footer">
                        <button type="button" class="calendar-today-btn" @click="jumpToday">
                            <i class="fas fa-calendar-day"></i> Jump to today
                        </button>
                    </div>
                </div>
            </div>
        `,
        setup(props, { emit }) {
            const wrap = ref(null);
            const todayIso = new Date().toISOString().slice(0, 10);
            const todayJ   = JalaliUtil.toParts(todayIso);
            const initJ    = props.modelValue ? JalaliUtil.toParts(props.modelValue) : todayJ;

            const state = reactive({
                year:  initJ ? initJ.year : todayJ.year,
                month: initJ ? initJ.month : todayJ.month,
                open: false,
            });

            const dayNames = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

            const displayValue = computed(function () {
                if (!props.modelValue) return 'Select date';
                return JalaliUtil.numeric(props.modelValue);
            });

            const monthName    = computed(function () { return JalaliUtil.monthName(state.month); });
            const viewYear     = computed(function () { return state.year; });
            const open         = computed(function () { return state.open; });
            const firstWeekday = computed(function () {
                return JalaliUtil.firstDayOfWeek(state.year, state.month);
            });

            const days = computed(function () {
                var out = [];
                var dim = JalaliUtil.daysInMonth(state.year, state.month);
                for (var d = 1; d <= dim; d++) {
                    var iso = JalaliUtil.toIso(state.year, state.month, d);
                    out.push({
                        day: d,
                        iso: iso,
                        isToday: iso === todayIso,
                        isSelected: iso === props.modelValue,
                    });
                }
                return out;
            });

            function toggle() { state.open = !state.open; }
            function prevMonth() {
                if (state.month === 1) { state.month = 12; state.year--; }
                else state.month--;
            }
            function nextMonth() {
                if (state.month === 12) { state.month = 1; state.year++; }
                else state.month++;
            }
            function pick(day) {
                emit('update:modelValue', day.iso);
                state.open = false;
            }
            function jumpToday() {
                var p = JalaliUtil.toParts(todayIso);
                state.year = p.year;
                state.month = p.month;
                emit('update:modelValue', todayIso);
                state.open = false;
            }
            function onDocClick(e) {
                if (!state.open) return;
                if (wrap.value && !wrap.value.contains(e.target)) {
                    state.open = false;
                }
            }

            onMounted(function () { document.addEventListener('click', onDocClick); });
            onUnmounted(function () { document.removeEventListener('click', onDocClick); });

            return {
                wrap: wrap, state: state,
                displayValue: displayValue, monthName: monthName, viewYear: viewYear,
                open: open, firstWeekday: firstWeekday, days: days, dayNames: dayNames,
                toggle: toggle, prevMonth: prevMonth, nextMonth: nextMonth,
                pick: pick, jumpToday: jumpToday,
            };
        }
    };

    // ---- Shared sub-components ----

    const AiExplainButton = {
        props: ['reportKey'],
        template: `
            <button type="button" class="btn-explain" :disabled="loading" @click="click">
                <i :class="iconClass"></i>
                <span>{{ label }}</span>
            </button>
        `,
        setup(props) {
            const loading = computed(function () { return !!store.aiLoading[props.reportKey]; });
            const hasSummary = computed(function () {
                const s = store.aiSummaries[props.reportKey];
                return !!(s && s.text);
            });
            const isVisible = computed(function () { return !!store.aiVisible[props.reportKey]; });
            const iconClass = computed(function () {
                if (loading.value) return 'fas fa-circle-notch fa-spin';
                if (hasSummary.value && isVisible.value) return 'fas fa-eye-slash';
                return 'fas fa-wand-magic-sparkles';
            });
            const label = computed(function () {
                if (loading.value) return 'Thinking...';
                if (hasSummary.value && isVisible.value) return 'Hide';
                return 'Explain';
            });
            return { loading: loading, iconClass: iconClass, label: label,
                     click: function () { toggleExplain(props.reportKey, false); } };
        }
    };

    const AiSummaryCard = {
        props: ['reportKey'],
        template: `
            <div v-if="visible" class="ai-summary" :class="stateClass">
                <div v-if="loading" class="ai-summary-loading">
                    <i class="fas fa-circle-notch fa-spin"></i>
                    <span>Analyzing the numbers...</span>
                </div>
                <template v-else-if="errorText">
                    <div class="ai-summary-error-line">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>{{ errorText }}</span>
                        <button type="button" class="ai-summary-retry" @click="retry">Retry</button>
                    </div>
                </template>
                <template v-else-if="summaryText">
                    <div class="ai-summary-header">
                        <span class="ai-summary-badge"><i class="fas fa-wand-magic-sparkles"></i> AI</span>
                        <span v-if="providerText" class="ai-summary-provider">{{ providerText }}</span>
                        <button type="button" class="ai-summary-regen" @click="regenerate"
                                :disabled="loading" title="Regenerate">
                            <i class="fas fa-sync"></i>
                        </button>
                    </div>
                    <p class="ai-summary-text">{{ summaryText }}</p>
                </template>
            </div>
        `,
        setup(props) {
            const loading = computed(function () { return !!store.aiLoading[props.reportKey]; });
            const visible = computed(function () { return loading.value || !!store.aiVisible[props.reportKey]; });
            const summary = computed(function () { return store.aiSummaries[props.reportKey] || null; });
            const summaryText = computed(function () {
                if (loading.value) return '';
                return (summary.value && summary.value.text) ? summary.value.text : '';
            });
            const errorText = computed(function () {
                if (loading.value) return '';
                return (summary.value && summary.value.error) ? summary.value.error : '';
            });
            const providerText = computed(function () {
                if (!summary.value) return '';
                if (summary.value.cached) return 'from cache';
                if (summary.value.provider) return 'via ' + summary.value.provider;
                return '';
            });
            const stateClass = computed(function () {
                if (loading.value) return 'state-loading';
                if (errorText.value) return 'state-error';
                return 'state-ready';
            });
            return {
                loading: loading, visible: visible, summaryText: summaryText,
                errorText: errorText, providerText: providerText, stateClass: stateClass,
                retry:      function () { toggleExplain(props.reportKey, true); },
                regenerate: function () { toggleExplain(props.reportKey, true); },
            };
        }
    };

    // ---- Toolbar ----

    const Toolbar = {
        components: { JalaliCalendar: JalaliCalendar },
        template: `
            <div class="analytics-toolbar">
                <div class="toolbar-left">
                    <span class="toolbar-label"><i class="fas fa-calendar"></i> Range:</span>
                    <JalaliCalendar v-model="store.start"></JalaliCalendar>
                    <span class="range-sep">to</span>
                    <JalaliCalendar v-model="store.end"></JalaliCalendar>
                    <button type="button" class="btn-apply" @click="applyCustom">Apply</button>
                </div>
                <div class="toolbar-quick">
                    <button type="button" class="quick-btn" @click="setRange(7)">7 days</button>
                    <button type="button" class="quick-btn" @click="setRange(30)">30 days</button>
                    <button type="button" class="quick-btn" @click="setRange(90)">90 days</button>
                </div>
                <div class="toolbar-right">
                    <button type="button" class="btn-refresh" :disabled="store.loading" @click="refresh">
                        <i :class="store.loading ? 'fas fa-circle-notch fa-spin' : 'fas fa-sync'"></i>
                        {{ store.loading ? 'Working...' : 'Refresh' }}
                    </button>
                </div>
            </div>
        `,
        setup() {
            return {
                store: store,
                setRange:    function (n) { applyRange(n); },
                applyCustom: applyCustomRange,
                refresh:     recompute,
            };
        }
    };

    // ---- Summary card ----

    const SummaryCard = {
        template: `
            <div class="report-card summary-card">
                <div class="report-title-row summary-title-row">
                    <div class="summary-title-left">
                        <h3 class="report-title"><i class="fas fa-chart-pie"></i> Summary</h3>
                    </div>
                    <div class="summary-range" v-if="rangeText">{{ rangeText }}</div>
                    <div class="summary-toggle" role="tablist">
                        <button type="button" class="summary-toggle-btn"
                                :class="{ active: store.mode === 'sql' }"
                                @click="setMode('sql')">
                            <i class="fas fa-calculator"></i>
                            <span>SQL</span>
                        </button>
                        <button type="button" class="summary-toggle-btn summary-toggle-ai"
                                :class="{ active: store.mode === 'ai' }"
                                @click="setMode('ai')">
                            <i class="fas fa-wand-magic-sparkles"></i>
                            <span>AI</span>
                        </button>
                    </div>
                </div>

                <div v-if="store.mode === 'ai'" class="ai-review">
                    <div v-if="store.aiReviewLoading" class="ai-review-loading">
                        <i class="fas fa-circle-notch fa-spin"></i>
                        <span>Writing an executive review...</span>
                    </div>
                    <template v-else-if="store.aiReview && store.aiReview.error">
                        <div class="ai-review-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>{{ store.aiReview.error }}</span>
                            <button type="button" class="ai-summary-retry" @click="regen">Retry</button>
                        </div>
                    </template>
                    <template v-else-if="store.aiReview && store.aiReview.text">
                        <div class="ai-review-header">
                            <span class="ai-review-badge"><i class="fas fa-wand-magic-sparkles"></i> AI Review</span>
                            <span v-if="reviewProvider" class="ai-summary-provider">{{ reviewProvider }}</span>
                            <button type="button" class="ai-summary-regen" @click="regen"
                                    :disabled="store.aiReviewLoading" title="Regenerate">
                                <i class="fas fa-sync"></i>
                            </button>
                        </div>
                        <p class="ai-review-text">{{ store.aiReview.text }}</p>
                    </template>
                    <template v-else>
                        <div class="ai-review-empty">
                            <i class="fas fa-wand-magic-sparkles"></i>
                            <span>Click <strong>Refresh</strong> to compute AI-curated data and a fresh review.</span>
                            <button type="button" class="ai-summary-retry" @click="regen">Review now</button>
                        </div>
                    </template>
                </div>

                <div class="summary-grid">
                    <div class="stat">
                        <div class="stat-label">Total Revenue</div>
                        <div class="stat-value">{{ formatNumber(revenue) }} <span class="stat-unit">T</span></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Total Orders</div>
                        <div class="stat-value">{{ formatNumber(orders) }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Avg Order Value</div>
                        <div class="stat-value">{{ formatNumber(aov) }} <span class="stat-unit">T</span></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Days with Orders</div>
                        <div class="stat-value">{{ daysWithOrders }}</div>
                    </div>
                </div>
            </div>
        `,
        setup() {
            const summary = computed(function () {
                const rt = currentReports.value.revenue_trend;
                return (rt && rt.data && rt.data.summary) ? rt.data.summary : {};
            });
            const reviewProvider = computed(function () {
                if (!store.aiReview) return '';
                if (store.aiReview.cached) return 'from cache';
                if (store.aiReview.provider) return 'via ' + store.aiReview.provider;
                return '';
            });
            return {
                store: store, formatNumber: formatNumber, rangeText: rangeText,
                revenue:        computed(function () { return summary.value.total_revenue || 0; }),
                orders:         computed(function () { return summary.value.total_orders || 0; }),
                aov:            computed(function () { return summary.value.avg_order_value || 0; }),
                daysWithOrders: computed(function () { return summary.value.days_with_orders || 0; }),
                reviewProvider: reviewProvider,
                setMode: setMode,
                regen: regenerateReview,
            };
        }
    };

    // ---- Core reports ----

    const RevenueChart = {
        components: { AiExplainButton: AiExplainButton, AiSummaryCard: AiSummaryCard },
        template: `
            <div class="report-card">
                <div class="report-title-row">
                    <h3 class="report-title"><i class="fas fa-chart-line"></i> {{ title }}</h3>
                    <AiExplainButton reportKey="revenue_trend"></AiExplainButton>
                </div>
                <div v-if="hasData" class="chart-wrapper"><canvas id="chart-revenue"></canvas></div>
                <div v-else class="empty-state"><i class="fas fa-inbox"></i> No data in this range.</div>
                <AiSummaryCard reportKey="revenue_trend"></AiSummaryCard>
            </div>
        `,
        setup() {
            const title = computed(function () {
                const rt = currentReports.value.revenue_trend;
                return (rt && rt.title) ? rt.title : 'Revenue Trend';
            });
            const hasData = computed(function () {
                const rt = currentReports.value.revenue_trend;
                const d = rt && rt.data;
                return d && Array.isArray(d.days) && d.days.length > 0;
            });
            return { store: store, title: title, hasData: hasData };
        }
    };

    const TopItemsChart = {
        components: { AiExplainButton: AiExplainButton, AiSummaryCard: AiSummaryCard },
        template: `
            <div class="report-card">
                <div class="report-title-row">
                    <h3 class="report-title"><i class="fas fa-fire"></i> {{ title }}</h3>
                    <AiExplainButton reportKey="top_items"></AiExplainButton>
                </div>
                <div v-if="hasData" class="chart-wrapper chart-wrapper-tall">
                    <canvas id="chart-top-items"></canvas>
                </div>
                <div v-else class="empty-state"><i class="fas fa-inbox"></i> No data in this range.</div>
                <AiSummaryCard reportKey="top_items"></AiSummaryCard>
            </div>
        `,
        setup() {
            const title = computed(function () {
                const ti = currentReports.value.top_items;
                return (ti && ti.title) ? ti.title : 'Top Items';
            });
            const hasData = computed(function () {
                const ti = currentReports.value.top_items;
                const d = ti && ti.data;
                return d && Array.isArray(d.items) && d.items.length > 0;
            });
            return { store: store, title: title, hasData: hasData };
        }
    };

    const LeastItemsTable = {
        components: { AiExplainButton: AiExplainButton, AiSummaryCard: AiSummaryCard },
        template: `
            <div class="report-card">
                <div class="report-title-row">
                    <h3 class="report-title"><i class="fas fa-snowflake"></i> {{ title }}</h3>
                    <AiExplainButton reportKey="least_items"></AiExplainButton>
                </div>
                <div v-if="hasData" class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr><th>Item</th><th class="num">Sold</th><th class="num">Revenue (T)</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id">
                                <td>{{ item.name }}</td>
                                <td class="num">{{ item.qty }}</td>
                                <td class="num">{{ formatNumber(item.revenue) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="empty-state"><i class="fas fa-inbox"></i> No data in this range.</div>
                <AiSummaryCard reportKey="least_items"></AiSummaryCard>
            </div>
        `,
        setup() {
            const items = computed(function () {
                const li = currentReports.value.least_items;
                const d = li && li.data;
                return (d && Array.isArray(d.items)) ? d.items : [];
            });
            const title = computed(function () {
                const li = currentReports.value.least_items;
                return (li && li.title) ? li.title : 'Least-Selling Items';
            });
            return {
                store: store, title: title, items: items,
                hasData: computed(function () { return items.value.length > 0; }),
                formatNumber: formatNumber,
            };
        }
    };

    const HeatmapGrid = {
        components: { AiExplainButton: AiExplainButton, AiSummaryCard: AiSummaryCard },
        template: `
            <div class="report-card report-card-wide">
                <div class="report-title-row">
                    <h3 class="report-title"><i class="fas fa-th"></i> {{ title }}</h3>
                    <AiExplainButton reportKey="hourly_heatmap"></AiExplainButton>
                </div>
                <div v-if="hasData" class="heatmap-wrap">
                    <div class="heatmap-grid">
                        <div class="heatmap-corner"></div>
                        <div class="heatmap-hour-label" v-for="h in hours" :key="'h-' + h">{{ h }}</div>
                        <template v-for="(row, dayIdx) in grid" :key="'d-' + dayIdx">
                            <div class="heatmap-day-label">{{ dayNames[dayIdx] }}</div>
                            <div v-for="(val, hrIdx) in row"
                                 :key="'c-' + dayIdx + '-' + hrIdx"
                                 class="heatmap-cell"
                                 :style="cellStyle(val)"
                                 :title="cellTitle(dayIdx, hrIdx, val)">
                            </div>
                        </template>
                    </div>
                    <div class="heatmap-legend">
                        <span>Low</span><div class="legend-bar"></div><span>High</span>
                    </div>
                </div>
                <div v-else class="empty-state"><i class="fas fa-inbox"></i> No data in this range.</div>
                <AiSummaryCard reportKey="hourly_heatmap"></AiSummaryCard>
            </div>
        `,
        setup() {
            const title = computed(function () {
                const hm = currentReports.value.hourly_heatmap;
                return (hm && hm.title) ? hm.title : 'Orders by Hour';
            });
            const grid = computed(function () {
                const hm = currentReports.value.hourly_heatmap;
                const d = hm && hm.data;
                return (d && Array.isArray(d.grid)) ? d.grid : [];
            });
            const max = computed(function () {
                const hm = currentReports.value.hourly_heatmap;
                const d = hm && hm.data;
                return (d && d.max) ? d.max : 0;
            });
            const hours = computed(function () {
                const hm = currentReports.value.hourly_heatmap;
                const d = hm && hm.data;
                return (d && Array.isArray(d.hour_names)) ? d.hour_names : [];
            });
            const dayNames = computed(function () {
                const hm = currentReports.value.hourly_heatmap;
                const d = hm && hm.data;
                return (d && Array.isArray(d.day_names)) ? d.day_names : [];
            });
            const hasData = computed(function () {
                return grid.value.length > 0 && max.value > 0;
            });
            function cellStyle(val) {
                if (max.value === 0) return { background: '#f3e8e0' };
                var i = val / max.value;
                return { background: 'rgba(111,78,55,' + (0.08 + i * 0.75).toFixed(3) + ')' };
            }
            function cellTitle(dayIdx, hrIdx, val) {
                var day = dayNames.value[dayIdx] || '';
                var hour = hours.value[hrIdx] || '';
                return day + ' ' + hour + ':00 - ' + val + ' orders';
            }
            return {
                store: store, title: title, grid: grid, hours: hours, dayNames: dayNames,
                hasData: hasData, cellStyle: cellStyle, cellTitle: cellTitle,
            };
        }
    };

    // ---- Phase 3.2 reports ----

    const ItemCombosTable = {
        components: { AiExplainButton: AiExplainButton, AiSummaryCard: AiSummaryCard },
        template: `
            <div class="report-card">
                <div class="report-title-row">
                    <h3 class="report-title"><i class="fas fa-people-arrows"></i> {{ title }}</h3>
                    <AiExplainButton reportKey="item_combos"></AiExplainButton>
                </div>
                <div v-if="hasData" class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr><th>Pair</th><th class="num">Appears in</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(c, i) in combos" :key="i">
                                <td>
                                    <span class="combo-pair">
                                        <span>{{ c.a_name }}</span>
                                        <span class="combo-plus">+</span>
                                        <span>{{ c.b_name }}</span>
                                    </span>
                                </td>
                                <td class="num">{{ c.count }} orders</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="empty-state"><i class="fas fa-inbox"></i> No pairs met the minimum frequency.</div>
                <AiSummaryCard reportKey="item_combos"></AiSummaryCard>
            </div>
        `,
        setup() {
            const combos = computed(function () {
                const c = currentReports.value.item_combos;
                const d = c && c.data;
                return (d && Array.isArray(d.combos)) ? d.combos : [];
            });
            const title = computed(function () {
                const c = currentReports.value.item_combos;
                return (c && c.title) ? c.title : 'Frequently Bought Together';
            });
            return {
                store: store, title: title, combos: combos,
                hasData: computed(function () { return combos.value.length > 0; }),
            };
        }
    };

    const FadingItemsTable = {
        components: { AiExplainButton: AiExplainButton, AiSummaryCard: AiSummaryCard },
        template: `
            <div class="report-card">
                <div class="report-title-row">
                    <h3 class="report-title"><i class="fas fa-arrow-trend-down"></i> {{ title }}</h3>
                    <AiExplainButton reportKey="fading_items"></AiExplainButton>
                </div>
                <div v-if="hasData" class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="num">Before</th>
                                <th class="num">After</th>
                                <th class="num">Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id">
                                <td>{{ item.name }}</td>
                                <td class="num">{{ item.before }}</td>
                                <td class="num">{{ item.after }}</td>
                                <td class="num">
                                    <span class="trend-pill down">
                                        <i class="fas fa-arrow-down"></i>{{ item.change_pct }}%
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="empty-state empty-state-explained">
                    <i class="fas fa-circle-info"></i>
                    <span v-html="explanation"></span>
                </div>
                <AiSummaryCard reportKey="fading_items"></AiSummaryCard>
            </div>
        `,
        setup() {
            const items = computed(function () {
                const f = currentReports.value.fading_items;
                const d = f && f.data;
                return (d && Array.isArray(d.items)) ? d.items : [];
            });
            const explanation = computed(function () {
                const f = currentReports.value.fading_items;
                const d = f && f.data;
                const reason = d && d.reason;
                const split = (d && d.period_split) ? JalaliUtil.long(d.period_split) : 'the midpoint';
                if (reason === 'no_before_data') {
                    return 'No items are fading.<br><small>Fading requires sales in both halves of the range. Your first half (before ' + split + ') has no orders.</small>';
                }
                if (reason === 'no_after_data') {
                    return 'No items are fading.<br><small>The second half (after ' + split + ') has no orders.</small>';
                }
                if (reason === 'no_orders_in_range') {
                    return 'No items are fading.<br><small>No orders exist in this range.</small>';
                }
                return 'No items crossed the fading threshold.<br><small>Comparing before vs after ' + split + '.</small>';
            });
            const title = computed(function () {
                const f = currentReports.value.fading_items;
                return (f && f.title) ? f.title : 'Fading Items';
            });
            return {
                store: store, title: title, items: items,
                hasData: computed(function () { return items.value.length > 0; }),
                explanation: explanation,
            };
        }
    };

    const RisingItemsTable = {
        components: { AiExplainButton: AiExplainButton, AiSummaryCard: AiSummaryCard },
        template: `
            <div class="report-card">
                <div class="report-title-row">
                    <h3 class="report-title"><i class="fas fa-arrow-trend-up"></i> {{ title }}</h3>
                    <AiExplainButton reportKey="rising_items"></AiExplainButton>
                </div>
                <div v-if="hasData" class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="num">Before</th>
                                <th class="num">After</th>
                                <th class="num">Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in items" :key="item.id">
                                <td>{{ item.name }}</td>
                                <td class="num">{{ item.before }}</td>
                                <td class="num">{{ item.after }}</td>
                                <td class="num">
                                    <span class="trend-pill up">
                                        <i class="fas fa-arrow-up"></i>+{{ item.change_pct }}%
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="empty-state empty-state-explained">
                    <i class="fas fa-circle-info"></i>
                    <span v-html="explanation"></span>
                </div>
                <AiSummaryCard reportKey="rising_items"></AiSummaryCard>
            </div>
        `,
        setup() {
            const items = computed(function () {
                const r = currentReports.value.rising_items;
                const d = r && r.data;
                return (d && Array.isArray(d.items)) ? d.items : [];
            });
            const explanation = computed(function () {
                const r = currentReports.value.rising_items;
                const d = r && r.data;
                const reason = d && d.reason;
                const split = (d && d.period_split) ? JalaliUtil.long(d.period_split) : 'the midpoint';
                if (reason === 'no_before_data') {
                    return 'No items are rising notably.<br><small>Rising requires sales in both halves of the range. Your first half (before ' + split + ') has no orders.</small>';
                }
                if (reason === 'no_after_data') {
                    return 'No items are rising notably.<br><small>The second half (after ' + split + ') has no orders.</small>';
                }
                if (reason === 'no_orders_in_range') {
                    return 'No items are rising notably.<br><small>No orders exist in this range.</small>';
                }
                return 'No items crossed the rising threshold.<br><small>Comparing before vs after ' + split + '.</small>';
            });
            const title = computed(function () {
                const r = currentReports.value.rising_items;
                return (r && r.title) ? r.title : 'Rising Items';
            });
            return {
                store: store, title: title, items: items,
                hasData: computed(function () { return items.value.length > 0; }),
                explanation: explanation,
            };
        }
    };

    const PriceTierShiftGrid = {
        components: { AiExplainButton: AiExplainButton, AiSummaryCard: AiSummaryCard },
        template: `
            <div class="report-card report-card-wide">
                <div class="report-title-row">
                    <h3 class="report-title"><i class="fas fa-layer-group"></i> {{ title }}</h3>
                    <AiExplainButton reportKey="price_tier_shift"></AiExplainButton>
                </div>

                <p v-if="splitLabel && hasData" class="tier-subtitle">
                    Comparing <strong>before</strong> vs <strong>after {{ splitLabel }}</strong>
                    - how customers' price preferences shifted across the range.
                </p>

                <div v-if="hasData" class="tier-grid">
                    <div v-for="tier in tiers" :key="tier.key" class="tier-card">
                        <div class="tier-header">
                            <i class="fas fa-tag"></i>
                            <span>{{ tier.name }}</span>
                        </div>
                        <div class="tier-range">{{ tier.range }}</div>

                        <div class="tier-bar">
                            <div class="tier-bar-label">Share of units (after)</div>
                            <div class="tier-bar-track">
                                <div class="tier-bar-fill" :style="{ width: tier.share_after + '%' }"></div>
                            </div>
                            <div class="tier-bar-value">
                                {{ tier.share_after }}%
                                <span v-if="tier.share_delta !== 0"
                                      class="tier-bar-delta"
                                      :class="tier.share_delta > 0 ? 'up' : 'down'">
                                    ({{ tier.share_delta > 0 ? '+' : '' }}{{ tier.share_delta }}%)
                                </span>
                            </div>
                        </div>

                        <div class="tier-stat">
                            <span class="tier-label">Units sold</span>
                            <span class="tier-value">{{ tier.qty_before }} <small>-></small> {{ tier.qty_after }}</span>
                        </div>
                        <div class="tier-stat">
                            <span class="tier-label">Revenue</span>
                            <span class="tier-value">{{ formatNumber(tier.rev_before) }} <small>-></small> {{ formatNumber(tier.rev_after) }} <small>T</small></span>
                        </div>
                    </div>
                </div>

                <div v-else class="empty-state empty-state-explained">
                    <i class="fas fa-circle-info"></i>
                    <span v-html="explanation"></span>
                </div>

                <AiSummaryCard reportKey="price_tier_shift"></AiSummaryCard>
            </div>
        `,
        setup() {
            const tiers = computed(function () {
                const p = currentReports.value.price_tier_shift;
                const d = p && p.data;
                return (d && Array.isArray(d.tiers)) ? d.tiers : [];
            });
            const splitLabel = computed(function () {
                const p = currentReports.value.price_tier_shift;
                const d = p && p.data;
                return (d && d.period_split) ? JalaliUtil.long(d.period_split) : '';
            });
            const explanation = computed(function () {
                const p = currentReports.value.price_tier_shift;
                const d = p && p.data;
                const reason = d && d.reason;
                if (reason === 'no_before_data') {
                    return 'Not enough data yet.<br><small>The first half of the selected range has no orders, so there is nothing to compare against.</small>';
                }
                if (reason === 'no_after_data') {
                    return 'Not enough data yet.<br><small>The second half of the selected range has no orders.</small>';
                }
                return 'No price tiers to display.<br><small>Add a few items to the menu and place some orders.</small>';
            });
            const title = computed(function () {
                const p = currentReports.value.price_tier_shift;
                return (p && p.title) ? p.title : 'Price Tier Shift';
            });
            return {
                store: store, formatNumber: formatNumber, title: title,
                tiers: tiers, splitLabel: splitLabel, explanation: explanation,
                hasData: computed(function () { return tiers.value.length > 0; }),
            };
        }
    };

    // ---- Root ----

    const AnalyticsApp = {
        components: {
            Toolbar: Toolbar, SummaryCard: SummaryCard,
            RevenueChart: RevenueChart, TopItemsChart: TopItemsChart, HeatmapGrid: HeatmapGrid,
            LeastItemsTable: LeastItemsTable, RisingItemsTable: RisingItemsTable,
            ItemCombosTable: ItemCombosTable, FadingItemsTable: FadingItemsTable,
            PriceTierShiftGrid: PriceTierShiftGrid,
        },
        template: `
            <div>
                <Toolbar></Toolbar>
                <SummaryCard></SummaryCard>

                <div class="report-row-2">
                    <RevenueChart></RevenueChart>
                    <TopItemsChart></TopItemsChart>
                </div>

                <div class="report-row-2">
                    <HeatmapGrid></HeatmapGrid>
                </div>

                <div class="report-row-2">
                    <LeastItemsTable></LeastItemsTable>
                    <RisingItemsTable></RisingItemsTable>
                </div>

                <div class="report-row-2">
                    <ItemCombosTable></ItemCombosTable>
                    <FadingItemsTable></FadingItemsTable>
                </div>

                <div class="report-row-2">
                    <PriceTierShiftGrid></PriceTierShiftGrid>
                </div>
            </div>
        `,
        setup() { return { store: store }; }
    };

    const mountEl = document.getElementById('vue-analytics-root');
    if (!mountEl) { console.error('[analytics-vue] #vue-analytics-root not found'); return; }

    try {
        createApp(AnalyticsApp).mount('#vue-analytics-root');
        console.log('[analytics-vue] mounted successfully');
    } catch (err) {
        console.error('[analytics-vue] mount failed:', err);
        return;
    }

    renderAllCharts();

    let lastVersion = JSON.stringify(currentReports.value);
    setInterval(function () {
        var v = JSON.stringify(currentReports.value);
        if (v !== lastVersion) {
            lastVersion = v;
            renderAllCharts();
        }
    }, 300);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startAnalyticsApp);
} else {
    startAnalyticsApp();
}
