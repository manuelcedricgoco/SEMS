/**
 * SEMS Organizer — organizer_event.js
 * Handles: Dark Mode, Sidebar, Standard Time Formatter,
 *          Auto-Dismiss Alert, Tab Counts, Event Filter/Search,
 *          Archive Tab, Announcements Tab (show/hide)
 */

// ═══════════════════════════════════════════════════════════════
// DARK MODE
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
// STANDARD TIME FORMATTER
// ═══════════════════════════════════════════════════════════════

function formatStandardTime(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;

    var months = [
        'January','February','March','April','May','June',
        'July','August','September','October','November','December'
    ];
    var month  = months[d.getMonth()];
    var day    = d.getDate();
    var year   = d.getFullYear();
    var hour   = d.getHours();
    var minute = String(d.getMinutes()).padStart(2, '0');
    var ampm   = hour >= 12 ? 'PM' : 'AM';
    hour = hour % 12;
    hour = hour ? hour : 12;
    return month + ' ' + day + ', ' + year + ' at ' + hour + ':' + minute + ' ' + ampm;
}

// ═══════════════════════════════════════════════════════════════
// AUTO-DISMISS ALERT
// ═══════════════════════════════════════════════════════════════

var dismissTimer     = null;
var dismissInterval  = null;
var dismissCancelled = false;

(function initAutoDismiss() {
    var alert = document.getElementById('success-alert');
    if (!alert) return;

    var secondsLeft = 3;

    dismissInterval = setInterval(function () {
        if (dismissCancelled) { clearInterval(dismissInterval); return; }
        secondsLeft--;
        var el = document.getElementById('dismiss-countdown');
        if (el) el.textContent = secondsLeft;
    }, 1000);

    dismissTimer = setTimeout(function () {
        if (!dismissCancelled) {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity    = '0';
            setTimeout(function () { alert.remove(); }, 400);
        }
    }, 2500);
})();

function cancelAutoDismiss() {
    dismissCancelled = true;
    clearTimeout(dismissTimer);
    clearInterval(dismissInterval);

    var alert = document.getElementById('success-alert');
    if (alert) {
        var bar = document.getElementById('dismiss-bar');
        if (bar) bar.style.width = '0%';
        var span = alert.querySelector('span.whitespace-nowrap');
        if (span) span.innerHTML = '<i class="fas fa-check mr-1"></i>Auto-dismiss cancelled';
    }
}

// ═══════════════════════════════════════════════════════════════
// TAB COUNTS
// ═══════════════════════════════════════════════════════════════

var currentTab = 'all';

function initCounts() {
    var cards  = document.querySelectorAll('.filterable-event');
    var counts = { all: cards.length, pending: 0, approved: 0, ended: 0 };

    cards.forEach(function (c) {
        var s = c.dataset.status;
        if (counts[s] !== undefined) counts[s]++;
    });

    // Update event-status tab counts; archive & announcements counts are set by PHP
    Object.keys(counts).forEach(function (t) {
        var el = document.getElementById('count-' + t);
        if (el) el.textContent = counts[t];
    });
}

// ═══════════════════════════════════════════════════════════════
// EVENT FILTER + SEARCH
// ═══════════════════════════════════════════════════════════════

function filterEvents(query) {
    // When archive or announcements tab is active, nothing to filter in the event grid
    if (currentTab === 'archive' || currentTab === 'announcements') return;

    query = (query || '').toLowerCase().trim();
    var cards = document.querySelectorAll('.filterable-event');
    var shown = 0;

    cards.forEach(function (c) {
        var matchQ   = !query || c.dataset.title.includes(query) || c.dataset.departments.includes(query);
        var matchTab = currentTab === 'all' || c.dataset.status === currentTab;
        var vis      = matchQ && matchTab;
        c.style.display = vis ? '' : 'none';
        if (vis) shown++;
    });

    ['pending', 'approved', 'ended'].forEach(function (t) {
        var el = document.getElementById('empty-' + t);
        if (!el) return;
        if (currentTab === t && shown === 0) {
            el.classList.remove('hidden');
        } else {
            el.classList.add('hidden');
        }
    });
}

