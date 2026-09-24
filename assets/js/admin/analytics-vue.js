// assets/js/admin/analytics-vue.js
// Vue 3 analytics dashboard for the admin panel.
// Phase 4 + Phase 3.2 + layout reorder.
// ASCII-only source. No em-dashes, no ellipsis chars, no smart quotes.

function startAnalyticsApp() {
    console.log('[analytics-vue] starting');

    if (typeof Vue === 'undefined') {
        console.error('[analytics-vue] Vue 3 not loaded');
        return;
    }
    if (typeof Chart === 'undefined') {
        console.error('[analytics-vue] Chart.js not loaded');
        return;
    }

    const { createApp, reactive, computed, nextTick } = Vue;
    const initial = window.__ANALYTICS_DATA__ || {};

    const store = reactive({
        start: initial.start || '',
        end:   initial.end   || '',
        reports: initial.reports || {},
        loading: false,
        lastRefreshed: null,
        aiSummaries: {},
        aiLoading:   {},
        aiVisible:   {},
    });

    window.__ANALYTICS_STORE__ = store;
    console.log('[analytics-vue] store ready. Reports:', Object.keys(store.reports));

    // ---- Helpers ----

    function formatNumber(n) {
        return ('' + n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function formatDateHuman(iso) {
        const d = new Date(iso + 'T00:00:00');
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    }

    // ---- Report loading ----

    async function fetchReports(force) {
        if (store.loading) return;
        store.loading = true;
        try {
            const url = 'analytics.php?api=reports'
                + '&start=' + encodeURIComponent(store.start)
                + '&end=' + encodeURIComponent(store.end)
                + (force ? '&refresh=1' : '');
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (data.reports) {
                store.reports = data.reports;
                store.lastRefreshed = new Date();
            }
        } catch (err) {
            console.error('[analytics-vue] fetch failed:', err);
            alert('Could not load analytics. Try again.');
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
        store.aiVisible = {};
        fetchReports(false);
    }

    function applyCustomRange() {
        if (!store.start || !store.end) return;
        if (store.start > store.end) {
            alert('Start date must be before end date.');
            return;
        }
        store.aiSummaries = {};
        store.aiVisible = {};
        fetchReports(false);
    }

    function forceRefresh() {
        store.aiSummaries = {};
        store.aiVisible = {};
        fetchReports(true);
    }

    // ---- AI narration ----

    async function fetchExplain(key, force) {
        try {
            const url = 'analytics.php?api=explain'
                + '&key='   + encodeURIComponent(key)
                + '&start=' + encodeURIComponent(store.start)
                + '&end='   + encodeURIComponent(store.end)
                + (force ? '&force=1' : '');

            const res = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();

            if (data.ok) {
                store.aiSummaries[key] = {
                    text:     data.text || '',
                    provider: data.provider || null,
                    cached:   !!data.cached,
                };
            } else {
                store.aiSummaries[key] = { error: data.error || 'AI unavailable' };
            }
        } catch (err) {
            console.error('[analytics-vue] explain fetch failed:', err);
            store.aiSummaries[key] = { error: 'Network error. Please try again.' };
        } finally {
            store.aiLoading[key] = false;
        }
    }

    function toggleExplain(key, force) {
        if (store.aiSummaries[key] && !force) {
            store.aiVisible[key] = !store.aiVisible[key];
            return;
        }
        if (store.aiLoading[key]) return;
        if (force) store.aiSummaries[key] = null;
        store.aiLoading[key] = true;
        store.aiVisible[key] = true;
        fetchExplain(key, force);
    }

    // ---- Charts ----

    const charts = { revenue: null, topItems: null };

    function renderRevenueChart() {
        const canvas = document.getElementById('chart-revenue');
        if (!canvas) return;
        const trend = store.reports.revenue_trend && store.reports.revenue_trend.data;
        if (!trend || !Array.isArray(trend.days)) return;

        const labels  = trend.days.map(function (d) { return formatDateHuman(d.date); });
        const revenue = trend.days.map(function (d) { return d.revenue; });
        const orders  = trend.days.map(function (d) { return d.orders; });

        if (charts.revenue) charts.revenue.destroy();

        charts.revenue = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Revenue (T)',
                        data: revenue,
                        borderColor: '#6f4e37',
                        backgroundColor: 'rgba(111,78,55,0.10)',
                        borderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Orders',
                        data: orders,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34,197,94,0.0)',
                        borderWidth: 2,
                        tension: 0.35,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        yAxisID: 'y1',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { family: 'Inter' } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const v = ctx.parsed.y;
                                return ctx.dataset.label + ': ' + v.toLocaleString();
                            },
                        },
                    },
                },
                scales: {
                    y: {
                        position: 'left',
                        beginAtZero: true,
                        ticks: { callback: function (v) { return v.toLocaleString(); } },
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        ticks: { precision: 0 },
                    },
                    x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                },
            },
        });
    }

    function renderTopItemsChart() {
        const canvas = document.getElementById('chart-top-items');
        if (!canvas) return;
        const top = store.reports.top_items && store.reports.top_items.data;
        if (!top || !Array.isArray(top.items)) return;

        const labels = top.items.map(function (i) { return i.name; });
        const qty    = top.items.map(function (i) { return i.qty; });

        if (charts.topItems) charts.topItems.destroy();

        charts.topItems = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Quantity sold',
                    data: qty,
                    backgroundColor: 'rgba(111,78,55,0.75)',
                    borderColor: '#6f4e37',
                    borderWidth: 1,
                    borderRadius: 4,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ctx.parsed.x.toLocaleString() + ' sold'; },
                        },
                    },
                },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 } },
                    y: { ticks: { font: { family: 'Inter' } } },
                },
            },
        });
    }

    function renderAllCharts() {
        nextTick(function () {
            renderRevenueChart();
            renderTopItemsChart();
        });
    }

    window.addEventListener('resize', function () {
        if (charts.revenue)  { try { charts.revenue.resize();  } catch (e) {} }
        if (charts.topItems) { try { charts.topItems.resize(); } catch (e) {} }
    });

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
            const loading = computed(function () {
                return !!store.aiLoading[props.reportKey];
            });
            const hasSummary = computed(function () {
                const s = store.aiSummaries[props.reportKey];
                return !!(s && s.text);
            });
            const isVisible = computed(function () {
                return !!store.aiVisible[props.reportKey];
            });
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
            function click() { toggleExplain(props.reportKey, false); }
            return { loading, iconClass, label, click };
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
                        <button type="button" class="ai-summary-regen" @click="regenerate" :disabled="loading" title="Regenerate">
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
            function retry() { toggleExplain(props.reportKey, true); }
            function regenerate() { toggleExplain(props.reportKey, true); }
            return { loading, visible, summaryText, errorText, providerText, stateClass, retry, regenerate };
        }
    };

    // ---- Toolbar & Summary ----

    const Toolbar = {
        template: `
            <div class="analytics-toolbar">
                <div class="toolbar-left">
                    <span class="toolbar-label"><i class="fas fa-calendar"></i> Range:</span>
                    <input type="date" class="date-input" v-model="store.start">
                    <span class="range-sep">to</span>
                    <input type="date" class="date-input" v-model="store.end">
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
                        {{ store.loading ? 'Loading...' : 'Refresh' }}
                    </button>
                </div>
            </div>
        `,
        setup() {
            return {
                store,
                setRange: function (n) { applyRange(n); },
                applyCustom: applyCustomRange,
                refresh: forceRefresh,
            };
        }
    };

    const SummaryCard = {
        template: `
            <div class="report-card summary-card">
                <div class="report-title-row">
                    <h3 class="report-title"><i class="fas fa-chart-pie"></i> Summary</h3>
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
                const rt = store.reports.revenue_trend;
                return (rt && rt.data && rt.data.summary) ? rt.data.summary : {};
            });
            return {
                formatNumber,
                revenue: computed(function () { return summary.value.total_revenue || 0; }),
                orders: computed(function () { return summary.value.total_orders || 0; }),
                aov: computed(function () { return summary.value.avg_order_value || 0; }),
                daysWithOrders: computed(function () { return summary.value.days_with_orders || 0; }),
            };
        }
    };

    // ---- Report components ----

    const RevenueChart = {
        components: { AiExplainButton, AiSummaryCard },
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
                const rt = store.reports.revenue_trend;
                return (rt && rt.title) ? rt.title : 'Revenue Trend';
            });
            const hasData = computed(function () {
                const rt = store.reports.revenue_trend;
                const d = rt && rt.data;
                return d && Array.isArray(d.days) && d.days.length > 0;
            });
            return { store, title, hasData };
        }
    };

    const TopItemsChart = {
        components: { AiExplainButton, AiSummaryCard },
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
                const ti = store.reports.top_items;
                return (ti && ti.title) ? ti.title : 'Top Items';
            });
            const hasData = computed(function () {
                const ti = store.reports.top_items;
                const d = ti && ti.data;
                return d && Array.isArray(d.items) && d.items.length > 0;
            });
            return { store, title, hasData };
        }
    };

    const HeatmapGrid = {
        components: { AiExplainButton, AiSummaryCard },
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
                const hm = store.reports.hourly_heatmap;
                return (hm && hm.title) ? hm.title : 'Orders by Hour';
            });
            const grid = computed(function () {
                const hm = store.reports.hourly_heatmap;
                const d = hm && hm.data;
                return (d && Array.isArray(d.grid)) ? d.grid : [];
            });
            const max = computed(function () {
                const hm = store.reports.hourly_heatmap;
                const d = hm && hm.data;
                return (d && d.max) ? d.max : 0;
            });
            const hours = computed(function () {
                const hm = store.reports.hourly_heatmap;
                const d = hm && hm.data;
                return (d && Array.isArray(d.hour_names)) ? d.hour_names : [];
            });
            const dayNames = computed(function () {
                const hm = store.reports.hourly_heatmap;
                const d = hm && hm.data;
                return (d && Array.isArray(d.day_names)) ? d.day_names : [];
            });
            const hasData = computed(function () { return grid.value.length > 0 && max.value > 0; });
            function cellStyle(val) {
                if (max.value === 0) return { background: '#f3e8e0' };
                const intensity = val / max.value;
                const alpha = 0.08 + intensity * 0.75;
                return { background: 'rgba(111,78,55,' + alpha.toFixed(3) + ')' };
            }
            function cellTitle(dayIdx, hrIdx, val) {
                const day = dayNames.value[dayIdx] || '';
                const hour = hours.value[hrIdx] || '';
                return day + ' ' + hour + ':00 - ' + val + ' orders';
            }
            return { store, title, grid, hours, dayNames, hasData, cellStyle, cellTitle };
        }
    };

    const LeastItemsTable = {
        components: { AiExplainButton, AiSummaryCard },
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
                const li = store.reports.least_items;
                const d = li && li.data;
                return (d && Array.isArray(d.items)) ? d.items : [];
            });
            const hasData = computed(function () { return items.value.length > 0; });
            const title = computed(function () {
                const li = store.reports.least_items;
                return (li && li.title) ? li.title : 'Least-Selling Items';
            });
            return { store, title, items, hasData, formatNumber };
        }
    };

    const RisingItemsTable = {
        components: { AiExplainButton, AiSummaryCard },
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
                <div v-else class="empty-state"><i class="fas fa-inbox"></i> Nothing is rising notably.</div>
                <AiSummaryCard reportKey="rising_items"></AiSummaryCard>
            </div>
        `,
        setup() {
            const title = computed(function () {
                const r = store.reports.rising_items;
                return (r && r.title) ? r.title : 'Rising Items';
            });
            const items = computed(function () {
                const r = store.reports.rising_items;
                const d = r && r.data;
                return (d && Array.isArray(d.items)) ? d.items : [];
            });
            const hasData = computed(function () { return items.value.length > 0; });
            return { store, title, items, hasData };
        }
    };

    const ItemCombosTable = {
        components: { AiExplainButton, AiSummaryCard },
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
            const title = computed(function () {
                const c = store.reports.item_combos;
                return (c && c.title) ? c.title : 'Frequently Bought Together';
            });
            const combos = computed(function () {
                const c = store.reports.item_combos;
                const d = c && c.data;
                return (d && Array.isArray(d.combos)) ? d.combos : [];
            });
            const hasData = computed(function () { return combos.value.length > 0; });
            return { store, title, combos, hasData };
        }
    };

    const FadingItemsTable = {
        components: { AiExplainButton, AiSummaryCard },
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
                <div v-else class="empty-state"><i class="fas fa-inbox"></i> Nothing is fading notably.</div>
                <AiSummaryCard reportKey="fading_items"></AiSummaryCard>
            </div>
        `,
        setup() {
            const title = computed(function () {
                const f = store.reports.fading_items;
                return (f && f.title) ? f.title : 'Fading Items';
            });
            const items = computed(function () {
                const f = store.reports.fading_items;
                const d = f && f.data;
                return (d && Array.isArray(d.items)) ? d.items : [];
            });
            const hasData = computed(function () { return items.value.length > 0; });
            return { store, title, items, hasData };
        }
    };

    const PriceTierShiftGrid = {
        components: { AiExplainButton, AiSummaryCard },
        template: `
            <div class="report-card report-card-wide">
                <div class="report-title-row">
                    <h3 class="report-title"><i class="fas fa-layer-group"></i> {{ title }}</h3>
                    <AiExplainButton reportKey="price_tier_shift"></AiExplainButton>
                </div>
                <div v-if="hasData" class="tier-grid">
                    <div v-for="tier in tiers" :key="tier.key" class="tier-card">
                        <div class="tier-header">
                            <i class="fas fa-tag"></i>
                            <span>{{ tier.name }}</span>
                        </div>
                        <div class="tier-range">{{ tier.range }}</div>
                        <div class="tier-stat">
                            <span class="tier-label">Before</span>
                            <span class="tier-value">{{ tier.qty_before }} <small>units</small></span>
                        </div>
                        <div class="tier-stat">
                            <span class="tier-label">After</span>
                            <span class="tier-value">{{ tier.qty_after }} <small>units</small></span>
                        </div>
                        <div class="tier-stat" v-if="tier.change_pct !== null">
                            <span class="tier-label">Change</span>
                            <span class="trend-pill" :class="tier.change_pct >= 0 ? 'up' : 'down'">
                                <i :class="tier.change_pct >= 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down'"></i>
                                {{ tier.change_pct > 0 ? '+' : '' }}{{ tier.change_pct }}%
                            </span>
                        </div>
                    </div>
                </div>
                <div v-else class="empty-state"><i class="fas fa-inbox"></i> No price tiers to display.</div>
                <AiSummaryCard reportKey="price_tier_shift"></AiSummaryCard>
            </div>
        `,
        setup() {
            const title = computed(function () {
                const p = store.reports.price_tier_shift;
                return (p && p.title) ? p.title : 'Price Tier Shift';
            });
            const tiers = computed(function () {
                const p = store.reports.price_tier_shift;
                const d = p && p.data;
                return (d && Array.isArray(d.tiers)) ? d.tiers : [];
            });
            const hasData = computed(function () { return tiers.value.length > 0; });
            return { store, title, tiers, hasData };
        }
    };

    // ---- Root app ----
    // Layout order (fixed to avoid orphan rows):
    //   Row 1: Summary (full width)
    //   Row 2: Revenue Trend | Top Items
    //   Row 3: Orders by Hour (full width - needs horizontal room)
    //   Row 4: Least Items | Rising Items
    //   Row 5: Item Combos | Fading Items
    //   Row 6: Price Tier Shift (full width - three tier cards)

    const AnalyticsApp = {
        components: {
            Toolbar,
            SummaryCard,
            RevenueChart,
            TopItemsChart,
            HeatmapGrid,
            LeastItemsTable,
            RisingItemsTable,
            ItemCombosTable,
            FadingItemsTable,
            PriceTierShiftGrid,
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
        setup() { return { store }; }
    };

    const mountEl = document.getElementById('vue-analytics-root');
    if (!mountEl) {
        console.error('[analytics-vue] #vue-analytics-root not found');
        return;
    }

    try {
        createApp(AnalyticsApp).mount('#vue-analytics-root');
        console.log('[analytics-vue] mounted successfully');
    } catch (err) {
        console.error('[analytics-vue] mount failed:', err);
        return;
    }

    renderAllCharts();

    let lastReportVersion = JSON.stringify(store.reports);
    setInterval(function () {
        const v = JSON.stringify(store.reports);
        if (v !== lastReportVersion) {
            lastReportVersion = v;
            renderAllCharts();
        }
    }, 500);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startAnalyticsApp);
} else {
    startAnalyticsApp();
}
