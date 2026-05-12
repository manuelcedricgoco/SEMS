/**
 * SEMS Admin — admin_insight.js
 * Handles: Dark Mode, Sidebar, Charts, Event Table, Filters,
 *          Event Modal (AJAX), CSV Downloads, Keyboard/Resize Events
 *
 * Requires SEMS_INSIGHT_DATA to be defined inline before this script loads:
 *   <script>
 *     const SEMS_INSIGHT_DATA = {
 *         events:        [...],
 *         deptAtt:       [...],
 *         eventType:     [...],
 *         eventStatus:   {...},
 *         monthlyTrend:  [...],
 *         reqGen:        { required: N, general: N },
 *     };
 *   </script>
 */

// ═══════════════════════════════════════════════════════════════
// DATA (from bridge)
// ═══════════════════════════════════════════════════════════════

const _d           = (typeof SEMS_INSIGHT_DATA !== 'undefined') ? SEMS_INSIGHT_DATA : {};
const eventsData   = _d.events       || [];
const deptAttData  = _d.deptAtt      || [];
const eventTypeData  = _d.eventType  || [];
const eventStatusData = _d.eventStatus || {};
const monthlyTrendData = _d.monthlyTrend || [];
const reqGenData   = _d.reqGen       || { required: 0, general: 0 };

// ═══════════════════════════════════════════════════════════════
// DARK MODE
// ═══════════════════════════════════════════════════════════════

function toggleTheme() {
    const isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('sems-theme', isDark ? 'dark' : 'light');
    _applyThemeUI(isDark);
    updateChartTheme();
}

function _applyThemeUI(isDark) {
    const icon  = document.getElementById('theme-icon');
    const label = document.getElementById('theme-label');
    if (!icon || !label) return;
    if (isDark) {
        icon.className    = 'fas fa-sun w-5 text-center text-amber-500';
        label.textContent = 'Light Mode';
    } else {
        icon.className    = 'fas fa-moon w-5 text-center';
        label.textContent = 'Dark Mode';
    }
}

// Restore theme on load (flash prevention handled by inline IIFE in <head>)
(function () {
    const theme = localStorage.getItem('sems-theme') || 'light';
    _applyThemeUI(theme === 'dark');
})();

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

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#sidebar a').forEach(function (el) {
        el.addEventListener('click', function () {
            if (window.innerWidth < 1024) closeSidebar();
        });
    });
});

window.addEventListener('resize', function () {
    if (window.innerWidth >= 1024) closeSidebar();
});

// ═══════════════════════════════════════════════════════════════
// CHART.JS GLOBAL DEFAULTS
// ═══════════════════════════════════════════════════════════════

Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.color       = function () {
    return document.documentElement.classList.contains('dark') ? '#94a3b8' : '#64748b';
};
Chart.defaults.scale.grid.color = function () {
    return document.documentElement.classList.contains('dark')
        ? 'rgba(148, 163, 184, 0.1)'
        : 'rgba(100, 116, 139, 0.1)';
};
Chart.defaults.plugins.tooltip.backgroundColor = function () {
    return document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff';
};
Chart.defaults.plugins.tooltip.titleColor = function () {
    return document.documentElement.classList.contains('dark') ? '#f3f4f6' : '#111827';
};
Chart.defaults.plugins.tooltip.bodyColor = function () {
    return document.documentElement.classList.contains('dark') ? '#d1d5db' : '#4b5563';
};
Chart.defaults.plugins.tooltip.borderColor = function () {
    return document.documentElement.classList.contains('dark') ? '#374151' : '#e5e7eb';
};
Chart.defaults.plugins.tooltip.borderWidth  = 1;
Chart.defaults.plugins.tooltip.padding      = 12;
Chart.defaults.plugins.tooltip.cornerRadius = 8;

const chartColors = ['#3B82F6', '#0ea5e9', '#9333ea', '#10b981', '#f59e0b', '#f43f5e'];

// ═══════════════════════════════════════════════════════════════
// CHARTS
// ═══════════════════════════════════════════════════════════════

let monthlyChart, deptChart, typeChart, statusChart, reqGenChart;