// ═══════════════════════════════════════════════════════════════
// TAB SWITCHER  (All / Pending / Approved / Archive / Ended / Announcements)
// ═══════════════════════════════════════════════════════════════

function setTab(tab) {
    currentTab = tab;

    var palette = {
        all:           { txt:'text-blue-600 dark:text-blue-400',    bg:'bg-blue-100 dark:bg-blue-900/30',    border:'border-blue-300 dark:border-blue-700'    },
        pending:       { txt:'text-amber-600 dark:text-amber-400',  bg:'bg-amber-100 dark:bg-amber-900/30',  border:'border-amber-300 dark:border-amber-700'  },
        approved:      { txt:'text-brand-600 dark:text-brand-400',  bg:'bg-brand-100 dark:bg-brand-900/30',  border:'border-brand-300 dark:border-brand-700'  },
        archive:       { txt:'text-slate-700 dark:text-slate-200',  bg:'bg-slate-200 dark:bg-slate-600',     border:'border-slate-400 dark:border-slate-500'  },
        ended:         { txt:'text-rose-600 dark:text-rose-400',    bg:'bg-rose-100 dark:bg-rose-900/30',    border:'border-rose-300 dark:border-rose-700'    },
        announcements: { txt:'text-orange-600 dark:text-orange-400',bg:'bg-orange-100 dark:bg-orange-900/30',border:'border-orange-300 dark:border-orange-700'},
    };

    ['all', 'pending', 'approved', 'archive', 'ended', 'announcements'].forEach(function (t) {
        var btn = document.getElementById('tab-' + t);
        if (!btn) return;
        btn.className = 'tab-btn flex items-center gap-2 px-3 py-2 rounded-xl text-sm font-semibold transition-all '
            + (t === tab
                ? palette[t].txt + ' ' + palette[t].bg + ' border ' + palette[t].border
                : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700');
    });

    // ── Grab all panel references ──────────────────────────────
    var archiveContent       = document.getElementById('archive-tab-content');
    var announcementsContent = document.getElementById('announcements-tab-content');
    var eventGrid            = document.getElementById('eventGrid');

    // ── Helpers ────────────────────────────────────────────────
    function hideEventPanels() {
        if (eventGrid) eventGrid.style.display = 'none';
        ['pending', 'approved', 'ended'].forEach(function (t) {
            var el = document.getElementById('empty-' + t);
            if (el) el.classList.add('hidden');
        });
    }

    function showEventGrid() {
        if (eventGrid) eventGrid.style.display = '';
        var searchInput = document.getElementById('searchInput');
        filterEvents(searchInput ? searchInput.value : '');
    }

    // ── Ensure all special panels are hidden first ─────────────
    if (archiveContent)       archiveContent.classList.add('hidden');
    if (announcementsContent) announcementsContent.classList.add('hidden');

    // ── Now activate the selected tab ──────────────────────────
    if (tab === 'archive') {
        if (archiveContent) archiveContent.classList.remove('hidden');
        hideEventPanels();

    } else if (tab === 'announcements') {
        if (announcementsContent) announcementsContent.classList.remove('hidden');
        hideEventPanels();

    } else {
        // All / Pending / Approved / Ended — show event grid
        showEventGrid();
    }
}

// ═══════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    initCounts();

    // Auto-switch to the right tab when arriving from a redirect
    var p = new URLSearchParams(window.location.search);

    if (p.get('restored_event') || p.get('perm_deleted_event')) {
        setTab('archive');
    } else if (p.get('restored_ann') || p.get('perm_deleted_ann') || p.get('ann_success') || p.get('ann_deleted')) {
        setTab('announcements');
    } else {
        setTab('all');
    }

    // Live search wiring
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function () {
            filterEvents(this.value);
        });
    }
});