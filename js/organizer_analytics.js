/**
 * SEMS Organizer — organizer_analytics.js
 * Handles: Theme, Sidebar, Charts (Registration Trends,
 *          Event Performance, Department Doughnut),
 *          Chart toolbars, Download PNG,
 *          Attendance accordion / filter / CSV export,
 *          Feedback list (search / filter / sort / paginate)
 *
 * Requires SEMS_ANALYTICS_DATA to be defined inline before this script:
 *   <script>
 *     const SEMS_ANALYTICS_DATA = {
 *         months:        [...],
 *         regCounts:     [...],
 *         eventTitles:   [...],
 *         eventRegs:     [...],
 *         eventAttend:   [...],
 *         deptNames:     [...],
 *         deptCounts:    [...],
 *         deptColors:    [...],
 *         showDeptChart: true|false,
 *         allowedDepts:  null | [...],   // null = all; array = restricted list
 *     };
 *   <\/script>
 */

// ═══════════════════════════════════════════════════════════════
// DATA  (injected by PHP bridge before this file loads)
// ═══════════════════════════════════════════════════════════════

var _d           = (typeof SEMS_ANALYTICS_DATA !== 'undefined') ? SEMS_ANALYTICS_DATA : {};
var months       = _d.months       || [];
var regCounts    = _d.regCounts    || [];
var eventTitles  = _d.eventTitles  || [];
var eventRegs    = _d.eventRegs    || [];
var eventAttend  = _d.eventAttend  || [];
var deptNames    = _d.deptNames    || [];
var deptCounts   = _d.deptCounts   || [];
var deptColors   = _d.deptColors   || [];
var showDeptChart = (_d.showDeptChart === true);
// null = all departments allowed; string[] = restricted list
var allowedDepts = _d.allowedDepts || null;

// ═══════════════════════════════════════════════════════════════
// THEME
// ═══════════════════════════════════════════════════════════════

var html      = document.documentElement;
var themeIcon = document.getElementById('themeIcon');

function applyTheme(dark) {
    dark ? html.classList.add('dark') : html.classList.remove('dark');
    if (themeIcon) themeIcon.className = dark ? 'fas fa-sun text-sm' : 'fas fa-moon text-sm';
}

function toggleTheme() {
    var d = !html.classList.contains('dark');
    localStorage.setItem('theme', d ? 'dark' : 'light');
    applyTheme(d);
    setTimeout(initCharts, 50);
}

(function () {
    var s = localStorage.getItem('theme');
    if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme:dark)').matches)) {
        applyTheme(true);
    }
})();

// ═══════════════════════════════════════════════════════════════
// SIDEBAR
// ═══════════════════════════════════════════════════════════════

var sidebar   = document.getElementById('sidebar');
var sbOverlay = document.getElementById('sb-overlay');

function openSidebar() {
    sidebar.classList.remove('-translate-x-full');
    sbOverlay.classList.add('show');
}

function closeSidebar() {
    sidebar.classList.add('-translate-x-full');
    sbOverlay.classList.remove('show');
}

// ═══════════════════════════════════════════════════════════════
// CHARTS
// ═══════════════════════════════════════════════════════════════

var registrationChart = null;
var performanceChart  = null;
var departmentChart   = null;

function chartTheme() {
    var dark = html.classList.contains('dark');
    return {
        text:  dark ? '#9ca3af' : '#6b7280',
        grid:  dark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)',
        tipBg: dark ? '#1f2937' : '#ffffff',
        tipFg: dark ? '#f3f4f6' : '#111827',
    };
}

