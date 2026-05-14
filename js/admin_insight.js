/**
 * SEMS Admin — admin_insight.js v2
 * Global filtering: all charts + activity + table update together.
 *
 * Requires SEMS_INSIGHT_DATA to be defined inline before this script loads:
 *   const SEMS_INSIGHT_DATA = {
 *       events:     [...],   // full event rows including is_restricted, type_name
 *       activities: [...],   // activity rows including event_id
 *       reqGen:     { required: N, general: N },
 *   };
 */

// ═══════════════════════════════════════════════════════════════
// DATA
// ═══════════════════════════════════════════════════════════════
const _d             = (typeof SEMS_INSIGHT_DATA !== 'undefined') ? SEMS_INSIGHT_DATA : {};
const eventsData     = _d.events     || [];
const activitiesData = _d.activities || [];

// ═══════════════════════════════════════════════════════════════
// CHART INSTANCES
// ═══════════════════════════════════════════════════════════════
let monthlyChart, deptChart, typeChart, statusChart, reqGenChart;

// ═══════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════
const isDarkMode  = () => document.documentElement.classList.contains('dark');
const textColor   = () => isDarkMode() ? '#94a3b8' : '#64748b';
const gridColor   = () => isDarkMode() ? 'rgba(148,163,184,0.1)' : 'rgba(100,116,139,0.1)';
const CHART_COLORS = ['#3B82F6', '#0ea5e9', '#9333ea', '#10b981', '#f59e0b', '#f43f5e'];

