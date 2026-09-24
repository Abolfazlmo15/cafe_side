// assets/js/admin/analytics-vue.js
// Vue 3 analytics dashboard for the admin panel.
// ASCII-only source. No em-dashes, no ellipsis characters, no
// smart quotes. Safe to copy-paste across any encoding.

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
        end: initial.end || '',
        reports: initial.reports || {},
        loading: false,
        lastRefreshed: null,
    });

    window.__ANALYTICS_STORE__ = store;
    console.log('[analytics-vue] store ready. Report keys:', Object.keys(store.reports));

    // ---- Helpers ----

    function formatNumber(n) {
        return ('' + n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function formatDateHuman(iso) {
        const d = new Date(iso + 'T00:00:00');
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    }

    // ---- Data loading ----

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
        store.end = end.toISOString().slice(0, 10);
        store.start = start.toISOString().slice(0, 10);
        fetchReports(false);
    }

    function applyCustomRange() {
        if (!store.start || !store.end) return;
        if (store.start > store.end) {
            alert('Start date must be before end date.');
            return;
        }
        fetchReports(false);
    }

    function forceRefresh() {
        fetchReports(true);
    }

    // ---- Charts ----

    const charts = { revenue: null, topItems: null };

    function renderRevenueChart() {
        const canvas = document.getElementById('chart-revenue');
        if (!canvas) return;
        const trend = store.reports.revenue_trend && store.reports.revenue_trend.data;
        if (!trend || !Array.isArray(trend.days)) return;

        const labels = trend.days.map(function (d) { return formatDateHuman(d.date); });
        const revenue = trend.days.map(function (d) { return d.revenue; });
        const orders = trend.days.map(function (d) { return d.orders; });

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
        const qty = top.items.map(function (i) { return i.qty; });

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

    // ---- Components ----
    // Templates use plain hyphen or nothing. No em-dash, no ellipsis,
    // no smart quotes, no non-ASCII characters anywhere.

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
                store: store,
                setRange: function (n) { applyRange(n); },
                applyCustom: applyCustomRange,
                refresh: forceRefresh,
            };
        }
    };

    const SummaryCard = {
        template: `
            <div class="report-card summary-card">
                <h3 class="report-title"><i class="fas fa-chart-pie"></i> Summary</h3>
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
                formatNumber: formatNumber,
                revenue: computed(function () { return summary.value.total_revenue || 0; }),
                orders: computed(function () { return summary.value.total_orders || 0; }),
                aov: computed(function () { return summary.value.avg_order_value || 0; }),
                daysWithOrders: computed(function () { return summary.value.days_with_orders || 0; }),
            };
        }
    };

    const RevenueChart = {
        template: `
            <div class="report-card">
                <h3 class="report-title">
                    <i class="fas fa-chart-line"></i>
                    {{ title }}
                </h3>
                <div v-if="hasData" class="chart-wrapper">
                    <canvas id="chart-revenue"></canvas>
                </div>
                <div v-else class="empty-state">
                    <i class="fas fa-inbox"></i> No data in this range.
                </div>
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
            return { store: store, title: title, hasData: hasData };
        }
    };

    const TopItemsChart = {
        template: `
            <div class="report-card">
                <h3 class="report-title">
                    <i class="fas fa-fire"></i>
                    {{ title }}
                </h3>
                <div v-if="hasData" class="chart-wrapper chart-wrapper-tall">
                    <canvas id="chart-top-items"></canvas>
                </div>
                <div v-else class="empty-state">
                    <i class="fas fa-inbox"></i> No data in this range.
                </div>
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
            return { store: store, title: title, hasData: hasData };
        }
    };

    const LeastItemsTable = {
        template: `
            <div class="report-card">
                <h3 class="report-title">
                    <i class="fas fa-snowflake"></i>
                    {{ title }}
                </h3>
                <div v-if="hasData" class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="num">Sold</th>
                                <th class="num">Revenue (T)</th>
                            </tr>
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
                <div v-else class="empty-state">
                    <i class="fas fa-inbox"></i> No data in this range.
                </div>
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
            return {
                store: store,
                title: title,
                items: items,
                hasData: hasData,
                formatNumber: formatNumber,
            };
        }
    };

    const HeatmapGrid = {
        template: `
            <div class="report-card report-card-wide">
                <h3 class="report-title">
                    <i class="fas fa-th"></i>
                    {{ title }}
                </h3>
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
                        <span>Low</span>
                        <div class="legend-bar"></div>
                        <span>High</span>
                    </div>
                </div>
                <div v-else class="empty-state">
                    <i class="fas fa-inbox"></i> No data in this range.
                </div>
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
            const hasData = computed(function () {
                return grid.value.length > 0 && max.value > 0;
            });

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

            return {
                store: store,
                title: title,
                grid: grid,
                hours: hours,
                dayNames: dayNames,
                hasData: hasData,
                cellStyle: cellStyle,
                cellTitle: cellTitle,
            };
        }
    };

    const AnalyticsApp = {
        components: {
            Toolbar: Toolbar,
            SummaryCard: SummaryCard,
            RevenueChart: RevenueChart,
            TopItemsChart: TopItemsChart,
            LeastItemsTable: LeastItemsTable,
            HeatmapGrid: HeatmapGrid,
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
                    <LeastItemsTable></LeastItemsTable>
                    <HeatmapGrid></HeatmapGrid>
                </div>
            </div>
        `,
        setup() { return { store: store }; }
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

    // Re-render charts whenever the reports object changes.
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