function initCharts() {
    var c = chartTheme();

    if (registrationChart) { registrationChart.destroy(); registrationChart = null; }
    if (performanceChart)  { performanceChart.destroy();  performanceChart  = null; }
    if (departmentChart)   { departmentChart.destroy();   departmentChart   = null; }

    // ── Registration Trends ──────────────────────────────────
    var regCanvas = document.getElementById('registrationChart');
    if (regCanvas) {
        registrationChart = new Chart(regCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Registrations',
                    data: regCounts,
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34,197,94,.12)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#22c55e',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        labels: { usePointStyle: true, padding: 16, font: { size: 11 }, color: c.text }
                    },
                    tooltip: { backgroundColor: c.tipBg, titleColor: c.tipFg, bodyColor: c.text, cornerRadius: 10, padding: 10 }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: c.grid }, ticks: { color: c.text }, border: { display: false } },
                    x: { grid: { display: false }, ticks: { color: c.text } }
                }
            }
        });
    }

    // ── Event Performance ────────────────────────────────────
    var perfCanvas = document.getElementById('performanceChart');
    if (perfCanvas) {
        performanceChart = new Chart(perfCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: eventTitles,
                datasets: [
                    {
                        label: 'Registrations',
                        data: eventRegs,
                        backgroundColor: '#22c55e',
                        borderRadius: 5,
                        barPercentage: 0.55,
                        categoryPercentage: 0.75,
                    },
                    {
                        label: 'Attendance',
                        data: eventAttend,
                        backgroundColor: '#3b82f6',
                        borderRadius: 5,
                        barPercentage: 0.55,
                        categoryPercentage: 0.75,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        labels: { usePointStyle: true, padding: 16, font: { size: 11 }, color: c.text }
                    },
                    tooltip: { backgroundColor: c.tipBg, titleColor: c.tipFg, bodyColor: c.text, cornerRadius: 10, padding: 10 }
                },
                scales: {
                    y: {
                        grid: { display: false },
                        ticks: {
                            color: c.text,
                            font: { size: 11 },
                            callback: function (v) {
                                var l = performanceChart && performanceChart.data.labels[v];
                                return l && l.length > 18 ? l.slice(0, 18) + '…' : l;
                            }
                        }
                    },
                    x: {
                        beginAtZero: true,
                        grid: { color: c.grid },
                        ticks: { color: c.text, precision: 0 },
                        border: { display: false }
                    }
                }
            }
        });
    }

    // ── Department Doughnut ──────────────────────────────────
    var deptCanvas = document.getElementById('departmentChart');
    if (deptCanvas && showDeptChart) {
        departmentChart = new Chart(deptCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: deptNames,
                datasets: [{
                    data: deptCounts,
                    backgroundColor: deptColors,
                    borderWidth: 0,
                    hoverOffset: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        display: true, position: 'right',
                        labels: { color: c.text, font: { size: 11 }, usePointStyle: true }
                    },
                    tooltip: { backgroundColor: c.tipBg, titleColor: c.tipFg, bodyColor: c.text, cornerRadius: 10, padding: 10 }
                }
            }
        });
    }

    buildRegToolbar();
    buildPerfToolbar();
}

// ═══════════════════════════════════════════════════════════════
// CHART TOOLBARS
// ═══════════════════════════════════════════════════════════════

function buildRegToolbar() {
    var tb    = document.getElementById('regToolbar');
    var dlBtn = document.getElementById('dlRegBtn');
    if (!tb) return;
    tb.innerHTML = '';

    if (dlBtn) dlBtn.onclick = function () { dlChart(registrationChart, 'registration-trends'); };

    var curRange = (document.getElementById('dateFilter') || {}).value || '30days';
    var ranges   = [
        { l: '7D', v: '7days' }, { l: '30D', v: '30days' },
        { l: '3M', v: '90days' }, { l: 'Year', v: 'year' }, { l: 'All', v: 'all' }
    ];

    ranges.forEach(function (r) {
        var b = document.createElement('button');
        b.className   = 'ct-btn' + (r.v === curRange ? ' active' : '');
        b.textContent = r.l;
        b.onclick = function () {
            var df = document.getElementById('dateFilter');
            if (df) { df.value = r.v; document.getElementById('analyticsFilterForm').submit(); }
        };
        tb.appendChild(b);
    });

    var sep = document.createElement('span');
    sep.style.cssText = 'width:1px;height:18px;background:rgba(0,0,0,.1);margin:0 2px;';
    tb.appendChild(sep);

    var lineBtn = document.createElement('button');
    lineBtn.className = 'ct-btn active';
    lineBtn.innerHTML = '<i class="fas fa-chart-line"></i>';

    var barBtn = document.createElement('button');
    barBtn.className  = 'ct-btn';
    barBtn.innerHTML  = '<i class="fas fa-chart-bar"></i>';

    lineBtn.onclick = function () {
        setRegType('line');
        lineBtn.className = 'ct-btn active';
        barBtn.className  = 'ct-btn';
    };
    barBtn.onclick = function () {
        setRegType('bar');
        barBtn.className  = 'ct-btn active';
        lineBtn.className = 'ct-btn';
    };

    tb.appendChild(lineBtn);
    tb.appendChild(barBtn);
}