function _esc(s) {
    if (!s) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ═══════════════════════════════════════════════════════════════
// DARK MODE
// ═══════════════════════════════════════════════════════════════
function toggleTheme() {
    const dark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('sems-theme', dark ? 'dark' : 'light');
    _applyThemeUI(dark);
    updateChartTheme();
}

function _applyThemeUI(dark) {
    const icon  = document.getElementById('theme-icon');
    const label = document.getElementById('theme-label');
    if (!icon || !label) return;
    icon.className    = dark ? 'fas fa-sun w-5 text-center text-amber-500' : 'fas fa-moon w-5 text-center';
    label.textContent = dark ? 'Light Mode' : 'Dark Mode';
}

(function () { _applyThemeUI(isDarkMode()); })();

// ═══════════════════════════════════════════════════════════════
// SIDEBAR
// ═══════════════════════════════════════════════════════════════
function openSidebar() {
    document.getElementById('sidebar').classList.remove('-translate-x-full');
    const ov = document.getElementById('sidebarOverlay');
    ov.classList.remove('pointer-events-none', 'opacity-0');
    ov.classList.add('pointer-events-auto', 'opacity-100');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    document.getElementById('sidebar').classList.add('-translate-x-full');
    const ov = document.getElementById('sidebarOverlay');
    ov.classList.remove('pointer-events-auto', 'opacity-100');
    ov.classList.add('pointer-events-none', 'opacity-0');
    document.body.style.overflow = '';
}

// ═══════════════════════════════════════════════════════════════
// DERIVE ALL CHART DATA FROM A FILTERED EVENTS ARRAY
// ═══════════════════════════════════════════════════════════════
function deriveData(filtered) {
    // 1 — Monthly trend (last 6 months)
    const monthMap = {};
    filtered.forEach(e => {
        const d   = new Date(e.start_datetime);
        const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
        const lbl = d.toLocaleString('en-US', { month: 'short', year: 'numeric' });
        if (!monthMap[key]) monthMap[key] = { label: lbl, count: 0 };
        monthMap[key].count++;
    });
    const months = Object.entries(monthMap)
        .sort(([a], [b]) => a.localeCompare(b))
        .slice(-6)
        .map(([, v]) => v);

    // 2 — Dept attendance (sum attended per dept)
    const deptMap = {};
    filtered.forEach(e => {
        const dept = e.dept_name || 'N/A';
        deptMap[dept] = (deptMap[dept] || 0) + parseInt(e.attended || 0);
    });

    // 3 — Event type counts
    const typeMap = {};
    filtered.forEach(e => {
        if (e.type_name) typeMap[e.type_name] = (typeMap[e.type_name] || 0) + 1;
    });
    const types = Object.entries(typeMap).sort(([, a], [, b]) => b - a).slice(0, 6);

    // 4 — Status breakdown
    const statusMap = { Completed: 0, Upcoming: 0, 'In Progress': 0 };
    filtered.forEach(e => { if (e.event_status in statusMap) statusMap[e.event_status]++; });

    // 5 — Required vs General
    const reqGen = { required: 0, general: 0 };
    filtered.forEach(e => {
        parseInt(e.is_restricted) === 1 ? reqGen.required++ : reqGen.general++;
    });

    // 6 — Stat card values
    const totalAtt = filtered.reduce((s, e) => s + parseInt(e.attended || 0), 0);
    const totalCap = filtered.reduce((s, e) => s + parseInt(e.capacity || 100), 0);
    const avgAtt   = totalCap > 0
        ? Math.min(100, parseFloat((totalAtt / totalCap * 100).toFixed(1)))
        : 0;
    const rated    = filtered.filter(e => e.avg_rating != null && e.avg_rating !== '');
    const avgRat   = rated.length
        ? (rated.reduce((s, e) => s + parseFloat(e.avg_rating), 0) / rated.length).toFixed(1)
        : 'N/A';

    return { months, deptMap, types, statusMap, reqGen, avgAtt, avgRat };
}

// ═══════════════════════════════════════════════════════════════
// INIT CHARTS (initial render with full dataset)
// ═══════════════════════════════════════════════════════════════
function initCharts() {
    const data = deriveData(eventsData);
    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";

    // Monthly Trend
    monthlyChart = new Chart(document.getElementById('monthlyTrendChart'), {
        type: 'bar',
        data: {
            labels:   data.months.map(m => m.label),
            datasets: [{
                label:           'Approved Events',
                data:            data.months.map(m => m.count),
                backgroundColor: '#3B82F6',
                borderRadius:    6,
                barThickness:    24,
            }],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { font: { size: 11 } }, grid: { display: false } },
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, font: { size: 11 }, padding: 10 },
                    grid: { drawBorder: false },
                    border: { display: false },
                },
            },
        },
    });

    // Dept Attendance
    deptChart = new Chart(document.getElementById('deptAttendanceChart'), {
        type: 'bar',
        data: {
            labels:   Object.keys(data.deptMap).map(l => l.length > 15 ? l.slice(0, 15) + '…' : l),
            datasets: [{
                label:           'Attendees',
                data:            Object.values(data.deptMap),
                backgroundColor: '#0ea5e9',
                borderRadius:    6,
            }],
        },
        options: {
            responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { font: { size: 11 } }, grid: { color: gridColor(), drawBorder: false }, border: { display: false } },
                y: { ticks: { font: { size: 11 } }, grid: { display: false }, border: { display: false } },
            },
        },
    });

    // Event Type Doughnut
    typeChart = new Chart(document.getElementById('eventTypeChart'), {
        type: 'doughnut',
        data: {
            labels:   data.types.map(([k]) => k),
            datasets: [{ data: data.types.map(([, v]) => v), backgroundColor: CHART_COLORS, borderWidth: 0, hoverOffset: 4 }],
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '75%',
            plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: { size: 11 } } } },
        },
    });

    // Event Status Pie
    statusChart = new Chart(document.getElementById('eventStatusChart'), {
        type: 'pie',
        data: {
            labels:   Object.keys(data.statusMap),
            datasets: [{ data: Object.values(data.statusMap), backgroundColor: ['#10b981', '#f59e0b', '#3B82F6'], borderWidth: 0 }],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, font: { size: 11 } } } },
        },
    });

    // Required vs General
    reqGenChart = new Chart(document.getElementById('reqGenChart'), {
        type: 'bar',
        data: {
            labels: ['Required (Dept-Specific)', 'General (Voluntary)'],
            datasets: [{
                label:           'Events',
                data:            [data.reqGen.required, data.reqGen.general],
                backgroundColor: ['#f43f5e', '#10b981'],
                borderRadius:    8,
                barThickness:    40,
            }],
        },
        options: {
            responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label(ctx) {
                            const total = data.reqGen.required + data.reqGen.general;
                            const pct   = total > 0 ? Math.round(ctx.raw / total * 100) : 0;
                            return ` ${ctx.raw} events (${pct}%)`;
                        },
                    },
                },
            },
            scales: {
                x: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { drawBorder: false }, border: { display: false } },
                y: { ticks: { font: { size: 12, weight: '600' } }, grid: { display: false }, border: { display: false } },
            },
        },
    });
}