function initCharts() {
    // ── Monthly Trend Bar Chart ──────────────────────────────
    monthlyChart = new Chart(document.getElementById('monthlyTrendChart'), {
        type: 'bar',
        data: {
            labels:   monthlyTrendData.map(function (d) { return d.month_label; }),
            datasets: [{
                label:           'Approved Events',
                data:            monthlyTrendData.map(function (d) { return d.total; }),
                backgroundColor: '#3B82F6',
                borderRadius:    6,
                barThickness:    24,
            }],
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
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

    // ── Dept Attendance Horizontal Bar Chart ─────────────────
    deptChart = new Chart(document.getElementById('deptAttendanceChart'), {
        type: 'bar',
        data: {
            labels:   deptAttData.map(function (d) {
                return d.dept_name.length > 15 ? d.dept_name.substring(0, 15) + '…' : d.dept_name;
            }),
            datasets: [{
                label:           'Attendees',
                data:            deptAttData.map(function (d) { return d.total; }),
                backgroundColor: '#0ea5e9',
                borderRadius:    6,
            }],
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            indexAxis:           'y',
            plugins: { legend: { display: false } },
            scales: {
                x: {
                    ticks:  { font: { size: 11 } },
                    grid:   { color: Chart.defaults.scale.grid.color(), drawBorder: false },
                    border: { display: false },
                },
                y: {
                    ticks:  { font: { size: 11 } },
                    grid:   { display: false },
                    border: { display: false },
                },
            },
        },
    });

    // ── Event Type Doughnut Chart ────────────────────────────
    typeChart = new Chart(document.getElementById('eventTypeChart'), {
        type: 'doughnut',
        data: {
            labels:   eventTypeData.map(function (d) { return d.type_name; }),
            datasets: [{
                data:            eventTypeData.map(function (d) { return d.total; }),
                backgroundColor: chartColors,
                borderWidth:     0,
                hoverOffset:     4,
            }],
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            cutout:              '75%',
            plugins: {
                legend: {
                    position: 'right',
                    labels:   { usePointStyle: true, padding: 15, font: { size: 11 } },
                },
            },
        },
    });

    // ── Event Status Pie Chart ───────────────────────────────
    statusChart = new Chart(document.getElementById('eventStatusChart'), {
        type: 'pie',
        data: {
            labels:   Object.keys(eventStatusData),
            datasets: [{
                data:            Object.values(eventStatusData),
                backgroundColor: ['#10b981', '#f59e0b', '#3B82F6'],
                borderWidth:     0,
            }],
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels:   { usePointStyle: true, padding: 15, font: { size: 11 } },
                },
            },
        },
    });

    // ── Required vs General Horizontal Bar Chart ─────────────
    reqGenChart = new Chart(document.getElementById('reqGenChart'), {
        type: 'bar',
        data: {
            labels: ['Required (Dept-Specific)', 'General (Voluntary)'],
            datasets: [{
                label:           'Number of Events',
                data:            [reqGenData.required, reqGenData.general],
                backgroundColor: ['#f43f5e', '#10b981'],
                borderRadius:    8,
                barThickness:    40,
            }],
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            indexAxis:           'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            var total = reqGenData.required + reqGenData.general;
                            var pct   = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                            return ' ' + ctx.raw + ' events (' + pct + '%)';
                        },
                    },
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks:       { stepSize: 1, font: { size: 11 } },
                    grid:        { drawBorder: false },
                    border:      { display: false },
                },
                y: {
                    ticks:  { font: { size: 12, weight: '600' } },
                    grid:   { display: false },
                    border: { display: false },
                },
            },
        },
    });
}

function updateChartTheme() {
    if (!monthlyChart) return;
    const textColor = document.documentElement.classList.contains('dark') ? '#94a3b8' : '#64748b';
    const gridColor = document.documentElement.classList.contains('dark')
        ? 'rgba(148, 163, 184, 0.1)'
        : 'rgba(100, 116, 139, 0.1)';

    [monthlyChart, deptChart].forEach(function (chart) {
        if (!chart) return;
        if (chart.options.scales.x) chart.options.scales.x.ticks.color = textColor;
        if (chart.options.scales.y) {
            chart.options.scales.y.ticks.color = textColor;
            chart.options.scales.y.grid.color  = gridColor;
        }
        chart.update();
    });
}

// ═══════════════════════════════════════════════════════════════
// EVENT TABLE RENDER
// ═══════════════════════════════════════════════════════════════