function setRegType(t) {
    if (!registrationChart) return;
    registrationChart.config.type = t;
    registrationChart.data.datasets[0].fill            = (t === 'line');
    registrationChart.data.datasets[0].backgroundColor = t === 'line'
        ? 'rgba(34,197,94,.12)'
        : 'rgba(34,197,94,.75)';
    registrationChart.update();
}

function buildPerfToolbar() {
    var tb    = document.getElementById('perfToolbar');
    var dlBtn = document.getElementById('dlPerfBtn');
    if (!tb) return;
    tb.innerHTML = '';

    if (dlBtn) dlBtn.onclick = function () { dlChart(performanceChart, 'event-performance'); };

    var rawL = ((performanceChart && performanceChart.data.labels)                                          || []).slice();
    var rawR = ((performanceChart && performanceChart.data.datasets[0] && performanceChart.data.datasets[0].data) || []).slice();
    var rawA = ((performanceChart && performanceChart.data.datasets[1] && performanceChart.data.datasets[1].data) || []).slice();

    var regSort = document.createElement('button');
    regSort.className = 'ct-btn active';
    regSort.innerHTML = '<i class="fas fa-sort-amount-down"></i> By Reg';

    var attSort = document.createElement('button');
    attSort.className = 'ct-btn';
    attSort.innerHTML = '<i class="fas fa-sort-amount-down"></i> By Att';

    function sortPerf(by) {
        regSort.className = 'ct-btn' + (by === 'reg' ? ' active' : '');
        attSort.className = 'ct-btn' + (by === 'att' ? ' active' : '');
        var idx = rawL.map(function (_, i) { return i; })
            .sort(function (a, b) { return by === 'reg' ? rawR[b] - rawR[a] : rawA[b] - rawA[a]; });
        performanceChart.data.labels           = idx.map(function (i) { return rawL[i]; });
        performanceChart.data.datasets[0].data = idx.map(function (i) { return rawR[i]; });
        performanceChart.data.datasets[1].data = idx.map(function (i) { return rawA[i]; });
        performanceChart.update();
    }

    regSort.onclick = function () { sortPerf('reg'); };
    attSort.onclick = function () { sortPerf('att'); };

    tb.appendChild(regSort);
    tb.appendChild(attSort);
}

function dlChart(chart, name) {
    if (!chart) return;
    var a = document.createElement('a');
    a.download = name + '-' + new Date().toISOString().slice(0, 10) + '.png';
    a.href = chart.canvas.toDataURL('image/png');
    a.click();
}

// ═══════════════════════════════════════════════════════════════
// ATTENDANCE — accordion toggles
// ═══════════════════════════════════════════════════════════════

function toggleDeptSection(hdr) {
    var ds   = hdr.closest('.attendance-dept-section');
    var c    = ds.querySelector('.attendance-dept-content');
    var open = ds.classList.contains('open');
    if (open) {
        c.classList.add('hidden'); c.classList.remove('!block'); ds.classList.remove('open');
    } else {
        c.classList.remove('hidden'); c.classList.add('!block'); ds.classList.add('open');
    }
}

function toggleSection(hdr) {
    var sec  = hdr.closest('.attendance-section');
    var c    = sec.querySelector('.attendance-section-content');
    var open = sec.classList.contains('open');
    if (open) {
        c.classList.add('hidden'); c.classList.remove('!block'); sec.classList.remove('open');
    } else {
        c.classList.remove('hidden'); c.classList.add('!block'); sec.classList.add('open');
    }
}

function toggleAttendanceFilters() {
    var bar = document.getElementById('attendanceFilters');
    if (bar.classList.contains('hidden')) {
        bar.classList.remove('hidden');
    } else {
        bar.classList.add('hidden');
        document.getElementById('attDeptFilter').value   = 'all';
        document.getElementById('attYearFilter').value   = 'all';
        document.getElementById('attStatusFilter').value = 'all';
        document.getElementById('attSearchFilter').value = '';
        filterAttendanceTable();
    }
}

// ═══════════════════════════════════════════════════════════════
// ATTENDANCE — filter
// ═══════════════════════════════════════════════════════════════

