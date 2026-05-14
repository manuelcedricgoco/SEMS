/**
 * SEMS Admin — admin_event_manage.js
 * Handles: Dark Mode, Sidebar, Filter Tabs, Live Search,
 *          Table Render, View Modal, Archive/Restore/Delete Modals,
 *          Manage Venues & Event Types Modal, PDF Export, Toast
 *
 * Requires SEMS_EVENT_DATA to be defined inline before this script loads.
 */

// ═══════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════

let eventData      = (typeof SEMS_EVENT_DATA !== 'undefined') ? SEMS_EVENT_DATA.events         : [];
let archivedData   = (typeof SEMS_EVENT_DATA !== 'undefined') ? SEMS_EVENT_DATA.archivedEvents : [];
let venueData      = (typeof SEMS_EVENT_DATA !== 'undefined') ? SEMS_EVENT_DATA.venues         : [];
let eventTypeData  = (typeof SEMS_EVENT_DATA !== 'undefined') ? SEMS_EVENT_DATA.eventTypes     : [];
let orgData        = (typeof SEMS_EVENT_DATA !== 'undefined') ? SEMS_EVENT_DATA.orgs           : [];
let clubData       = (typeof SEMS_EVENT_DATA !== 'undefined') ? SEMS_EVENT_DATA.clubs          : [];

let currentFilter      = 'all';
let searchQuery        = '';

// Pending IDs for event modals
let _pendingArchiveId  = null;
let _pendingRestoreId  = null;
let _pendingPermDelId  = null;

// Manage modal state
let _manageTab           = 'venues';
let _editingVenueId      = null;
let _editingTypeId       = null;
let _pendingMgmtDeleteId = null;
let _pendingMgmtDeleteKind = null; // 'venue' | 'type'

// ═══════════════════════════════════════════════════════════════
// STATUS STYLE MAPS
// ═══════════════════════════════════════════════════════════════

const statusStyles = {
    approved: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400',
    pending:  'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400',
    rejected: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-400',
};

const statusBadgeStyles = {
    approved: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/20',
    pending:  'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400 border border-amber-200 dark:border-amber-500/20',
    rejected: 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400 border border-rose-200 dark:border-rose-500/20',
};

const statusDot = {
    approved: 'bg-emerald-500',
    pending:  'bg-amber-500',
    rejected: 'bg-rose-500',
};

// ═══════════════════════════════════════════════════════════════
// SPACING & ANIMATION CSS INJECTION
// ═══════════════════════════════════════════════════════════════

(function injectSpacingCSS() {
    const style = document.createElement('style');
    style.id    = 'sems-spacing-fix';
    style.textContent = `
        #active-section {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        #archived-section {
            flex-direction: column;
            gap: 1.25rem;
        }
        #active-section.hidden,
        #archived-section.hidden {
            display: none !important;
        }
        .event-row {
            transition: opacity .25s ease, max-height .3s ease, transform .25s ease;
            max-height: 200px;
            overflow: hidden;
        }
        .event-row.removing {
            opacity: 0;
            max-height: 0;
            transform: translateX(20px);
            pointer-events: none;
        }
        .archived-live-badge {
            margin-left: .35rem;
            min-width: 1.25rem;
            height: 1.25rem;
            padding: 0 .25rem;
            border-radius: 9999px;
            background: #e2e8f0;
            color: #475569;
            font-size: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background .2s, color .2s;
        }
        .dark .archived-live-badge { background: #475569; color: #e2e8f0; }
        .archived-live-badge.has-items { background: #fbbf24; color: #78350f; }
        .dark .archived-live-badge.has-items { background: #d97706; color: #fffbeb; }
        #mgmt-venues-panel, #mgmt-types-panel { animation: fadeIn .2s ease both; }
    `;
    document.head.appendChild(style);
})();

// ═══════════════════════════════════════════════════════════════
// DARK MODE
// ═══════════════════════════════════════════════════════════════

function toggleTheme() {
    const isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('sems-theme', isDark ? 'dark' : 'light');
    _applyThemeUI(isDark);
}

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

document.addEventListener('DOMContentLoaded', function () {
    const saved = localStorage.getItem('sems-theme') || 'light';
    _applyThemeUI(saved === 'dark');
});

// ═══════════════════════════════════════════════════════════════
// SIDEBAR
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

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#sidebar a').forEach(function (el) {
        el.addEventListener('click', function () { if (window.innerWidth < 1024) closeSidebar(); });
    });
});

// ═══════════════════════════════════════════════════════════════
// FILTER TABS
// ═══════════════════════════════════════════════════════════════

function setFilter(status) {
    currentFilter = status;
    document.querySelectorAll('.tab-btn').forEach(function (b) {
        b.className = 'tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-primary-400 hover:text-primary-500 transition-all duration-200';
    });
    const activeTab = document.getElementById('tab-' + status);
    if (activeTab) {
        activeTab.className = 'tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-primary-500 text-white shadow-sm shadow-primary-500/30 transition-all duration-200';
    }
    showActiveView();
    render();
}