// ═══════════════════════════════════════════════════════════════
// UPDATE ALL CHARTS WITH NEW DERIVED DATA
// ═══════════════════════════════════════════════════════════════
function updateAllCharts(data) {
    // Monthly
    monthlyChart.data.labels = data.months.map(m => m.label);
    monthlyChart.data.datasets[0].data = data.months.map(m => m.count);
    monthlyChart.update();

    // Dept
    const dLabels = Object.keys(data.deptMap);
    deptChart.data.labels = dLabels.map(l => l.length > 15 ? l.slice(0, 15) + '…' : l);
    deptChart.data.datasets[0].data = Object.values(data.deptMap);
    deptChart.update();

    // Type
    typeChart.data.labels = data.types.map(([k]) => k);
    typeChart.data.datasets[0].data = data.types.map(([, v]) => v);
    typeChart.update();

    // Status
    statusChart.data.datasets[0].data = Object.values(data.statusMap);
    statusChart.update();

    // Req vs Gen
    reqGenChart.data.datasets[0].data = [data.reqGen.required, data.reqGen.general];
    reqGenChart.update();

    // Badges
    const reqEl = document.getElementById('req-count');
    const genEl = document.getElementById('gen-count');
    if (reqEl) reqEl.textContent = data.reqGen.required;
    if (genEl) genEl.textContent = data.reqGen.general;

    // Stat cards
    const avgAttEl = document.getElementById('stat-avg-att');
    const avgRatEl = document.getElementById('stat-avg-rat');
    if (avgAttEl) avgAttEl.textContent = data.avgAtt + '%';
    if (avgRatEl) avgRatEl.innerHTML = data.avgRat + ' <span class="text-sm text-slate-400 font-normal">/ 5</span>';
}

// ═══════════════════════════════════════════════════════════════
// RENDER RECENT ACTIVITY
// ═══════════════════════════════════════════════════════════════
function renderRecentActivity(filteredIds) {
    const container = document.getElementById('recentActivityList');
    if (!container) return;

    let display = activitiesData;
    if (filteredIds !== null) {
        display = activitiesData.filter(a => filteredIds.has(String(a.event_id)));
    }
    display = display.slice(0, 5);

    if (!display.length) {
        container.innerHTML = '<p class="text-sm text-slate-400 text-center py-6 italic">No activity matches the current filters.</p>';
        return;
    }

    container.innerHTML = display.map(a => {
        const diff    = (Date.now() - new Date(a.date).getTime()) / 1000;
        const timeAgo = diff < 3600   ? 'Just now'
            : diff < 86400  ? `${Math.floor(diff / 3600)}h ago`
            : `${Math.floor(diff / 86400)}d ago`;
        const details = a.details
            ? `<p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate">${_esc(a.details)}</p>`
            : '';
        return `
        <div class="relative flex gap-4 items-start">
            <div class="absolute -left-[25px] w-6 h-6 rounded-full bg-white dark:bg-slate-800 border-2 border-white dark:border-slate-800 flex items-center justify-center shadow-sm">
                <i class="fas ${_esc(a.icon)} text-xs ${_esc(a.color)}"></i>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900 dark:text-white truncate">${_esc(a.action)}</p>
                ${details}
                <span class="text-xs font-semibold text-slate-400 block mt-1">${timeAgo}</span>
            </div>
        </div>`;
    }).join('');
}

// ═══════════════════════════════════════════════════════════════
// GLOBAL FILTER APPLY
// ═══════════════════════════════════════════════════════════════
function applyFilters() {
    const search    = document.getElementById('searchEvent').value.toLowerCase().trim();
    const status    = document.getElementById('filterStatus').value;
    const dept      = document.getElementById('filterDept').value;
    const anyFilter = search || status || dept;

    const filtered = eventsData.filter(e =>
        (!search || e.title.toLowerCase().includes(search)) &&
        (!status || e.event_status === status) &&
        (!dept   || e.dept_name   === dept)
    );

    updateAllCharts(deriveData(filtered));
    renderEventTable(filtered);
    renderRecentActivity(
        anyFilter ? new Set(filtered.map(e => String(e.event_id))) : null
    );
}

function resetFilters() {
    document.getElementById('searchEvent').value  = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterDept').value   = '';
    applyFilters();
}