function filterAttendanceTable() {
    var dF = (document.getElementById('attDeptFilter')   || { value: 'all' }).value;
    var yF = (document.getElementById('attYearFilter')   || { value: 'all' }).value;
    var sF = (document.getElementById('attStatusFilter') || { value: 'all' }).value;
    var q  = ((document.getElementById('attSearchFilter') || { value: '' }).value || '').toLowerCase().trim();

    /* Step 1 — show / hide individual rows */
    document.querySelectorAll('.attendance-row').forEach(function (row) {
        var show = true;
        // Enforce server-side dept restriction client-side as well
        if (allowedDepts !== null && allowedDepts.indexOf(row.dataset.department) === -1) show = false;
        if (show && dF !== 'all' && row.dataset.department !== dF) show = false;
        if (show && yF !== 'all' && row.dataset.year       !== yF) show = false;
        if (show && sF !== 'all' && row.dataset.status     !== sF) show = false;
        if (show && q) {
            var name = (row.dataset.name          || '');
            var num  = (row.dataset.studentNumber || '').toLowerCase();
            if (!name.includes(q) && !num.includes(q)) show = false;
        }
        row.style.display = show ? '' : 'none';
    });

    /* Step 2 — section containers */
    document.querySelectorAll('.attendance-section').forEach(function (sec) {
        var vis = Array.from(sec.querySelectorAll('.attendance-row'))
            .filter(function (r) { return r.style.display !== 'none'; });
        if (vis.length === 0) {
            sec.style.display = 'none';
        } else {
            sec.style.display = '';
            var c = sec.querySelector('.attendance-section-content');
            if (c) { c.classList.remove('hidden'); c.classList.add('!block'); sec.classList.add('open'); }
        }
    });

    /* Step 3 — year-group divs only (NOT <tr> rows) */
    document.querySelectorAll('.att-year-group').forEach(function (yg) {
        var vis = Array.from(yg.querySelectorAll('.attendance-row'))
            .filter(function (r) { return r.style.display !== 'none'; });
        yg.style.display = vis.length === 0 ? 'none' : '';
    });

    /* Step 4 — dept sections */
    document.querySelectorAll('.attendance-dept-section').forEach(function (ds) {
        var vis = Array.from(ds.querySelectorAll('.attendance-row'))
            .filter(function (r) { return r.style.display !== 'none'; });
        if (vis.length === 0) {
            ds.style.display = 'none';
        } else {
            ds.style.display = '';
            var c = ds.querySelector('.attendance-dept-content');
            if (c) { c.classList.remove('hidden'); c.classList.add('!block'); ds.classList.add('open'); }
        }
    });
}

// ═══════════════════════════════════════════════════════════════
// ATTENDANCE — CSV export
// ═══════════════════════════════════════════════════════════════