// ═══════════════════════════════════════════════════════════════
// LIVE SEARCH
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('liveSearch');
    if (!searchInput) return;
    searchInput.addEventListener('keyup', function () {
        searchQuery = this.value.toLowerCase().trim();
        render();
    });
});

// ═══════════════════════════════════════════════════════════════
// DATE FORMATTER
// ═══════════════════════════════════════════════════════════════

function formatDateTime(datetime) {
    if (!datetime) return 'N/A';
    return new Date(datetime).toLocaleString('en-US', {
        month: 'long', day: 'numeric', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

// ═══════════════════════════════════════════════════════════════
// LIVE ARCHIVED BADGE
// ═══════════════════════════════════════════════════════════════

function syncArchivedBadge() {
    const btn = document.getElementById('view-archived-btn');
    if (!btn) return;
    let badge = btn.querySelector('.archived-live-badge');
    if (!badge) {
        const phpBadge = btn.querySelector('span:not(.archived-live-badge)');
        if (phpBadge) phpBadge.remove();
        badge = document.createElement('span');
        badge.className = 'archived-live-badge';
        btn.appendChild(badge);
    }
    const count = archivedData.length;
    badge.textContent = count;
    badge.classList.toggle('has-items', count > 0);
}

// ═══════════════════════════════════════════════════════════════
// ROW REMOVAL ANIMATION
// ═══════════════════════════════════════════════════════════════

function animateRemoveRow(eventId, onDone) {
    const tbody = document.getElementById('event-table-body');
    if (!tbody) { onDone && onDone(); return; }
    const target = tbody.querySelector('tr[data-event-id="' + eventId + '"]');
    if (target) {
        target.classList.add('removing');
        setTimeout(function () { target.remove(); onDone && onDone(); }, 320);
    } else {
        onDone && onDone();
    }
}

// ═══════════════════════════════════════════════════════════════
// SYNC ALL UI
// ═══════════════════════════════════════════════════════════════

function syncAllUI() {
    render();
    updateStatCards();
    syncArchivedBadge();
    const archSection = document.getElementById('archived-section');
    if (archSection && !archSection.classList.contains('hidden')) {
        renderArchivedTable(archivedData);
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTIVE EVENTS TABLE RENDER
// ═══════════════════════════════════════════════════════════════

function render() {
    const tbody      = document.getElementById('event-table-body');
    const emptyState = document.getElementById('empty-state');
    if (!tbody) return;

    const filtered = eventData.filter(function (e) {
        const matchStatus = currentFilter === 'all' || e.status.toLowerCase() === currentFilter;
        const matchSearch = !searchQuery ||
            e.title.toLowerCase().includes(searchQuery) ||
            e.org.toLowerCase().includes(searchQuery) ||
            String(e.id).includes(searchQuery);
        return matchStatus && matchSearch;
    });

    document.getElementById('result-num').textContent = filtered.length;

    if (filtered.length === 0) {
        tbody.innerHTML = '';
        emptyState.classList.remove('hidden');
        return;
    }

    emptyState.classList.add('hidden');

    tbody.innerHTML = filtered.map(function (e, idx) {
        const pill      = statusStyles[e.status.toLowerCase()] || 'bg-gray-100 text-gray-600';
        const isPending = e.status.toLowerCase() === 'pending';

        return `
        <tr class="event-row hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
            data-event-id="${e.id}" style="animation-delay:${idx * 0.03}s">
            <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-primary-50 dark:bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-calendar-day text-primary-500 dark:text-primary-400 text-xs"></i>
                    </div>
                    <span class="font-medium text-slate-900 dark:text-white">${_escHtml(e.title)}</span>
                </div>
            </td>
            <td class="px-6 py-4 text-slate-600 dark:text-slate-400">${_escHtml(e.org)}</td>
            <td class="px-6 py-4 text-slate-600 dark:text-slate-400 whitespace-nowrap">${_escHtml(e.date)}</td>
            <td class="px-6 py-4">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold capitalize ${pill}">
                    <span class="w-1.5 h-1.5 rounded-full ${statusDot[e.status.toLowerCase()] || 'bg-gray-400'}"></span>
                    ${e.status}
                </span>
            </td>
            <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                    <button onclick="viewEventDetails(${e.id})" title="View Details"
                            class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-slate-400 hover:text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-all duration-200">
                        <i class="far fa-eye text-xs"></i>
                    </button>
                    <button onclick="exportEventPDF(${e.id})" title="Export PDF"
                            class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-slate-400 hover:text-violet-500 hover:bg-violet-50 dark:hover:bg-violet-500/10 transition-all duration-200">
                        <i class="fas fa-download text-xs"></i>
                    </button>
                    <button onclick="openArchiveModal(${e.id})" title="Archive Event"
                            class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-slate-400 hover:text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-500/10 transition-all duration-200">
                        <i class="fas fa-archive text-xs"></i>
                    </button>
                    ${isPending ? `
                    <button onclick="location.href='/admin/admin_aprovals.php'" title="Review"
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-500 hover:bg-amber-600 text-white shadow-sm shadow-amber-500/30 transition-all duration-200">
                        Review
                    </button>` : ''}
                </div>
            </td>
        </tr>`;
    }).join('');
}

var renderTable = render;

// ═══════════════════════════════════════════════════════════════
// UPDATE STAT CARDS
// ═══════════════════════════════════════════════════════════════

function updateStatCards() {
    const counts = {
        total:    eventData.length,
        approved: eventData.filter(function (e) { return e.status === 'approved'; }).length,
        pending:  eventData.filter(function (e) { return e.status === 'pending';  }).length,
        rejected: eventData.filter(function (e) { return e.status === 'rejected'; }).length,
        archived: archivedData.length,
    };
    Object.keys(counts).forEach(function (key) {
        const el = document.getElementById('stat-' + key);
        if (!el) return;
        el.style.transition = 'transform .15s ease, opacity .15s ease';
        el.style.transform  = 'scale(1.3)';
        el.style.opacity    = '0.5';
        el.textContent      = counts[key];
        setTimeout(function () { el.style.transform = 'scale(1)'; el.style.opacity = '1'; }, 150);
    });
}

// ═══════════════════════════════════════════════════════════════
// VIEW TOGGLE (Active ↔ Archived)
// ═══════════════════════════════════════════════════════════════

function showActiveView() {
    const activeSection   = document.getElementById('active-section');
    const archivedSection = document.getElementById('archived-section');
    activeSection.style.display   = 'flex';
    archivedSection.style.display = 'none';
    activeSection.classList.remove('hidden');
    archivedSection.classList.add('hidden');

    const activeBtn   = document.getElementById('view-active-btn');
    const archivedBtn = document.getElementById('view-archived-btn');
    if (!activeBtn || !archivedBtn) return;
    activeBtn.classList.add('bg-primary-500', 'text-white');
    activeBtn.classList.remove('text-slate-600', 'dark:text-slate-400', 'hover:bg-gray-50', 'dark:hover:bg-slate-700');
    archivedBtn.classList.remove('bg-primary-500', 'text-white');
    archivedBtn.classList.add('text-slate-600', 'dark:text-slate-400', 'hover:bg-gray-50', 'dark:hover:bg-slate-700');
}

function showArchivedView() {
    const activeSection   = document.getElementById('active-section');
    const archivedSection = document.getElementById('archived-section');
    activeSection.style.display   = 'none';
    archivedSection.style.display = 'flex';
    activeSection.classList.add('hidden');
    archivedSection.classList.remove('hidden');

    const activeBtn   = document.getElementById('view-active-btn');
    const archivedBtn = document.getElementById('view-archived-btn');
    if (!activeBtn || !archivedBtn) return;
    archivedBtn.classList.add('bg-primary-500', 'text-white');
    archivedBtn.classList.remove('text-slate-600', 'dark:text-slate-400', 'hover:bg-gray-50', 'dark:hover:bg-slate-700');
    activeBtn.classList.remove('bg-primary-500', 'text-white');
    activeBtn.classList.add('text-slate-600', 'dark:text-slate-400', 'hover:bg-gray-50', 'dark:hover:bg-slate-700');
    renderArchivedTable(archivedData);
}

// ═══════════════════════════════════════════════════════════════
// ARCHIVED EVENTS TABLE RENDER
// ═══════════════════════════════════════════════════════════════

function renderArchivedTable(data) {
    const tbody   = document.getElementById('archive-table-body');
    const empty   = document.getElementById('archive-empty-state');
    const countEl = document.getElementById('archive-result-num');
    if (!tbody) return;

    tbody.innerHTML = '';
    if (countEl) countEl.textContent = data.length;

    if (data.length === 0) {
        if (empty) empty.classList.remove('hidden');
        return;
    }
    if (empty) empty.classList.add('hidden');

    data.forEach(function (ev) {
        const tr = document.createElement('tr');
        tr.className  = 'hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors duration-150';
        tr.dataset.id = ev.id;
        tr.innerHTML = `
            <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-archive text-slate-400 text-xs"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-slate-900 dark:text-white text-sm opacity-70 line-clamp-1">${_escHtml(ev.title)}</p>
                        <p class="text-xs text-slate-400 mt-0.5 line-clamp-1">${_escHtml(ev.description)}</p>
                    </div>
                </div>
            </td>
            <td class="px-6 py-4 text-slate-600 dark:text-slate-400 text-xs">${_escHtml(ev.org)}</td>
            <td class="px-6 py-4 text-slate-600 dark:text-slate-400 text-xs whitespace-nowrap">${_escHtml(ev.date)}</td>
            <td class="px-6 py-4 text-xs whitespace-nowrap">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 font-semibold">
                    <i class="fas fa-calendar-times text-[10px]"></i>
                    ${_escHtml(ev.archived_date || '—')}
                </span>
            </td>
            <td class="px-6 py-4 text-xs text-slate-500 dark:text-slate-400">${_escHtml(ev.archived_by_name || 'System')}</td>
            <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                    <button onclick="openRestoreModal(${ev.id})" title="Restore"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold
                                   bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400
                                   hover:bg-emerald-100 dark:hover:bg-emerald-500/20
                                   border border-emerald-200 dark:border-emerald-500/30 transition-all duration-200">
                        <i class="fas fa-undo-alt"></i> Restore
                    </button>
                    <button onclick="openPermDeleteModal(${ev.id})" title="Permanently delete"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold
                                   bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400
                                   hover:bg-rose-100 dark:hover:bg-rose-500/20
                                   border border-rose-200 dark:border-rose-500/30 transition-all duration-200">
                        <i class="fas fa-trash-alt"></i> Delete
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function filterArchived() {
    const q = (document.getElementById('archiveSearch').value || '').toLowerCase().trim();
    const filtered = q
        ? archivedData.filter(function (e) {
            return e.title.toLowerCase().includes(q) || e.org.toLowerCase().includes(q);
          })
        : archivedData;
    renderArchivedTable(filtered);
}

// ═══════════════════════════════════════════════════════════════
// VIEW EVENT DETAILS MODAL
// ═══════════════════════════════════════════════════════════════

function viewEventDetails(eventId) {
    const event = eventData.find(function (e) { return e.id == eventId; });
    if (!event) return;

    const pill = statusBadgeStyles[event.status.toLowerCase()] || 'bg-gray-100 text-gray-600';
    const dot  = statusDot[event.status.toLowerCase()] || 'bg-gray-400';
    const desc = event.description && event.description.trim() !== '' ? event.description : 'No description provided.';

    document.getElementById('modalBody').innerHTML = `
        <div class="p-4 rounded-xl bg-primary-50 dark:bg-primary-500/10 border border-primary-100 dark:border-primary-500/20 flex items-center justify-between gap-4">
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-semibold text-primary-500 uppercase tracking-wider mb-1"><i class="fas fa-heading mr-1"></i> Event Title</p>
                <p class="font-bold text-slate-900 dark:text-white text-base truncate">${_escHtml(event.title)}</p>
            </div>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold capitalize ${pill} flex-shrink-0">
                <span class="w-1.5 h-1.5 rounded-full ${dot}"></span>
                ${event.status}
            </span>
        </div>
        <div class="p-4 rounded-xl bg-gray-50 dark:bg-slate-700/30 border border-gray-100 dark:border-slate-700">
            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-2"><i class="fas fa-align-left mr-1"></i> Description</p>
            <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">${_escHtml(desc)}</p>
        </div>
        <div class="p-4 rounded-xl bg-gray-50 dark:bg-slate-700/30 border border-gray-100 dark:border-slate-700">
            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1"><i class="fas fa-building mr-1"></i> Organizer</p>
            <p class="font-semibold text-slate-900 dark:text-white text-sm">${_escHtml(event.org)}</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="p-4 rounded-xl bg-blue-50 dark:bg-blue-500/10 border border-blue-100 dark:border-blue-500/20">
                <p class="text-[10px] font-semibold text-blue-500 uppercase tracking-wider mb-1"><i class="far fa-calendar mr-1"></i> Starts</p>
                <p class="font-semibold text-slate-900 dark:text-white text-sm">${formatDateTime(event.start_datetime)}</p>
            </div>
            <div class="p-4 rounded-xl bg-violet-50 dark:bg-violet-500/10 border border-violet-100 dark:border-violet-500/20">
                <p class="text-[10px] font-semibold text-violet-500 uppercase tracking-wider mb-1"><i class="far fa-calendar-check mr-1"></i> Ends</p>
                <p class="font-semibold text-slate-900 dark:text-white text-sm">${formatDateTime(event.end_datetime)}</p>
            </div>
        </div>
    `;
    document.getElementById('eventModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('eventModal').classList.add('hidden');
}

// ═══════════════════════════════════════════════════════════════
// ARCHIVE MODAL
// ═══════════════════════════════════════════════════════════════

function openArchiveModal(eventId) {
    const event = eventData.find(function (e) { return e.id == eventId; });
    if (!event) return;
    _pendingArchiveId = eventId;
    document.getElementById('archiveEventTitle').textContent = event.title;
    document.getElementById('archiveModal').classList.remove('hidden');
}

function closeArchiveModal() {
    _pendingArchiveId = null;
    document.getElementById('archiveModal').classList.add('hidden');
}

async function archiveEvent() {
    if (!_pendingArchiveId) return;
    const idSnap = _pendingArchiveId;
    const btn    = document.getElementById('confirmArchiveBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archiving…';
    try {
        const fd = new FormData();
        fd.append('archive_event_id', idSnap);
        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();
        closeArchiveModal();
        if (data.success) {
            animateRemoveRow(idSnap, function () {
                const idx = eventData.findIndex(function (e) { return e.id == idSnap; });
                if (idx !== -1) {
                    const removed            = eventData.splice(idx, 1)[0];
                    removed.archived_date    = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                    removed.archived_by_name = 'You';
                    removed.deleted_at       = new Date().toISOString();
                    archivedData.unshift(removed);
                }
                syncAllUI();
            });
            showToast('Event archived. You can restore it from the Archive view.', 'success');
        } else {
            showToast(data.message || 'Archive failed.', 'error');
        }
    } catch (_) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-archive"></i> Archive';
    }
}

// ═══════════════════════════════════════════════════════════════
// RESTORE MODAL
// ═══════════════════════════════════════════════════════════════

function openRestoreModal(eventId) {
    const event = archivedData.find(function (e) { return e.id == eventId; });
    if (!event) return;
    _pendingRestoreId = eventId;
    document.getElementById('restoreEventTitle').textContent = event.title;
    document.getElementById('restoreModal').classList.remove('hidden');
}

function closeRestoreModal() {
    _pendingRestoreId = null;
    document.getElementById('restoreModal').classList.add('hidden');
}

async function restoreEvent() {
    if (!_pendingRestoreId) return;
    const idSnap = _pendingRestoreId;
    const btn    = document.getElementById('confirmRestoreBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring…';
    try {
        const fd = new FormData();
        fd.append('restore_event_id', idSnap);
        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();
        closeRestoreModal();
        if (data.success) {
            const idx = archivedData.findIndex(function (e) { return e.id == idSnap; });
            if (idx !== -1) {
                const restored = archivedData.splice(idx, 1)[0];
                delete restored.deleted_at;
                delete restored.archived_date;
                delete restored.archived_by_name;
                eventData.unshift(restored);
            }
            syncAllUI();
            showToast('Event restored successfully!', 'success');
        } else {
            showToast(data.message || 'Restore failed.', 'error');
        }
    } catch (_) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-undo-alt"></i> Restore';
    }
}

// ═══════════════════════════════════════════════════════════════
// PERMANENT DELETE MODAL
// ═══════════════════════════════════════════════════════════════

function openPermDeleteModal(eventId) {
    const event = archivedData.find(function (e) { return e.id == eventId; });
    if (!event) return;
    _pendingPermDelId = eventId;
    document.getElementById('permDeleteEventTitle').textContent = event.title;
    document.getElementById('permDeleteModal').classList.remove('hidden');
}

function closePermDeleteModal() {
    _pendingPermDelId = null;
    document.getElementById('permDeleteModal').classList.add('hidden');
}

async function permanentDeleteEvent() {
    if (!_pendingPermDelId) return;
    const idSnap = _pendingPermDelId;
    const btn    = document.getElementById('confirmPermDeleteBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting…';
    try {
        const fd = new FormData();
        fd.append('permanent_delete_event_id', idSnap);
        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();
        closePermDeleteModal();
        if (data.success) {
            archivedData = archivedData.filter(function (e) { return e.id != idSnap; });
            syncAllUI();
            showToast('Event permanently deleted.', 'error');
        } else {
            showToast(data.message || 'Delete failed.', 'error');
        }
    } catch (_) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Forever';
    }
}

// ═══════════════════════════════════════════════════════════════
// ════════════════════════════════════════════════════════════════
//  MANAGE VENUES & EVENT TYPES
// ════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════

// ── Open / Close main manage modal ──────────────────────────────

function openManageModal() {
    document.getElementById('manageModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    switchManageTab(_manageTab);   // restore last active tab
}

function closeManageModal() {
    document.getElementById('manageModal').classList.add('hidden');
    document.body.style.overflow = '';
}

// ── Tab switching ────────────────────────────────────────────────

function switchManageTab(tab) {
    _manageTab = tab;

    // Tab button styles
    ['venues', 'types'].forEach(function (t) {
        const btn = document.getElementById('mgmt-tab-' + t);
        if (!btn) return;
        if (t === tab) {
            btn.className = 'px-4 py-2 rounded-xl text-xs font-semibold bg-primary-500 text-white shadow-sm transition-all duration-200';
        } else {
            btn.className = 'px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-primary-400 hover:text-primary-500 transition-all duration-200';
        }
    });

    // Panel visibility
    document.getElementById('mgmt-venues-panel').classList.toggle('hidden', tab !== 'venues');
    document.getElementById('mgmt-types-panel').classList.toggle('hidden', tab !== 'types');

    // Render active panel
    if (tab === 'venues') {
        renderVenueList();
    } else {
        renderTypeList();
    }
}

// ── RENDER VENUE LIST ────────────────────────────────────────────

function renderVenueList() {
    const tbody = document.getElementById('venue-list-body');
    const empty = document.getElementById('venue-empty-state');
    const badge = document.getElementById('venue-count-badge');
    if (!tbody) return;

    if (badge) badge.textContent = venueData.length + ' venue' + (venueData.length !== 1 ? 's' : '');

    if (venueData.length === 0) {
        tbody.innerHTML = '';
        if (empty) empty.classList.remove('hidden');
        return;
    }
    if (empty) empty.classList.add('hidden');

    tbody.innerHTML = venueData.map(function (v) {
        const capText = (v.capacity != null && v.capacity !== '')
            ? Number(v.capacity).toLocaleString() + ' pax'
            : '<span class="italic text-slate-400">—</span>';
        return `
        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
            <td class="px-4 py-3 font-medium text-slate-900 dark:text-white text-sm">${_escHtml(v.venue_name)}</td>
            <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-sm">${capText}</td>
            <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                    <button onclick="openVenueForm(${v.venue_id})"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold
                                   bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400
                                   hover:bg-blue-100 dark:hover:bg-blue-500/20
                                   border border-blue-200 dark:border-blue-500/30 transition-all">
                        <i class="fas fa-pencil-alt"></i> Edit
                    </button>
                    <button onclick="openMgmtDeleteModal(${v.venue_id}, 'venue')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold
                                   bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400
                                   hover:bg-rose-100 dark:hover:bg-rose-500/20
                                   border border-rose-200 dark:border-rose-500/30 transition-all">
                        <i class="fas fa-trash-alt"></i> Delete
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

// ── RENDER EVENT TYPE LIST ───────────────────────────────────────

function renderTypeList() {
    const tbody = document.getElementById('type-list-body');
    const empty = document.getElementById('type-empty-state');
    const badge = document.getElementById('type-count-badge');
    if (!tbody) return;

    if (badge) badge.textContent = eventTypeData.length + ' type' + (eventTypeData.length !== 1 ? 's' : '');

    if (eventTypeData.length === 0) {
        tbody.innerHTML = '';
        if (empty) empty.classList.remove('hidden');
        return;
    }
    if (empty) empty.classList.add('hidden');

    tbody.innerHTML = eventTypeData.map(function (t) {
        let scopeHtml;
        if (t.org_name) {
            scopeHtml = `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs
                           bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400
                           border border-blue-100 dark:border-blue-500/20">
                           <i class="fas fa-building text-[10px]"></i> ${_escHtml(t.org_name)}</span>`;
        } else if (t.club_name) {
            scopeHtml = `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs
                           bg-violet-50 dark:bg-violet-500/10 text-violet-600 dark:text-violet-400
                           border border-violet-100 dark:border-violet-500/20">
                           <i class="fas fa-users text-[10px]"></i> ${_escHtml(t.club_name)}</span>`;
        } else {
            scopeHtml = '<span class="text-slate-400 italic text-xs">General</span>';
        }
        return `
        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
            <td class="px-4 py-3 font-medium text-slate-900 dark:text-white text-sm">${_escHtml(t.type_name)}</td>
            <td class="px-4 py-3">${scopeHtml}</td>
            <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                    <button onclick="openTypeForm(${t.type_id})"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold
                                   bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400
                                   hover:bg-blue-100 dark:hover:bg-blue-500/20
                                   border border-blue-200 dark:border-blue-500/30 transition-all">
                        <i class="fas fa-pencil-alt"></i> Edit
                    </button>
                    <button onclick="openMgmtDeleteModal(${t.type_id}, 'type')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold
                                   bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400
                                   hover:bg-rose-100 dark:hover:bg-rose-500/20
                                   border border-rose-200 dark:border-rose-500/30 transition-all">
                        <i class="fas fa-trash-alt"></i> Delete
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

// ── VENUE FORM (Add / Edit) ──────────────────────────────────────

function openVenueForm(venueId) {
    _editingVenueId = (venueId != null) ? venueId : null;
    const titleEl   = document.getElementById('venueFormTitle');
    const nameInput = document.getElementById('venueNameInput');
    const capInput  = document.getElementById('venueCapInput');

    if (_editingVenueId !== null) {
        const venue = venueData.find(function (v) { return v.venue_id == _editingVenueId; });
        if (!venue) return;
        titleEl.textContent = 'Edit Venue';
        nameInput.value     = venue.venue_name;
        capInput.value      = (venue.capacity != null) ? venue.capacity : '';
    } else {
        titleEl.textContent = 'Add New Venue';
        nameInput.value     = '';
        capInput.value      = '';
    }

    document.getElementById('venueFormModal').classList.remove('hidden');
    setTimeout(function () { nameInput.focus(); }, 50);
}

function closeVenueForm() {
    _editingVenueId = null;
    document.getElementById('venueFormModal').classList.add('hidden');
}

async function submitVenueForm() {
    const name = document.getElementById('venueNameInput').value.trim();
    const cap  = document.getElementById('venueCapInput').value.trim();

    if (!name) { showToast('Venue name is required.', 'error'); return; }

    const btn = document.getElementById('venueFormSubmitBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    if (_editingVenueId !== null) {
        fd.append('edit_venue',  '1');
        fd.append('venue_id',    _editingVenueId);
    } else {
        fd.append('add_venue', '1');
    }
    fd.append('venue_name', name);
    fd.append('capacity',   cap);

    try {
        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            if (_editingVenueId !== null) {
                const idx = venueData.findIndex(function (v) { return v.venue_id == _editingVenueId; });
                if (idx !== -1) {
                    venueData[idx].venue_name = name;
                    venueData[idx].capacity   = cap !== '' ? parseInt(cap) : null;
                }
                showToast('Venue updated successfully!', 'success');
            } else {
                venueData.push(data.venue);
                showToast('Venue added successfully!', 'success');
            }
            closeVenueForm();
            renderVenueList();
        } else {
            showToast(data.message || 'Failed to save venue.', 'error');
        }
    } catch (_) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Venue';
    }
}

// ── EVENT TYPE FORM (Add / Edit) ─────────────────────────────────

function openTypeForm(typeId) {
    _editingTypeId  = (typeId != null) ? typeId : null;
    const titleEl   = document.getElementById('typeFormTitle');
    const nameInput = document.getElementById('typeNameInput');
    const orgSel    = document.getElementById('typeOrgSelect');
    const clubSel   = document.getElementById('typeClubSelect');

    // Populate org dropdown
    orgSel.innerHTML = '<option value="">— None —</option>' +
        orgData.map(function (o) {
            return '<option value="' + o.org_id + '">' + _escHtml(o.org_name) + '</option>';
        }).join('');

    // Populate club dropdown
    clubSel.innerHTML = '<option value="">— None —</option>' +
        clubData.map(function (c) {
            return '<option value="' + c.club_id + '">' + _escHtml(c.club_name) + '</option>';
        }).join('');

    if (_editingTypeId !== null) {
        const type = eventTypeData.find(function (t) { return t.type_id == _editingTypeId; });
        if (!type) return;
        titleEl.textContent = 'Edit Event Type';
        nameInput.value     = type.type_name;
        orgSel.value        = type.org_id  || '';
        clubSel.value       = type.club_id || '';
    } else {
        titleEl.textContent = 'Add Event Type';
        nameInput.value     = '';
        orgSel.value        = '';
        clubSel.value       = '';
    }

    document.getElementById('typeFormModal').classList.remove('hidden');
    setTimeout(function () { nameInput.focus(); }, 50);
}

function closeTypeForm() {
    _editingTypeId = null;
    document.getElementById('typeFormModal').classList.add('hidden');
}

async function submitTypeForm() {
    const name   = document.getElementById('typeNameInput').value.trim();
    const orgId  = document.getElementById('typeOrgSelect').value;
    const clubId = document.getElementById('typeClubSelect').value;

    if (!name) { showToast('Type name is required.', 'error'); return; }

    const btn = document.getElementById('typeFormSubmitBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    if (_editingTypeId !== null) {
        fd.append('edit_event_type', '1');
        fd.append('type_id',         _editingTypeId);
    } else {
        fd.append('add_event_type', '1');
    }
    fd.append('type_name', name);
    fd.append('org_id',    orgId);
    fd.append('club_id',   clubId);

    try {
        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            if (_editingTypeId !== null) {
                const idx = eventTypeData.findIndex(function (t) { return t.type_id == _editingTypeId; });
                if (idx !== -1) {
                    const org  = orgData.find(function (o)  { return o.org_id   == orgId;  });
                    const club = clubData.find(function (c) { return c.club_id  == clubId; });
                    eventTypeData[idx].type_name  = name;
                    eventTypeData[idx].org_id     = orgId  || null;
                    eventTypeData[idx].club_id    = clubId || null;
                    eventTypeData[idx].org_name   = org  ? org.org_name   : null;
                    eventTypeData[idx].club_name  = club ? club.club_name : null;
                }
                showToast('Event type updated!', 'success');
            } else {
                eventTypeData.push(data.type);
                showToast('Event type added!', 'success');
            }
            closeTypeForm();
            renderTypeList();
        } else {
            showToast(data.message || 'Failed to save event type.', 'error');
        }
    } catch (_) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Type';
    }
}

// ── MANAGE DELETE CONFIRM ────────────────────────────────────────

function openMgmtDeleteModal(id, kind) {
    _pendingMgmtDeleteId   = id;
    _pendingMgmtDeleteKind = kind;

    let name = '';
    if (kind === 'venue') {
        const v = venueData.find(function (x) { return x.venue_id == id; });
        name = v ? v.venue_name : '';
    } else {
        const t = eventTypeData.find(function (x) { return x.type_id == id; });
        name = t ? t.type_name : '';
    }

    document.getElementById('mgmtDeleteName').textContent = name;
    document.getElementById('mgmtDeleteModal').classList.remove('hidden');
}

function closeMgmtDeleteModal() {
    _pendingMgmtDeleteId   = null;
    _pendingMgmtDeleteKind = null;
    document.getElementById('mgmtDeleteModal').classList.add('hidden');
}

async function confirmMgmtDelete() {
    if (!_pendingMgmtDeleteId || !_pendingMgmtDeleteKind) return;

    const idSnap   = _pendingMgmtDeleteId;
    const kindSnap = _pendingMgmtDeleteKind;
    const btn      = document.getElementById('mgmtDeleteConfirmBtn');
    btn.disabled   = true;
    btn.innerHTML  = '<i class="fas fa-spinner fa-spin"></i> Deleting…';

    const fd = new FormData();
    if (kindSnap === 'venue') {
        fd.append('delete_venue', '1');
        fd.append('venue_id',     idSnap);
    } else {
        fd.append('delete_event_type', '1');
        fd.append('type_id',           idSnap);
    }

    try {
        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();

        closeMgmtDeleteModal();

        if (data.success) {
            if (kindSnap === 'venue') {
                venueData = venueData.filter(function (v) { return v.venue_id != idSnap; });
                renderVenueList();
            } else {
                eventTypeData = eventTypeData.filter(function (t) { return t.type_id != idSnap; });
                renderTypeList();
            }
            showToast('Deleted successfully.', 'success');
        } else {
            showToast(data.message || 'Delete failed.', 'error');
        }
    } catch (_) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete';
    }
}

// ═══════════════════════════════════════════════════════════════
// PDF EXPORT (Print via hidden iframe)
// ═══════════════════════════════════════════════════════════════

function exportEventPDF(eventId) {
    const event = eventData.find(function (e) { return e.id == eventId; });
    if (!event) return;

    const desc = event.description && event.description.trim() !== ''
        ? event.description : 'No description provided.';

    const pdfContent = `
        <!DOCTYPE html><html><head>
        <title>Event Report - ${_escHtml(event.title)}</title>
        <style>
            body { font-family:'Segoe UI',Arial,sans-serif; padding:40px; color:#1e293b; }
            .header { border-bottom:3px solid #3b82f6; padding-bottom:20px; margin-bottom:30px; }
            .header h1 { color:#1e293b; margin:0; font-size:26px; }
            .header p { color:#64748b; margin:5px 0 0 0; font-size:13px; }
            .logo { font-size:20px; font-weight:800; color:#3b82f6; margin-bottom:6px; }
            .desc-box { background:#f8fafc; padding:18px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:22px; }
            .desc-box h3 { color:#64748b; font-size:10px; text-transform:uppercase; letter-spacing:1px; margin:0 0 10px 0; font-weight:700; }
            .desc-box p { color:#334155; font-size:14px; line-height:1.7; margin:0; }
            .grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:22px; }
            .box { background:#f8fafc; padding:14px; border-radius:8px; border-left:3px solid #3b82f6; }
            .box h3 { color:#64748b; font-size:10px; text-transform:uppercase; letter-spacing:1px; margin:0 0 6px 0; font-weight:700; }
            .box p { color:#1e293b; font-size:14px; margin:0; font-weight:600; }
            .badge { display:inline-block; padding:5px 12px; border-radius:20px; font-size:12px; font-weight:600; text-transform:capitalize; }
            .s-approved { background:#d1fae5; color:#065f46; }
            .s-pending  { background:#fef3c7; color:#92400e; }
            .s-rejected { background:#fee2e2; color:#991b1b; }
            .footer { margin-top:38px; padding-top:18px; border-top:1px solid #e2e8f0; color:#94a3b8; font-size:11px; text-align:center; }
            @media print { body { padding:40px; } }
        </style></head><body>
        <div class="header">
            <div class="logo">SEMS Admin</div>
            <h1>${_escHtml(event.title)}</h1>
            <p>Event Report &mdash; generated on ${new Date().toLocaleDateString()}</p>
        </div>
        <div class="desc-box"><h3>Description</h3><p>${_escHtml(desc)}</p></div>
        <div class="grid">
            <div class="box"><h3>Organizer</h3><p>${_escHtml(event.org)}</p></div>
            <div class="box"><h3>Status</h3><span class="badge s-${event.status.toLowerCase()}">${event.status}</span></div>
            <div class="box" style="border-left-color:#3b82f6"><h3>Start Date &amp; Time</h3><p>${formatDateTime(event.start_datetime)}</p></div>
            <div class="box" style="border-left-color:#8b5cf6"><h3>End Date &amp; Time</h3><p>${formatDateTime(event.end_datetime)}</p></div>
        </div>
        <div class="footer">Generated by SEMS Admin System &bull; ${new Date().toLocaleString()}</div>
        </body></html>`;

    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:0;height:0;border:0;';
    document.body.appendChild(iframe);
    const iframeDoc = iframe.contentWindow.document;
    iframeDoc.open(); iframeDoc.write(pdfContent); iframeDoc.close();
    setTimeout(function () {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
        iframe.contentWindow.onafterprint = function () { document.body.removeChild(iframe); };
        setTimeout(function () { if (document.body.contains(iframe)) document.body.removeChild(iframe); }, 60000);
    }, 500);
}

// ═══════════════════════════════════════════════════════════════
// TOAST NOTIFICATION
// ═══════════════════════════════════════════════════════════════

function showToast(message, type) {
    type = type || 'success';
    const colors = {
        success: 'bg-emerald-500 shadow-emerald-500/30',
        error:   'bg-rose-500 shadow-rose-500/30',
        info:    'bg-primary-500 shadow-primary-500/30',
    };
    const icons = {
        success: 'fa-check-circle',
        error:   'fa-exclamation-circle',
        info:    'fa-info-circle',
    };

    const toast = document.createElement('div');
    toast.className = `fixed top-4 right-4 z-[999] ${colors[type] || colors.info} text-white px-4 py-3 rounded-xl shadow-lg flex items-center gap-2.5 text-sm font-medium translate-x-full transition-transform duration-300`;
    toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i><span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(function () { toast.classList.remove('translate-x-full'); }, 50);
    setTimeout(function () {
        toast.classList.add('translate-x-full');
        setTimeout(function () { toast.remove(); }, 300);
    }, 3200);
}

// ═══════════════════════════════════════════════════════════════
// INTERNAL HELPERS
// ═══════════════════════════════════════════════════════════════

function _escHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ═══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════════════════

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeModal();
        closeArchiveModal();
        closeRestoreModal();
        closePermDeleteModal();
        closeMgmtDeleteModal();
        closeVenueForm();
        closeTypeForm();
        closeManageModal();
    }
});

// ═══════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    render();
    syncArchivedBadge();
});