// ═══════════════════════════════════════════════════════════════
// EVENT TABLE
// ═══════════════════════════════════════════════════════════════
function renderEventTable(filtered) {
    if (!filtered) {
        const search = document.getElementById('searchEvent').value.toLowerCase();
        const status = document.getElementById('filterStatus').value;
        const dept   = document.getElementById('filterDept').value;
        filtered = eventsData.filter(e =>
            (!search || e.title.toLowerCase().includes(search)) &&
            (!status || e.event_status === status) &&
            (!dept   || e.dept_name   === dept)
        );
    }

    const tbody = document.getElementById('eventTableBody');

    if (!filtered.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="px-6 py-8 text-center text-slate-500 dark:text-slate-400">No events found matching your criteria.</td></tr>';
        return;
    }

    tbody.innerHTML = filtered.slice(0, 10).map(e => {
        const badge = e.event_status === 'Completed'
            ? '<span class="px-3 py-1 text-xs font-bold rounded-full bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400">Completed</span>'
            : e.event_status === 'In Progress'
            ? '<span class="px-3 py-1 text-xs font-bold rounded-full bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400">In Progress</span>'
            : '<span class="px-3 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400">Upcoming</span>';

        const ratingHtml = e.avg_rating
            ? `<div class="flex items-center justify-center gap-1 font-bold text-slate-900 dark:text-white"><i class="fas fa-star text-yellow-400 text-xs"></i> ${e.avg_rating}</div>`
            : '<span class="text-slate-400 text-xs">No rating</span>';

        return `
        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
            <td class="px-6 py-4">
                <p class="font-bold text-slate-900 dark:text-white truncate max-w-[200px]" title="${_esc(e.title)}">${_esc(e.title)}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 truncate max-w-[200px]">${_esc(e.dept_name || 'General')}</p>
            </td>
            <td class="px-6 py-4">${badge}</td>
            <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                    <div class="w-full bg-gray-100 dark:bg-slate-700 rounded-full h-1.5 max-w-[80px]">
                        <div class="bg-primary-500 h-1.5 rounded-full transition-all duration-500" style="width:${e.attendance_pct}%"></div>
                    </div>
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300">${e.attendance_pct}%</span>
                </div>
            </td>
            <td class="px-6 py-4 text-center">${ratingHtml}</td>
            <td class="px-6 py-4 text-right">
                <button onclick="openEventModal(${e.event_id})"
                    class="text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 font-semibold text-sm transition-colors">
                    Insights <i class="fas fa-arrow-right ml-1 text-xs"></i>
                </button>
            </td>
        </tr>`;
    }).join('');
}

// ═══════════════════════════════════════════════════════════════
// UPDATE CHART THEME
// ═══════════════════════════════════════════════════════════════
function updateChartTheme() {
    const tc = textColor(), gc = gridColor();
    [monthlyChart, deptChart].forEach(chart => {
        if (!chart) return;
        if (chart.options.scales.x) chart.options.scales.x.ticks.color = tc;
        if (chart.options.scales.y) {
            chart.options.scales.y.ticks.color = tc;
            if (chart.options.scales.y.grid) chart.options.scales.y.grid.color = gc;
        }
        chart.update();
    });
}

// ═══════════════════════════════════════════════════════════════
// CSV DOWNLOADS
// ═══════════════════════════════════════════════════════════════
function _getFiltered() {
    const search = document.getElementById('searchEvent').value.toLowerCase();
    const status = document.getElementById('filterStatus').value;
    const dept   = document.getElementById('filterDept').value;
    return eventsData.filter(e =>
        (!search || e.title.toLowerCase().includes(search)) &&
        (!status || e.event_status === status) &&
        (!dept   || e.dept_name   === dept)
    );
}

function downloadDeptCSV() {
    const deptMap = deriveData(_getFiltered()).deptMap;
    if (!Object.keys(deptMap).length) { alert('No data to download.'); return; }
    let csv = 'Department,Total Attendees\n';
    Object.entries(deptMap).forEach(([k, v]) => { csv += `"${k}",${v}\n`; });
    _triggerCSVDownload(csv, 'attendance_by_department.csv');
}

