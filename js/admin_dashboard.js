/**
 * SEMS Admin Dashboard — dashboard.js
 * Handles: Dark Mode, Sidebar, Table Search, Chart.js initialization
 *
 * Requires SEMS_DATA to be defined inline in the page before this script loads:
 *   <script>
 *     const SEMS_DATA = {
 *       deptLabels:     [...],
 *       deptData:       [...],
 *       monthlyLabels:  [...],
 *       monthlyData:    [...],
 *       approvedEvents: N,
 *       pendingCount:   N,
 *       rejectedEvents: N,
 *     };
 *   </script>
 */

// ═══════════════════════════════════════════════════════════════
// DARK MODE
// ═══════════════════════════════════════════════════════════════

/**
 * Toggle between dark and light mode.
 * Saves preference to localStorage and refreshes chart colors.
 */
function toggleTheme() {
    const isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('sems-theme', isDark ? 'dark' : 'light');
    _applyThemeUI(isDark);
    updateChartTheme();
}

/**
 * Sync the icon + label to the current theme.
 * @param {boolean} isDark
 */
function _applyThemeUI(isDark) {
    const icon  = document.getElementById('theme-icon');
    const label = document.getElementById('theme-label');

    if (isDark) {
        icon.className    = 'fas fa-sun w-5 text-center text-amber-500';
        label.textContent = 'Light Mode';
    } else {
        icon.className    = 'fas fa-moon w-5 text-center';
        label.textContent = 'Dark Mode';
    }
}

// Restore saved theme on load (flash prevention is handled inline in <head>)
document.addEventListener('DOMContentLoaded', function () {
    const saved = localStorage.getItem('sems-theme') || 'light';
    _applyThemeUI(saved === 'dark');
});

// ═══════════════════════════════════════════════════════════════
// SIDEBAR (MOBILE)
// ═══════════════════════════════════════════════════════════════

function openSidebar() {
    document.getElementById('sidebar').classList.remove('-translate-x-full');
    const ov = document.getElementById('overlay');
    ov.classList.remove('pointer-events-none', 'opacity-0');
    ov.classList.add('pointer-events-auto', 'opacity-100');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    document.getElementById('sidebar').classList.add('-translate-x-full');
    const ov = document.getElementById('overlay');
    ov.classList.remove('pointer-events-auto', 'opacity-100');
    ov.classList.add('pointer-events-none', 'opacity-0');
    document.body.style.overflow = '';
}

// Auto-close sidebar when a nav link is tapped on mobile
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#sidebar a').forEach(function (el) {
        el.addEventListener('click', function () {
            if (window.innerWidth < 1024) closeSidebar();
        });
    });
});

// ═══════════════════════════════════════════════════════════════
// TABLE SEARCH / FILTER
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('tableSearch');
    if (!searchInput) return;

    searchInput.addEventListener('keyup', function () {
        const val        = this.value.toLowerCase();
        const rows       = document.querySelectorAll('#eventsTableBody tr');
        let visibleCount = 0;

        rows.forEach(function (row) {
            const isVisible = row.innerText.toLowerCase().includes(val);
            row.style.display = isVisible ? '' : 'none';
            if (isVisible) visibleCount++;
        });

        // Show/hide the empty-state block
        const noResults = document.getElementById('noResults');
        if (noResults) {
            noResults.classList.toggle('hidden', visibleCount > 0);
        }
    });
});

// ═══════════════════════════════════════════════════════════════
// CHART.JS
// ═══════════════════════════════════════════════════════════════

let eventsLineChart, approvalChart, deptChart;

/**
 * Returns color tokens for charts that match the active theme.
 * @returns {{ text: string, grid: string, textBold: string }}
 */
function getChartColors() {
    const isDark = document.documentElement.classList.contains('dark');
    return {
        text:     isDark ? '#94a3b8' : '#64748b',
        grid:     isDark ? 'rgba(148, 163, 184, 0.1)' : 'rgba(100, 116, 139, 0.1)',
        textBold: isDark ? '#e2e8f0' : '#1e293b',
    };
}

/**
 * Build shared scale options to keep chart configs DRY.
 * @param {object} colors
 * @returns {object}
 */
function buildScales(colors) {
    return {
        x: {
            ticks: { color: colors.text, font: { size: 11 } },
            grid:  { display: false },
        },
        y: {
            ticks: { color: colors.text, font: { size: 11 }, padding: 10 },
            grid:  { color: colors.grid, drawBorder: false },
            border: { display: false },
        },
    };
}

/**
 * Initialise all three charts using data from the SEMS_DATA global.
 */
function initCharts() {
    if (typeof SEMS_DATA === 'undefined') {
        console.error('SEMS_DATA is not defined. Make sure the inline data script is present.');
        return;
    }

    const colors = getChartColors();
    const scales = buildScales(colors);

    // ── BAR CHART – Department Attendance ──────────────────────
    const deptCtx = document.getElementById('deptChart');
    if (deptCtx) {
        deptChart = new Chart(deptCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: SEMS_DATA.deptLabels,
                datasets: [{
                    label: 'Attendance',
                    data: SEMS_DATA.deptData,
                    backgroundColor: '#3b82f6',
                    borderRadius: 6,
                    borderSkipped: false,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales,
            },
        });
    }

    // ── LINE CHART – Events Over Time ──────────────────────────
    const lineCtx = document.getElementById('eventsLineChart');
    if (lineCtx) {
        eventsLineChart = new Chart(lineCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: SEMS_DATA.monthlyLabels,
                datasets: [{
                    label: 'Events',
                    data: SEMS_DATA.monthlyData,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales,
            },
        });
    }

    // ── DOUGHNUT CHART – Approval Status ──────────────────────
    const approvalCtx = document.getElementById('approvalChart');
    if (approvalCtx) {
        approvalChart = new Chart(approvalCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Pending', 'Rejected'],
                datasets: [{
                    data: [
                        SEMS_DATA.approvedEvents,
                        SEMS_DATA.pendingCount,
                        SEMS_DATA.rejectedEvents,
                    ],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 0,
                    hoverOffset: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: { legend: { display: false } },
            },
        });
    }
}

/**
 * Re-apply theme-aware colors to all axis charts after a theme switch.
 */
function updateChartTheme() {
    const colors = getChartColors();

    [eventsLineChart, deptChart].forEach(function (chart) {
        if (!chart) return;

        if (chart.options.scales.x) {
            chart.options.scales.x.ticks.color = colors.text;
        }
        if (chart.options.scales.y) {
            chart.options.scales.y.ticks.color = colors.text;
            chart.options.scales.y.grid.color  = colors.grid;
        }
        chart.update();
    });
}

// Boot charts once the DOM is ready
document.addEventListener('DOMContentLoaded', initCharts);