function renderEventTable() {
    var search = document.getElementById('searchEvent').value.toLowerCase();
    var status = document.getElementById('filterStatus').value;
    var dept   = document.getElementById('filterDept').value;
    var tbody  = document.getElementById('eventTableBody');

    tbody.innerHTML = '';

    var filtered = eventsData.filter(function (e) {
        var matchSearch = e.title.toLowerCase().includes(search);
        var matchStatus = status ? e.event_status === status : true;
        var matchDept   = dept   ? e.dept_name   === dept   : true;
        return matchSearch && matchStatus && matchDept;
    });

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="px-6 py-8 text-center text-slate-500 dark:text-slate-400">No events found matching your criteria.</td></tr>';
        return;
    }

    filtered.slice(0, 10).forEach(function (e) {
        var statusBadge = '';
        if      (e.event_status === 'Completed')  statusBadge = '<span class="px-3 py-1 text-xs font-bold rounded-full bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400">Completed</span>';
        else if (e.event_status === 'In Progress') statusBadge = '<span class="px-3 py-1 text-xs font-bold rounded-full bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400">In Progress</span>';
        else                                       statusBadge = '<span class="px-3 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400">Upcoming</span>';

        var ratingHtml = e.avg_rating
            ? '<div class="flex items-center justify-center gap-1 font-bold text-slate-900 dark:text-white"><i class="fas fa-star text-yellow-400 text-xs"></i> ' + e.avg_rating + '</div>'
            : '<span class="text-slate-400 text-xs">No rating</span>';

        tbody.innerHTML += `
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
                <td class="px-6 py-4">
                    <p class="font-bold text-slate-900 dark:text-white truncate max-w-[200px]" title="${e.title}">${e.title}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate max-w-[200px]">${e.dept_name || 'General'}</p>
                </td>
                <td class="px-6 py-4">${statusBadge}</td>
                <td class="px-6 py-4">
                    <div class="flex items-center gap-2">
                        <div class="w-full bg-gray-100 dark:bg-slate-700 rounded-full h-1.5 max-w-[80px]">
                            <div class="bg-primary-500 h-1.5 rounded-full transition-all duration-500" style="width: ${e.attendance_pct}%"></div>
                        </div>
                        <span class="text-xs font-bold text-slate-700 dark:text-slate-300">${e.attendance_pct}%</span>
                    </div>
                </td>
                <td class="px-6 py-4 text-center">${ratingHtml}</td>
                <td class="px-6 py-4 text-right">
                    <button onclick="openEventModal(${e.event_id})" class="text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 font-semibold text-sm transition-colors">
                        Insights <i class="fas fa-arrow-right ml-1 text-xs"></i>
                    </button>
                </td>
            </tr>
        `;
    });
}

function resetFilters() {
    document.getElementById('searchEvent').value  = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterDept').value   = '';
    renderEventTable();
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('searchEvent').addEventListener('input', renderEventTable);
    document.getElementById('filterStatus').addEventListener('change', renderEventTable);
    document.getElementById('filterDept').addEventListener('change', renderEventTable);
});

// ═══════════════════════════════════════════════════════════════
// EVENT DETAILS MODAL (AJAX)
// ═══════════════════════════════════════════════════════════════

var modal        = null;
var modalContent = null;

document.addEventListener('DOMContentLoaded', function () {
    modal        = document.getElementById('eventModal');
    modalContent = document.getElementById('modalContent');
});

