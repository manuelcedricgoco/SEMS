/**
 * SEMS Organizer — organizer_panel.js
 * Handles: Dark Mode, Sidebar, Notifications panel,
 *          Event search filter, Chart.js (Events vs Registrations),
 *          Announcement Modal
 *
 * Requires SEMS_PANEL_DATA to be defined inline before this script:
 *   <script>
 *     const SEMS_PANEL_DATA = {
 *         chartLabels: [...],
 *         chartEvents: [...],
 *         chartRegs:   [...],
 *     };
 *   </script>
 */

// ═══════════════════════════════════════════════════════════════
// DATA (from bridge)
// ═══════════════════════════════════════════════════════════════

var _d          = (typeof SEMS_PANEL_DATA !== 'undefined') ? SEMS_PANEL_DATA : {};
var chartLabels = _d.chartLabels || [];
var chartEvents = _d.chartEvents || [];
var chartRegs   = _d.chartRegs   || [];

// ═══════════════════════════════════════════════════════════════
// DARK MODE
// ═══════════════════════════════════════════════════════════════

var html      = document.documentElement;
var themeIcon = document.getElementById('themeIcon');

function applyTheme(dark) {
    if (dark) {
        html.classList.add('dark');
        if (themeIcon) themeIcon.className = 'fas fa-sun text-sm';
    } else {
        html.classList.remove('dark');
        if (themeIcon) themeIcon.className = 'fas fa-moon text-sm';
    }
}

function toggleTheme() {
    var isDark = !html.classList.contains('dark');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    applyTheme(isDark);
    updateChart();
}

// Init theme on load
(function () {
    var saved = localStorage.getItem('theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme:dark)').matches)) {
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
// NOTIFICATIONS PANEL
// ═══════════════════════════════════════════════════════════════

var notifPanel = document.getElementById('notifPanel');

function toggleNotif(e) {
    e.stopPropagation();
    notifPanel.classList.toggle('hidden');
}

document.addEventListener('click', function () {
    if (notifPanel) notifPanel.classList.add('hidden');
});

// ═══════════════════════════════════════════════════════════════
// EVENT SEARCH FILTER
// ═══════════════════════════════════════════════════════════════

function filterEvents(val) {
    var q = val.toLowerCase();
    document.querySelectorAll('.filterable-event').forEach(function (card) {
        card.style.display = card.dataset.title.includes(q) ? '' : 'none';
    });
}

// ═══════════════════════════════════════════════════════════════
// ANNOUNCEMENT MODAL
// ═══════════════════════════════════════════════════════════════

var annModal = document.getElementById('annModal');

function openAnnModal() {
    annModal.classList.remove('hidden');
    annModal.classList.add('flex');
    // Focus the title input after transition
    setTimeout(function () {
        var titleInput = annModal.querySelector('input[name="ann_title"]');
        if (titleInput) titleInput.focus();
    }, 50);
}

function closeAnnModal() {
    annModal.classList.add('hidden');
    annModal.classList.remove('flex');
}

// Close on backdrop click (only when clicking the dark overlay itself)
if (annModal) {
    annModal.addEventListener('click', function (e) {
        if (e.target === annModal) closeAnnModal();
    });
}

// Close on Escape key
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && annModal && !annModal.classList.contains('hidden')) {
        closeAnnModal();
    }
});

// ═══════════════════════════════════════════════════════════════
// CHART.JS
// ═══════════════════════════════════════════════════════════════

var isDarkNow = function () { return html.classList.contains('dark'); };

function chartColors() {
    return {
        grid:   isDarkNow() ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)',
        tick:   isDarkNow() ? '#6b7280' : '#9ca3af',
        legend: isDarkNow() ? '#9ca3af' : '#6b7280',
        tipBg:  isDarkNow() ? '#1f2937' : '#ffffff',
        tipFg:  isDarkNow() ? '#f3f4f6' : '#111827',
        ptBdr:  isDarkNow() ? '#111827' : '#ffffff',
    };
}

var ctx     = document.getElementById('perfChart').getContext('2d');
var myChart = null;

function buildChart() {
    var c = chartColors();
    if (myChart) myChart.destroy();

    myChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: 'Events',
                    data: chartEvents,
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34,197,94,.1)',
                    borderWidth: 2.5,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#22c55e',
                    pointBorderColor: c.ptBdr,
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                },
                {
                    label: 'Registrations',
                    data: chartRegs,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,.1)',
                    borderWidth: 2.5,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: c.ptBdr,
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top', align: 'end',
                    labels: {
                        usePointStyle: true, pointStyle: 'circle',
                        padding: 16, font: { size: 11, family: 'Poppins' },
                        color: c.legend,
                    }
                },
                tooltip: {
                    backgroundColor: c.tipBg,
                    titleColor: c.tipFg,
                    bodyColor: c.tick,
                    borderColor: isDarkNow() ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.08)',
                    borderWidth: 1,
                    padding: 10,
                    cornerRadius: 10,
                    usePointStyle: true,
                }
            },
            scales: {
                x: {
                    grid:  { display: false },
                    ticks: { color: c.tick, font: { size: 11 } }
                },
                y: {
                    grid:   { color: c.grid },
                    ticks:  { color: c.tick, font: { size: 11 }, padding: 8 },
                    border: { display: false }
                }
            }
        }
    });
}

function updateChart() { buildChart(); }

buildChart();