function exportAttendanceToCSV() {
    var allRows = document.querySelectorAll('.attendance-row');
    if (allRows.length === 0) { alert('No attendance data available.'); return; }

    var bar         = document.getElementById('attendanceFilters');
    var filtersOpen = bar && !bar.classList.contains('hidden');

    var dF   = filtersOpen ? (document.getElementById('attDeptFilter')   || { value: 'all' }).value : 'all';
    var yF   = filtersOpen ? (document.getElementById('attYearFilter')   || { value: 'all' }).value : 'all';
    var sF   = filtersOpen ? (document.getElementById('attStatusFilter') || { value: 'all' }).value : 'all';
    var rawQ = filtersOpen ? ((document.getElementById('attSearchFilter') || { value: '' }).value || '') : '';
    var q    = rawQ.toLowerCase().trim();

    var rows = Array.from(allRows).filter(function (row) {
        if (allowedDepts !== null && allowedDepts.indexOf(row.dataset.department) === -1) return false;
        if (dF !== 'all' && row.dataset.department !== dF) return false;
        if (yF !== 'all' && row.dataset.year       !== yF) return false;
        if (sF !== 'all' && row.dataset.status     !== sF) return false;
        if (q) {
            var n   = (row.dataset.name || '');
            var num = (row.dataset.studentNumber || '').toLowerCase();
            if (!n.includes(q) && !num.includes(q)) return false;
        }
        return true;
    });

    if (rows.length === 0) { alert('No data to export. Please adjust your filters.'); return; }

    var headers = ['Department', 'Year Level', 'Section', 'Student Name', 'Student Number', 'Status', 'Login Time', 'Logout Time', 'Event'];
    var csv     = [headers];

    rows.forEach(function (row) {
        var cells = row.querySelectorAll('td');
        var g = function (i) { return cells[i] ? cells[i].textContent.trim().replace(/\u2014|—/g, '') : ''; };
        csv.push([
            row.dataset.department || '', row.dataset.year || '', row.dataset.section || '',
            g(0), g(1), g(2), g(3), g(4), g(5)
        ]);
    });

    var content = csv.map(function (r) {
        return r.map(function (cell) { return '"' + String(cell).replace(/"/g, '""') + '"'; }).join(',');
    }).join('\n');

    var blob = new Blob(['\uFEFF' + content], { type: 'text/csv;charset=utf-8;' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'attendance_report_' + new Date().toISOString().slice(0, 19).replace(/:/g, '-') + '.csv';
    a.style.visibility = 'hidden';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ═══════════════════════════════════════════════════════════════
// FEEDBACK — search / filter / sort / paginate
// ═══════════════════════════════════════════════════════════════

function loadFeedback() {
    if (typeof applyFbFilters === 'function') applyFbFilters();
}

// ═══════════════════════════════════════════════════════════════
// DOM READY
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {

    // Init charts
    initCharts();

    // Auto-expand first department accordion
    var firstDept = document.querySelector('.attendance-dept-section');
    if (firstDept) {
        var hdr = firstDept.querySelector('.flex.items-center.justify-between.cursor-pointer');
        if (hdr) toggleDeptSection(hdr);
    }

    // ── Feedback setup ────────────────────────────────────────
    var feedbackSection = document.getElementById('feedbackList');
    if (!feedbackSection || feedbackSection.children.length === 0) return;

    var allCards = Array.from(feedbackSection.children);

    // Tag each card with data attributes for filtering / sorting
    allCards.forEach(function (card) {
        var filled = card.querySelectorAll('.fa-star:not(.fa-star-half-alt)').length;
        var half   = card.querySelectorAll('.fa-star-half-alt').length;
        card.dataset.rating  = String(filled + (half ? 0.5 : 0));
        var spans = card.querySelectorAll('.text-xs.text-gray-400 span');
        card.dataset.evTitle = spans[0] ? spans[0].textContent.trim().toLowerCase() : '';
    });

    // Build toolbar DOM
    var tb = document.createElement('div');
    tb.id  = 'fbToolbar';

    var fbSearch = document.createElement('input');
    fbSearch.id          = 'fbSearch';
    fbSearch.type        = 'text';
    fbSearch.placeholder = 'Search by name or comment…';

    var fbRating = document.createElement('select');
    fbRating.id  = 'fbRatingFilter';
    fbRating.innerHTML = [
        '<option value="all">All Ratings</option>',
        '<option value="5">5★ only</option>',
        '<option value="4">4+★</option>',
        '<option value="3">3+★</option>',
        '<option value="2">2+★</option>',
    ].join('');

    var fbSort = document.createElement('select');
    fbSort.id  = 'fbSortSelect';
    fbSort.innerHTML = [
        '<option value="newest">Newest First</option>',
        '<option value="oldest">Oldest First</option>',
        '<option value="highest">Highest Rating</option>',
        '<option value="lowest">Lowest Rating</option>',
    ].join('');

    tb.appendChild(fbSearch);
    tb.appendChild(fbRating);
    tb.appendChild(fbSort);

    var noRes = document.getElementById('fbNoResults');
    if (noRes) {
        noRes.innerHTML = '<i class="fas fa-search" style="font-size:20px;display:block;margin-bottom:8px;color:#9ca3af"></i>No feedback matches your filters.';
    }

    feedbackSection.parentElement.insertBefore(tb, feedbackSection);

    // Pagination
    var PER     = 4;
    var page    = 0;
    var visible = allCards.slice();

    var pag     = document.createElement('div');
    pag.className = 'fb-pagination';

    var prevBtn = document.createElement('button');
    prevBtn.className = 'fb-pg-btn';
    prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Prev';

    var pgInfo = document.createElement('span');
    pgInfo.className = 'fb-pg-info';

    var nextBtn = document.createElement('button');
    nextBtn.className = 'fb-pg-btn';
    nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';

    pag.appendChild(prevBtn);
    pag.appendChild(pgInfo);
    pag.appendChild(nextBtn);
    feedbackSection.parentElement.appendChild(pag);

    function renderPage() {
        var total = Math.max(1, Math.ceil(visible.length / PER));
        if (page >= total) page = total - 1;
        if (page < 0)      page = 0;
        allCards.forEach(function (c) { c.style.display = 'none'; });
        visible.forEach(function (c, i) {
            c.style.display = (i >= page * PER && i < (page + 1) * PER) ? '' : 'none';
        });
        prevBtn.disabled = page === 0;
        nextBtn.disabled = page >= total - 1;
        pgInfo.textContent = visible.length > 0
            ? 'Page ' + (page + 1) + ' of ' + total + '  (' + visible.length + ' review' + (visible.length !== 1 ? 's' : '') + ')'
            : '';
        if (noRes) noRes.style.display = visible.length === 0 ? 'block' : 'none';
        pag.style.display = visible.length === 0 ? 'none' : 'flex';
    }

    prevBtn.onclick = function () { page--; renderPage(); };
    nextBtn.onclick = function () { page++; renderPage(); };

    window.applyFbFilters = function () {
        page = 0;
        var q     = fbSearch.value.toLowerCase().trim();
        var minR  = parseFloat(fbRating.value) || 0;
        var evSel = document.getElementById('feedbackEventFilter').value;
        var evT   = evSel === 'all' ? '' : evSel;
        var sort  = fbSort.value;

        var filtered = allCards.filter(function (card) {
            var text = (card.textContent || '').toLowerCase();
            var rat  = parseFloat(card.dataset.rating) || 0;
            var ev   = card.dataset.evTitle || '';
            if (q && !text.includes(q)) return false;
            if (fbRating.value !== 'all') {
                if (fbRating.value === '5' ? rat < 5 : rat < minR) return false;
            }
            if (evT && !ev.includes(evT.toLowerCase())) return false;
            return true;
        });

        filtered.sort(function (a, b) {
            if (sort === 'highest') return parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating);
            if (sort === 'lowest')  return parseFloat(a.dataset.rating) - parseFloat(b.dataset.rating);
            var ai = allCards.indexOf(a), bi = allCards.indexOf(b);
            return sort === 'oldest' ? bi - ai : ai - bi;
        });

        visible = filtered;
        filtered.forEach(function (c) { feedbackSection.appendChild(c); });
        updateRatingSummary(filtered);
        renderPage();
    };

    function updateRatingSummary(cards) {
        var total = cards.length;
        var dist  = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
        var sum   = 0;

        cards.forEach(function (c) {
            var r  = parseFloat(c.dataset.rating) || 0;
            sum   += r;
            var rr = Math.round(r);
            if (rr >= 1 && rr <= 5) dist[rr]++;
        });

        var avg = total > 0 ? Math.round((sum / total) * 10) / 10 : 0;

        var orEl = document.getElementById('overallRating');
        if (orEl) orEl.textContent = avg.toFixed(1);

        var osEl = document.getElementById('overallStars');
        if (osEl) {
            var starHtml = '';
            for (var i = 1; i <= 5; i++) {
                if (i <= Math.floor(avg))  starHtml += '<i class="fas fa-star text-sm"></i>';
                else if (i - 0.5 <= avg)   starHtml += '<i class="fas fa-star-half-alt text-sm"></i>';
                else                       starHtml += '<i class="far fa-star text-sm text-gray-300 dark:text-gray-600"></i>';
            }
            osEl.innerHTML = starHtml;
        }

        var rcEl = document.getElementById('reviewCount');
        if (rcEl) rcEl.textContent = 'Based on ' + total + ' review' + (total !== 1 ? 's' : '');

        var bars = document.querySelectorAll('#ratingBars > div');
        bars.forEach(function (row, idx) {
            var star = 5 - idx;
            var pct  = total > 0 ? Math.round((dist[star] / total) * 100) : 0;
            var bar  = row.querySelector('.h-full.rounded-full');
            var span = row.querySelectorAll('span')[1];
            if (bar)  bar.style.width  = pct + '%';
            if (span) span.textContent = pct + '%';
        });
    }

    fbSearch.addEventListener('input',  window.applyFbFilters);
    fbRating.addEventListener('change', window.applyFbFilters);
    fbSort.addEventListener('change',   window.applyFbFilters);

    var fbEventFilter = document.getElementById('feedbackEventFilter');
    if (fbEventFilter) fbEventFilter.addEventListener('change', window.applyFbFilters);

    // Wrap filter selects with icon groups
    function wrapIcon(selId, iconCls) {
        var sel = document.getElementById(selId);
        if (!sel || sel.parentElement.classList.contains('fb-filter-group')) return;
        var group = document.createElement('div');
        group.className = 'fb-filter-group';
        var icon = document.createElement('i');
        icon.className = 'fas ' + iconCls + ' fb-filter-icon';
        sel.parentNode.insertBefore(group, sel);
        group.appendChild(icon);
        group.appendChild(sel);
    }

    wrapIcon('fbRatingFilter', 'fa-star');
    wrapIcon('fbSortSelect',   'fa-sort-amount-down');

    window.applyFbFilters();
});