function downloadEventsCSV() {
    const filtered = _getFiltered();
    if (!filtered.length) { alert('No events to download.'); return; }
    let csv = 'Event Title,Type,Department,Event Status,Attendance %,Avg Rating,Feedback Count\n';
    filtered.forEach(e => {
        csv += `"${String(e.title).replace(/"/g, '""')}",`;
        csv += `"${e.type_name || 'N/A'}",`;
        csv += `"${e.dept_name || 'General'}",`;
        csv += `${e.event_status},${e.attendance_pct}%,${e.avg_rating || 'N/A'},${e.feedback_count}\n`;
    });
    _triggerCSVDownload(csv, 'event_performance.csv');
}

function _triggerCSVDownload(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = Object.assign(document.createElement('a'), { href: url, download: filename });
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ═══════════════════════════════════════════════════════════════
// EVENT DETAILS MODAL (AJAX)
// ═══════════════════════════════════════════════════════════════
let modal, modalContent;

function openEventModal(eventId) {
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        modal.querySelector('div').classList.remove('scale-95');
    }, 10);

    modalContent.innerHTML = '<div class="flex justify-center py-8"><i class="fas fa-circle-notch fa-spin text-3xl text-primary-500"></i></div>';

    const fd = new FormData();
    fd.append('ajax', 'event_detail');
    fd.append('eventId', eventId);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                modalContent.innerHTML = '<p class="text-red-500 font-medium text-center">Failed to load insights.</p>';
                return;
            }
            let html = '';
            if (data.categories.length) {
                html += '<div class="grid grid-cols-2 gap-3 mb-6">';
                data.categories.forEach(c => {
                    html += `
                    <div class="bg-gray-50 dark:bg-slate-700 p-3 rounded-xl border border-gray-100 dark:border-slate-600">
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-semibold mb-1">${_esc(c.category_name)}</p>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-star text-yellow-400 text-sm"></i>
                            <span class="font-black text-lg text-slate-900 dark:text-white">${c.avg_rating}</span>
                            <span class="text-xs text-slate-400">(${c.votes} votes)</span>
                        </div>
                    </div>`;
                });
                html += '</div>';
            }
            if (data.comments.length) {
                html += '<h4 class="font-bold text-sm text-slate-900 dark:text-white uppercase tracking-wider mb-3">Recent Feedback</h4>';
                html += '<div class="space-y-3 max-h-48 overflow-y-auto pr-2">';
                data.comments.forEach(c => {
                    html += `
                    <div class="bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-600 p-3 rounded-xl shadow-sm">
                        <p class="text-sm font-medium italic text-slate-600 dark:text-slate-300">"${_esc(c.comment)}"</p>
                        <p class="text-xs text-slate-400 mt-2 font-semibold">— ${_esc(c.reviewer)}
                            <span class="bg-gray-100 dark:bg-slate-700 px-2 py-0.5 rounded-full ml-1">${_esc(c.category_name)}</span>
                        </p>
                    </div>`;
                });
                html += '</div>';
            } else {
                html += '<p class="text-sm text-slate-500 font-medium text-center py-4 bg-gray-50 dark:bg-slate-700 rounded-xl">No written feedback yet.</p>';
            }
            modalContent.innerHTML = html;
        })
        .catch(() => {
            modalContent.innerHTML = '<p class="text-red-500 font-medium text-center">Error loading data.</p>';
        });
}

function closeEventModal() {
    modal.classList.add('opacity-0');
    modal.querySelector('div').classList.add('scale-95');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 300);
}

// ═══════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    modal        = document.getElementById('eventModal');
    modalContent = document.getElementById('modalContent');

    initCharts();
    renderEventTable();
    renderRecentActivity(null);

    // Global filter listeners
    ['searchEvent', 'filterStatus', 'filterDept'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener(id === 'searchEvent' ? 'input' : 'change', applyFilters);
    });

    // Modal: backdrop click + Escape
    modal.addEventListener('click', e => { if (e.target === modal) closeEventModal(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeEventModal();
    });

    // Sidebar
    document.querySelectorAll('#sidebar a').forEach(el => {
        el.addEventListener('click', () => { if (window.innerWidth < 1024) closeSidebar(); });
    });
    window.addEventListener('resize', () => { if (window.innerWidth >= 1024) closeSidebar(); });

    // Date
    const dateEl = document.getElementById('current-date');
    if (dateEl) {
        dateEl.textContent = new Date().toLocaleDateString('en-US', {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
        });
    }
});