function openEventModal(eventId) {
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(function () {
        modal.classList.remove('opacity-0');
        modal.querySelector('div').classList.remove('scale-95');
    }, 10);

    modalContent.innerHTML = '<div class="flex justify-center py-8"><i class="fas fa-circle-notch fa-spin text-3xl text-primary-500"></i></div>';

    var formData = new FormData();
    formData.append('ajax', 'event_detail');
    formData.append('eventId', eventId);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.success) {
                modalContent.innerHTML = '<p class="text-red-500 font-medium text-center">Failed to load insights.</p>';
                return;
            }

            var html = '';

            if (data.categories.length > 0) {
                html += '<div class="grid grid-cols-2 gap-3 mb-6">';
                data.categories.forEach(function (c) {
                    html += `
                        <div class="bg-gray-50 dark:bg-slate-700 p-3 rounded-xl border border-gray-100 dark:border-slate-600">
                            <p class="text-xs text-slate-500 dark:text-slate-400 font-semibold mb-1">${c.category_name}</p>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-star text-yellow-400 text-sm"></i>
                                <span class="font-black text-lg text-slate-900 dark:text-white">${c.avg_rating}</span>
                                <span class="text-xs text-slate-400">(${c.votes} votes)</span>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
            }

            if (data.comments.length > 0) {
                html += '<h4 class="font-bold text-sm text-slate-900 dark:text-white uppercase tracking-wider mb-3">Recent Feedback</h4>';
                html += '<div class="space-y-3 max-h-48 overflow-y-auto pr-2 custom-scrollbar">';
                data.comments.forEach(function (c) {
                    html += `
                        <div class="bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-600 p-3 rounded-xl shadow-sm">
                            <p class="text-sm font-medium italic text-slate-600 dark:text-slate-300">"${c.comment}"</p>
                            <p class="text-xs text-slate-400 mt-2 font-semibold">- ${c.reviewer}
                                <span class="bg-gray-100 dark:bg-slate-700 px-2 py-0.5 rounded-full ml-1 text-slate-600 dark:text-slate-400">${c.category_name}</span>
                            </p>
                        </div>
                    `;
                });
                html += '</div>';
            } else {
                html += '<p class="text-sm text-slate-500 font-medium text-center py-4 bg-gray-50 dark:bg-slate-700 rounded-xl">No written feedback yet.</p>';
            }

            modalContent.innerHTML = html;
        })
        .catch(function () {
            modalContent.innerHTML = '<p class="text-red-500 font-medium text-center">Error loading data.</p>';
        });
}

function closeEventModal() {
    modal.classList.add('opacity-0');
    modal.querySelector('div').classList.add('scale-95');
    setTimeout(function () {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 300);
}

document.addEventListener('DOMContentLoaded', function () {
    var m = document.getElementById('eventModal');
    m.addEventListener('click', function (e) {
        if (e.target === m) closeEventModal();
    });
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var m = document.getElementById('eventModal');
        if (m && !m.classList.contains('hidden')) closeEventModal();
    }
});

// ═══════════════════════════════════════════════════════════════
// CSV DOWNLOADS
// ═══════════════════════════════════════════════════════════════

function downloadDeptCSV() {
    if (deptAttData.length === 0) {
        alert('No department attendance data to download.');
        return;
    }

    var csv = 'Department,Total Attendees\n';
    deptAttData.forEach(function (dept) {
        csv += '"' + dept.dept_name + '",' + dept.total + '\n';
    });

    _triggerCSVDownload(csv, 'attendance_by_department.csv');
}

function downloadEventsCSV() {
    var search   = document.getElementById('searchEvent').value.toLowerCase();
    var status   = document.getElementById('filterStatus').value;
    var dept     = document.getElementById('filterDept').value;

    var filtered = eventsData.filter(function (e) {
        var matchSearch = e.title.toLowerCase().includes(search);
        var matchStatus = status ? e.event_status === status : true;
        var matchDept   = dept   ? e.dept_name   === dept   : true;
        return matchSearch && matchStatus && matchDept;
    });

    if (filtered.length === 0) {
        alert('No events to download with current filters.');
        return;
    }

    var csv = 'Event Title,Department,Event Status,Attendance %,Avg Rating,Feedback Count\n';
    filtered.forEach(function (e) {
        var rating   = e.avg_rating !== null ? e.avg_rating : 'N/A';
        var deptName = e.dept_name || 'General';
        csv += '"' + e.title.replace(/"/g, '""') + '"';
        csv += ',"' + deptName + '"';
        csv += ',' + e.event_status;
        csv += ',' + e.attendance_pct + '%';
        csv += ',' + rating;
        csv += ',' + e.feedback_count;
        csv += '\n';
    });

    _triggerCSVDownload(csv, 'event_performance.csv');
}

function _triggerCSVDownload(csv, filename) {
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url  = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href     = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

// ═══════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    initCharts();
    renderEventTable();

    var dateEl = document.getElementById('current-date');
    if (dateEl) {
        dateEl.textContent = new Date().toLocaleDateString('en-US', {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
        });
    }
});