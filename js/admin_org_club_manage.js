/**
 * SEMS Admin — admin_org_club_management.js
 * v2 — Archive filter, Archive/Restore/Permanent-Delete, AJAX Auto-Refresh
 *
 * Requires SEMS_ORG_DATA defined inline before this script:
 *   <script>
 *     const SEMS_ORG_DATA = {
 *         active:    [...],   // active orgs/clubs
 *         archived:  [...],   // soft-deleted orgs/clubs
 *         csrfToken: "...",
 *     };
 *   </script>
 */

// ═══════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════

var activeData    = [];   // non-archived orgs/clubs
var archivedData  = [];   // soft-deleted orgs/clubs
var csrfToken     = '';

var currentFilter     = 'all';   // 'all' | 'organization' | 'club' | 'archived'
var searchQuery       = '';
var pendingDeleteId   = null;
var pendingDeleteType = null;
var pendingDeleteMode = 'archive'; // 'archive' | 'permanent'
var isProcessing      = false;
var autoRefreshTimer  = null;

var membersState = {
    id: null, type: null, page: 1, search: '', roleFilter: 'all',
    total: 0, totalPages: 1, loading: false
};
var membersSearchTimeout = null;

var eventsState = {
    id: null, type: null, page: 1, search: '',
    total: 0, totalPages: 1, loading: false
};
var eventsSearchTimeout = null;

// ═══════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    if (typeof SEMS_ORG_DATA !== 'undefined') {
        activeData   = SEMS_ORG_DATA.active   || [];
        archivedData = SEMS_ORG_DATA.archived  || [];
        csrfToken    = SEMS_ORG_DATA.csrfToken || '';
    }
    render();
    updateStats();
    _applyThemeUI(localStorage.getItem('sems-theme') === 'dark');
    startAutoRefresh();
});

// ═══════════════════════════════════════════════════════════════
// DARK MODE
// ═══════════════════════════════════════════════════════════════

function toggleTheme() {
    var isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('sems-theme', isDark ? 'dark' : 'light');
    _applyThemeUI(isDark);
}

function _applyThemeUI(isDark) {
    var icon  = document.getElementById('theme-icon');
    var label = document.getElementById('theme-label');
    if (!icon || !label) return;
    if (isDark) {
        icon.className    = 'fas fa-sun w-5 text-center text-amber-500';
        label.textContent = 'Light Mode';
    } else {
        icon.className    = 'fas fa-moon w-5 text-center';
        label.textContent = 'Dark Mode';
    }
}

// ═══════════════════════════════════════════════════════════════
// SIDEBAR
// ═══════════════════════════════════════════════════════════════

function openSidebar() {
    document.getElementById('sidebar').classList.remove('-translate-x-full');
    var ov = document.getElementById('overlay');
    ov.classList.remove('pointer-events-none', 'opacity-0');
    ov.classList.add('pointer-events-auto', 'opacity-100');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    document.getElementById('sidebar').classList.add('-translate-x-full');
    var ov = document.getElementById('overlay');
    ov.classList.remove('pointer-events-auto', 'opacity-100');
    ov.classList.add('pointer-events-none', 'opacity-0');
    document.body.style.overflow = '';
}

// ═══════════════════════════════════════════════════════════════
// STATS
// ═══════════════════════════════════════════════════════════════

function updateStats() {
    var total    = activeData.length;
    var orgs     = activeData.filter(function (o) { return o.type === 'organization'; }).length;
    var clubs    = activeData.filter(function (o) { return o.type === 'club'; }).length;
    var archived = archivedData.length;
    document.getElementById('stat-total').textContent    = total;
    document.getElementById('stat-orgs').textContent     = orgs;
    document.getElementById('stat-clubs').textContent    = clubs;
    document.getElementById('stat-archived').textContent = archived;
}

// ═══════════════════════════════════════════════════════════════
// FILTER TABS
// ═══════════════════════════════════════════════════════════════

function setFilter(filter) {
    currentFilter = filter;

    // Reset all tabs to inactive style
    document.querySelectorAll('.tab-btn').forEach(function (b) {
        b.className = 'tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-primary-400 hover:text-primary-500 transition-all duration-200 flex items-center gap-2';
    });

    // Set active tab style
    var activeTab = document.getElementById('tab-' + filter);
    if (activeTab) {
        var cls = 'tab-btn px-4 py-2 rounded-xl text-xs font-semibold text-white shadow-sm transition-all duration-200 flex items-center gap-2 ';
        if      (filter === 'all')           cls += 'bg-primary-500 shadow-primary-500/30';
        else if (filter === 'organization')  cls += 'bg-purple-500 shadow-purple-500/30';
        else if (filter === 'club')          cls += 'bg-sky-500 shadow-sky-500/30';
        else if (filter === 'archived')      cls += 'bg-amber-500 shadow-amber-500/30';
        activeTab.className = cls;
    }

    // Toggle Add New button — hidden in Archived view
    var addBtn = document.getElementById('add-new-btn');
    if (addBtn) addBtn.classList.toggle('hidden', filter === 'archived');

    render();
}

// ═══════════════════════════════════════════════════════════════
// SEARCH
// ═══════════════════════════════════════════════════════════════

function handleSearch() {
    searchQuery = document.getElementById('searchInput').value.toLowerCase().trim();
    render();
}

// ═══════════════════════════════════════════════════════════════
// RENDER DISPATCHER
// ═══════════════════════════════════════════════════════════════

function render() {
    if (currentFilter === 'archived') {
        renderArchivedGrid();
    } else {
        renderActiveGrid();
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTIVE GRID
// ═══════════════════════════════════════════════════════════════

function renderActiveGrid() {
    var grid       = document.getElementById('orgs-grid');
    var emptyState = document.getElementById('empty-state');

    var filtered = activeData.filter(function (o) {
        var matchType   = currentFilter === 'all' || o.type === currentFilter;
        var matchSearch = !searchQuery || o.name.toLowerCase().includes(searchQuery) || String(o.id).includes(searchQuery);
        return matchType && matchSearch;
    });

    document.getElementById('result-num').textContent   = filtered.length;
    document.getElementById('result-label').textContent = 'results';

    if (filtered.length === 0) {
        grid.innerHTML = '';
        emptyState.classList.remove('hidden');
        return;
    }
    emptyState.classList.add('hidden');
    grid.innerHTML = filtered.map(renderActiveCard).join('');
}

function renderActiveCard(o, idx) {
    var isOrg       = o.type === 'organization';
    var accentColor = isOrg ? 'purple' : 'sky';
    var icon        = isOrg ? 'fa-building' : 'fa-users';
    var label       = isOrg ? 'Organization' : 'Club';

    var logoHtml;
    if (o.logo) {
        var logoSrc = (typeof o.logo === 'string' && o.logo.startsWith('data:'))
            ? o.logo
            : 'data:image/jpeg;base64,' + o.logo;
        logoHtml = '<img src="' + logoSrc + '" class="w-full h-full object-cover" alt="' + escapeHtml(o.name) + '">';
    } else {
        logoHtml = '<div class="w-full h-full bg-gradient-to-br from-' + accentColor + '-100 to-' + accentColor + '-200 dark:from-' + accentColor + '-900/30 dark:to-' + accentColor + '-800/30 flex items-center justify-center"><i class="fas ' + icon + ' text-' + accentColor + '-500 text-3xl"></i></div>';
    }

    return `
    <div class="card-anim org-card animate-fade-up bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden" style="animation-delay:${idx * 0.05}s">
        <div class="h-32 bg-gray-100 dark:bg-slate-700 relative overflow-hidden">
            ${logoHtml}
            <div class="absolute top-3 right-3">
                <span class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-white/90 dark:bg-slate-800/90 backdrop-blur text-${accentColor}-600 dark:text-${accentColor}-400 shadow-sm border border-${accentColor}-100 dark:border-${accentColor}-800 flex items-center gap-1.5">
                    <i class="fas ${icon} text-xs"></i> ${label}
                </span>
            </div>
        </div>
        <div class="p-5">
            <h3 class="font-bold text-slate-900 dark:text-white text-lg mb-3" title="${escapeHtml(o.name)}">${escapeHtml(o.name)}</h3>
            <div class="grid grid-cols-2 gap-3 mb-4">
                <div onclick="openMembersModal(${o.id}, '${o.type}', '${escapeJsString(o.name)}')"
                     class="stat-clickable p-3 rounded-xl bg-gray-50 dark:bg-slate-700/50 border border-gray-100 dark:border-slate-700 hover:border-${accentColor}-300 dark:hover:border-${accentColor}-700 hover:bg-${accentColor}-50 dark:hover:bg-${accentColor}-500/5">
                    <div class="flex items-center gap-2 mb-1">
                        <i class="fas fa-users text-${accentColor}-500 text-xs"></i>
                        <span class="text-xs text-slate-500 dark:text-slate-400">Members</span>
                    </div>
                    <p class="text-lg font-bold text-slate-900 dark:text-white">${o.user_count || 0}</p>
                </div>
                <div onclick="openEventsModal(${o.id}, '${o.type}', '${escapeJsString(o.name)}')"
                     class="stat-clickable p-3 rounded-xl bg-gray-50 dark:bg-slate-700/50 border border-gray-100 dark:border-slate-700 hover:border-${accentColor}-300 dark:hover:border-${accentColor}-700 hover:bg-${accentColor}-50 dark:hover:bg-${accentColor}-500/5">
                    <div class="flex items-center gap-2 mb-1">
                        <i class="fas fa-calendar text-${accentColor}-500 text-xs"></i>
                        <span class="text-xs text-slate-500 dark:text-slate-400">Events</span>
                    </div>
                    <p class="text-lg font-bold text-slate-900 dark:text-white">${o.event_count || 0}</p>
                </div>
            </div>
            <div class="flex gap-2">
                <button onclick="openEditModal(${o.id}, '${o.type}')"
                        class="flex-1 py-2 rounded-xl text-xs font-semibold bg-${accentColor}-50 dark:bg-${accentColor}-500/10 text-${accentColor}-600 dark:text-${accentColor}-400 hover:bg-${accentColor}-100 dark:hover:bg-${accentColor}-500/20 transition-all duration-200 flex items-center justify-center gap-2">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button onclick="openDeleteModal(${o.id}, '${o.type}', 'archive')"
                        class="flex-1 py-2 rounded-xl text-xs font-semibold bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-100 dark:hover:bg-amber-500/20 transition-all duration-200 flex items-center justify-center gap-2">
                    <i class="fas fa-archive"></i> Archive
                </button>
            </div>
        </div>
    </div>`;
}

// ═══════════════════════════════════════════════════════════════
// ARCHIVED GRID
// ═══════════════════════════════════════════════════════════════

function renderArchivedGrid() {
    var grid       = document.getElementById('orgs-grid');
    var emptyState = document.getElementById('empty-state');

    var filtered = archivedData.filter(function (o) {
        return !searchQuery || o.name.toLowerCase().includes(searchQuery) || String(o.id).includes(searchQuery);
    });

    document.getElementById('result-num').textContent   = filtered.length;
    document.getElementById('result-label').textContent = 'archived';

    if (filtered.length === 0) {
        grid.innerHTML = '';
        emptyState.classList.remove('hidden');
        return;
    }
    emptyState.classList.add('hidden');
    grid.innerHTML = filtered.map(renderArchivedCard).join('');
}

function renderArchivedCard(o, idx) {
    var isOrg = o.type === 'organization';
    var icon  = isOrg ? 'fa-building' : 'fa-users';
    var label = isOrg ? 'Organization' : 'Club';

    var logoHtml;
    if (o.logo) {
        var logoSrc = (typeof o.logo === 'string' && o.logo.startsWith('data:'))
            ? o.logo
            : 'data:image/jpeg;base64,' + o.logo;
        logoHtml = '<img src="' + logoSrc + '" class="w-full h-full object-cover opacity-40 grayscale" alt="' + escapeHtml(o.name) + '">';
    } else {
        logoHtml = '<div class="w-full h-full bg-gradient-to-br from-gray-100 to-gray-200 dark:from-slate-700 dark:to-slate-600 flex items-center justify-center"><i class="fas ' + icon + ' text-gray-300 dark:text-slate-500 text-3xl"></i></div>';
    }

    var archivedDate = '';
    if (o.deleted_at) {
        try {
            archivedDate = new Date(o.deleted_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        } catch (_) {}
    }

    return `
    <div class="card-anim org-card animate-fade-up bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-amber-200/60 dark:border-amber-800/30 overflow-hidden transition-opacity opacity-80 hover:opacity-100" style="animation-delay:${idx * 0.05}s">
        <div class="h-32 bg-gray-100 dark:bg-slate-700 relative overflow-hidden">
            ${logoHtml}
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900/30 to-transparent pointer-events-none"></div>
            <div class="absolute bottom-3 left-3">
                <span class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-amber-100/90 dark:bg-amber-900/70 backdrop-blur text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-700 flex items-center gap-1.5">
                    <i class="fas fa-archive text-xs"></i> Archived
                </span>
            </div>
            <div class="absolute top-3 right-3">
                <span class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-white/80 dark:bg-slate-800/80 backdrop-blur text-slate-400 dark:text-slate-500 border border-gray-200 dark:border-slate-600 flex items-center gap-1.5">
                    <i class="fas ${icon} text-xs"></i> ${label}
                </span>
            </div>
        </div>
        <div class="p-5">
            <h3 class="font-bold text-slate-500 dark:text-slate-400 text-lg mb-1 line-through decoration-slate-300 dark:decoration-slate-600" title="${escapeHtml(o.name)}">${escapeHtml(o.name)}</h3>
            <p class="text-xs text-amber-600 dark:text-amber-500 mb-3 flex items-center gap-1.5">
                <i class="fas fa-clock text-xs"></i>
                ${archivedDate ? 'Archived ' + archivedDate : 'Archived'}
            </p>
            <div class="grid grid-cols-2 gap-3 mb-4">
                <div class="p-3 rounded-xl bg-gray-50/80 dark:bg-slate-700/30 border border-gray-100 dark:border-slate-700/50">
                    <div class="flex items-center gap-2 mb-1">
                        <i class="fas fa-users text-gray-300 dark:text-slate-600 text-xs"></i>
                        <span class="text-xs text-slate-400 dark:text-slate-500">Members</span>
                    </div>
                    <p class="text-lg font-bold text-slate-400 dark:text-slate-500">${o.user_count || 0}</p>
                </div>
                <div class="p-3 rounded-xl bg-gray-50/80 dark:bg-slate-700/30 border border-gray-100 dark:border-slate-700/50">
                    <div class="flex items-center gap-2 mb-1">
                        <i class="fas fa-calendar text-gray-300 dark:text-slate-600 text-xs"></i>
                        <span class="text-xs text-slate-400 dark:text-slate-500">Events</span>
                    </div>
                    <p class="text-lg font-bold text-slate-400 dark:text-slate-500">${o.event_count || 0}</p>
                </div>
            </div>
            <div class="flex gap-2">
                <button onclick="restoreOrg(${o.id}, '${o.type}')"
                        class="flex-1 py-2 rounded-xl text-xs font-semibold bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-500/20 transition-all duration-200 flex items-center justify-center gap-2">
                    <i class="fas fa-undo"></i> Restore
                </button>
                <button onclick="openDeleteModal(${o.id}, '${o.type}', 'permanent')"
                        class="flex-1 py-2 rounded-xl text-xs font-semibold bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-100 dark:hover:bg-rose-500/20 transition-all duration-200 flex items-center justify-center gap-2">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
            </div>
        </div>
    </div>`;
}

// ═══════════════════════════════════════════════════════════════
// ESCAPE HELPERS
// ═══════════════════════════════════════════════════════════════

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeJsString(text) {
    return String(text)
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/"/g, '\\u0022')
        .replace(/\r/g, '\\r')
        .replace(/\n/g, '\\n')
        .replace(/</g, '\\u003C')
        .replace(/>/g, '\\u003E')
        .replace(/&/g, '\\u0026');
}

// ═══════════════════════════════════════════════════════════════
// API HELPER
// ═══════════════════════════════════════════════════════════════

async function apiCall(formData) {
    if (!formData.has('csrf_token')) {
        formData.append('csrf_token', csrfToken);
    }

    var response = await fetch(window.location.pathname, {
        method:  'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body:    formData,
    });

    var data;
    try {
        data = await response.json();
    } catch (_) {
        throw new Error('Server returned an unexpected response (HTTP ' + response.status + ')');
    }

    if (!response.ok) {
        throw new Error(data.message || 'Server error (HTTP ' + response.status + ')');
    }

    return data;
}

// ═══════════════════════════════════════════════════════════════
// AUTO-REFRESH (AJAX — 30-second polling)
// ═══════════════════════════════════════════════════════════════

function startAutoRefresh() {
    autoRefreshTimer = setInterval(silentRefresh, 30000);
}

function stopAutoRefresh() {
    if (autoRefreshTimer) clearInterval(autoRefreshTimer);
}

function isAnyModalOpen() {
    return ['addModal', 'editModal', 'deleteModal', 'membersModal', 'eventsModal']
        .some(function (id) {
            var el = document.getElementById(id);
            return el && !el.classList.contains('hidden');
        });
}

async function silentRefresh() {
    // Skip if user is interacting with a modal or an action is in flight
    if (isProcessing || isAnyModalOpen()) return;

    try {
        var formData = new FormData();
        formData.append('action', 'refresh_data');
        var result = await apiCall(formData);
        if (result.success) {
            activeData   = result.active   || [];
            archivedData = result.archived  || [];
            updateStats();
            render();
            _setRefreshTimestamp();
        }
    } catch (_) {
        // Silent fail — do not show toast for background refresh errors
    }
}

function _setRefreshTimestamp() {
    var el = document.getElementById('last-updated');
    if (!el) return;
    el.textContent = 'Refreshed ' + new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

// ═══════════════════════════════════════════════════════════════
// LOGO PREVIEW HELPERS
// ═══════════════════════════════════════════════════════════════

function previewLogo(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('logoPreview').src = e.target.result;
            document.getElementById('logoPreview').classList.remove('hidden');
            document.getElementById('logoPlaceholder').classList.add('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function previewEditLogo(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('editLogoPreview').src = e.target.result;
            document.getElementById('editLogoPreview').classList.remove('hidden');
            document.getElementById('editLogoPlaceholder').classList.add('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// ═══════════════════════════════════════════════════════════════
// ADD MODAL
// ═══════════════════════════════════════════════════════════════

function openAddModal() {
    document.getElementById('addModal').classList.remove('hidden');
    document.getElementById('addForm').reset();
    document.getElementById('logoPreview').classList.add('hidden');
    document.getElementById('logoPlaceholder').classList.remove('hidden');
    resetButton('addSubmitBtn', 'Create');
}

function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
}

async function handleAdd(e) {
    e.preventDefault();
    if (isProcessing) return;
    var formData = new FormData(e.target);
    formData.append('action', 'create');
    setLoading('addSubmitBtn', 'Creating...');
    isProcessing = true;

    try {
        var result = await apiCall(formData);
        if (result.success) {
            activeData.unshift(result.data);
            updateStats();
            render();
            closeAddModal();
            showToast(result.message, 'success');
        } else {
            showToast(result.message || 'Failed to create', 'error');
        }
    } catch (err) {
        showToast(err.message || 'Network error. Please try again.', 'error');
    } finally {
        isProcessing = false;
        resetButton('addSubmitBtn', 'Create');
    }
}

// ═══════════════════════════════════════════════════════════════
// EDIT MODAL
// ═══════════════════════════════════════════════════════════════

function openEditModal(id, type) {
    var org = activeData.find(function (o) { return o.id == id && o.type === type; });
    if (!org) return;

    document.getElementById('editId').value   = id;
    document.getElementById('editType').value = type;
    document.getElementById('editName').value = org.name;

    var isOrg       = type === 'organization';
    var accentColor = isOrg ? 'purple' : 'sky';
    var icon        = isOrg ? 'fa-building' : 'fa-users';

    document.getElementById('editTypeDisplay').innerHTML  = '<i class="fas ' + icon + ' text-' + accentColor + '-500"></i><span class="capitalize">' + type + '</span>';
    document.getElementById('editTypeDisplay').className  = 'px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-medium capitalize flex items-center gap-2 border border-' + accentColor + '-200 dark:border-' + accentColor + '-800';

    var logoPreview     = document.getElementById('editLogoPreview');
    var logoPlaceholder = document.getElementById('editLogoPlaceholder');
    if (org.logo) {
        var logoSrc = (typeof org.logo === 'string' && org.logo.startsWith('data:'))
            ? org.logo
            : 'data:image/jpeg;base64,' + org.logo;
        logoPreview.src = logoSrc;
        logoPreview.classList.remove('hidden');
        logoPlaceholder.classList.add('hidden');
    } else {
        logoPreview.classList.add('hidden');
        logoPlaceholder.classList.remove('hidden');
    }

    document.getElementById('editModal').classList.remove('hidden');
    resetButton('editSubmitBtn', 'Save Changes');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

async function handleEdit(e) {
    e.preventDefault();
    if (isProcessing) return;
    var formData = new FormData(e.target);
    formData.append('action', 'update');
    setLoading('editSubmitBtn', 'Saving...');
    isProcessing = true;

    try {
        var result = await apiCall(formData);
        if (result.success) {
            var idx = activeData.findIndex(function (o) { return o.id == result.data.id && o.type === result.data.type; });
            if (idx !== -1) activeData[idx] = result.data;
            render();
            closeEditModal();
            showToast(result.message, 'success');
        } else {
            showToast(result.message || 'Failed to update', 'error');
        }
    } catch (err) {
        showToast(err.message || 'Network error. Please try again.', 'error');
    } finally {
        isProcessing = false;
        resetButton('editSubmitBtn', 'Save Changes');
    }
}

// ═══════════════════════════════════════════════════════════════
// DELETE / ARCHIVE MODAL  (unified — mode determines behaviour)
// ═══════════════════════════════════════════════════════════════

function openDeleteModal(id, type, mode) {
    mode = mode || 'archive';

    // Look up in the correct dataset
    var sourceData = (mode === 'permanent') ? archivedData : activeData;
    var org = sourceData.find(function (o) { return o.id == id && o.type === type; });
    if (!org) return;

    pendingDeleteId   = id;
    pendingDeleteType = type;
    pendingDeleteMode = mode;

    var iconBgEl  = document.getElementById('deleteIconBg');
    var iconEl    = document.getElementById('deleteIcon');
    var titleEl   = document.getElementById('deleteTitle');
    var descEl    = document.getElementById('deleteDesc');
    var confirmBtn = document.getElementById('confirmDeleteBtn');
    var typeName  = type === 'organization' ? 'Organization' : 'Club';

    if (mode === 'archive') {
        iconBgEl.className  = 'w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-500/10 flex items-center justify-center mx-auto mb-4';
        iconEl.className    = 'fas fa-archive text-amber-500 text-xl';
        titleEl.textContent = 'Archive ' + typeName + '?';
        descEl.innerHTML    = 'You\'re about to archive <strong class="text-slate-900 dark:text-white">"' + escapeHtml(org.name) + '"</strong>. It will be hidden from active lists but can be restored later from the <strong>Archived</strong> tab.';
        confirmBtn.className = 'px-4 py-2 rounded-xl text-sm font-semibold bg-amber-500 hover:bg-amber-600 text-white shadow-sm shadow-amber-500/30 flex items-center gap-2';
        confirmBtn.innerHTML = '<i class="fas fa-archive"></i><span>Archive</span>';
    } else {
        iconBgEl.className  = 'w-14 h-14 rounded-full bg-rose-100 dark:bg-rose-500/10 flex items-center justify-center mx-auto mb-4';
        iconEl.className    = 'fas fa-trash-alt text-rose-500 text-xl';
        titleEl.textContent = 'Permanently Delete?';
        descEl.innerHTML    = 'This will permanently delete <strong class="text-slate-900 dark:text-white">"' + escapeHtml(org.name) + '"</strong>. This action <strong>cannot be undone</strong>.';
        confirmBtn.className = 'px-4 py-2 rounded-xl text-sm font-semibold bg-rose-500 hover:bg-rose-600 text-white shadow-sm shadow-rose-500/30 flex items-center gap-2';
        confirmBtn.innerHTML = '<i class="fas fa-trash-alt"></i><span>Delete Forever</span>';
    }

    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    pendingDeleteId   = null;
    pendingDeleteType = null;
}

async function confirmDeleteAction() {
    if (!pendingDeleteId || isProcessing) return;

    var action   = (pendingDeleteMode === 'permanent') ? 'permanent_delete' : 'archive';
    var formData = new FormData();
    formData.append('action', action);
    formData.append('id',     pendingDeleteId);
    formData.append('type',   pendingDeleteType);

    var btnLabel = (pendingDeleteMode === 'permanent') ? 'Deleting...' : 'Archiving...';
    setLoading('confirmDeleteBtn', btnLabel);
    isProcessing = true;

    try {
        var result = await apiCall(formData);
        if (result.success) {
            var id   = pendingDeleteId;
            var type = pendingDeleteType;

            if (pendingDeleteMode === 'archive') {
                var idx = activeData.findIndex(function (o) { return o.id == id && o.type === type; });
                if (idx !== -1) {
                    var moved = activeData.splice(idx, 1)[0];
                    moved.deleted_at = result.deleted_at || new Date().toISOString().slice(0, 19).replace('T', ' ');
                    archivedData.unshift(moved);
                }
            } else {
                archivedData = archivedData.filter(function (o) { return !(o.id == id && o.type === type); });
            }

            updateStats();
            render();
            closeDeleteModal();
            showToast(result.message, 'success');
        } else {
            showToast(result.message || 'Operation failed', 'error');
        }
    } catch (err) {
        showToast(err.message || 'Network error. Please try again.', 'error');
    } finally {
        isProcessing = false;
        // Restore the button text to whatever mode was active
        var confirmBtn = document.getElementById('confirmDeleteBtn');
        if (confirmBtn) {
            confirmBtn.disabled = false;
            confirmBtn.classList.remove('opacity-75', 'cursor-not-allowed');
            confirmBtn.innerHTML = pendingDeleteMode === 'permanent'
                ? '<i class="fas fa-trash-alt"></i><span>Delete Forever</span>'
                : '<i class="fas fa-archive"></i><span>Archive</span>';
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// RESTORE (inline — no confirmation modal needed)
// ═══════════════════════════════════════════════════════════════

async function restoreOrg(id, type) {
    if (isProcessing) return;
    isProcessing = true;

    var formData = new FormData();
    formData.append('action', 'restore');
    formData.append('id',     id);
    formData.append('type',   type);

    try {
        var result = await apiCall(formData);
        if (result.success) {
            var idx = archivedData.findIndex(function (o) { return o.id == id && o.type === type; });
            if (idx !== -1) {
                var restored = archivedData.splice(idx, 1)[0];
                restored.deleted_at = null;
                activeData.unshift(restored);
            }
            updateStats();
            render();
            showToast(result.message || 'Restored successfully', 'success');
        } else {
            showToast(result.message || 'Failed to restore', 'error');
        }
    } catch (err) {
        showToast(err.message || 'Network error. Please try again.', 'error');
    } finally {
        isProcessing = false;
    }
}

// ═══════════════════════════════════════════════════════════════
// MEMBERS MODAL
// ═══════════════════════════════════════════════════════════════

function openMembersModal(id, type, orgName) {
    membersState = { id: id, type: type, page: 1, search: '', roleFilter: 'all', total: 0, totalPages: 1, loading: false };
    document.getElementById('membersModalTitle').textContent = orgName + ' — Members';
    document.getElementById('membersSearch').value = '';
    updateMembersFilterTabs();
    document.getElementById('membersModal').classList.remove('hidden');
    loadMembers();
}

function closeMembersModal() {
    document.getElementById('membersModal').classList.add('hidden');
    if (membersSearchTimeout) clearTimeout(membersSearchTimeout);
}

function setMembersRoleFilter(filter) {
    membersState.roleFilter = filter;
    membersState.page       = 1;
    updateMembersFilterTabs();
    loadMembers();
}

function updateMembersFilterTabs() {
    ['all', 'student', 'officer'].forEach(function (f) {
        var btn = document.getElementById('membersFilter-' + f);
        if (!btn) return;
        if (membersState.roleFilter === f) {
            btn.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold bg-white dark:bg-slate-600 text-slate-700 dark:text-slate-200 shadow-sm transition-all';
        } else {
            btn.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-all';
        }
    });
}

function debounceMembersSearch() {
    if (membersSearchTimeout) clearTimeout(membersSearchTimeout);
    membersSearchTimeout = setTimeout(function () {
        membersState.search = document.getElementById('membersSearch').value.trim();
        membersState.page   = 1;
        loadMembers();
    }, 350);
}

function changeMembersPage(delta) {
    var newPage = membersState.page + delta;
    if (newPage < 1 || newPage > membersState.totalPages) return;
    membersState.page = newPage;
    loadMembers();
}

async function loadMembers() {
    if (membersState.loading) return;
    membersState.loading = true;
    var list = document.getElementById('membersList');
    list.innerHTML = renderSkeletonList(6);

    var formData = new FormData();
    formData.append('action',      'get_members');
    formData.append('id',          membersState.id);
    formData.append('type',        membersState.type);
    formData.append('page',        membersState.page);
    formData.append('search',      membersState.search);
    formData.append('role_filter', membersState.roleFilter);

    try {
        var result = await apiCall(formData);
        if (result.success) {
            membersState.total      = result.total;
            membersState.totalPages = result.total_pages;
            renderMembersList(result.members);
            updateMembersPagination();
        } else {
            list.innerHTML = renderEmptyState('Failed to load members', 'error');
        }
    } catch (err) {
        list.innerHTML = renderEmptyState(err.message || 'Network error. Please try again.', 'error');
    } finally {
        membersState.loading = false;
    }
}

function renderMembersList(members) {
    var list = document.getElementById('membersList');
    if (members.length === 0) {
        list.innerHTML = renderEmptyState('No members found', 'search');
        return;
    }

    list.innerHTML = members.map(function (m) {
        var isOfficer      = m.display_role === 'officer';
        var initials       = ((m.first_name || '')[0] || '') + ((m.last_name || '')[0] || '');
        var avatarGradient = isOfficer ? 'from-amber-400 to-orange-500' : 'from-purple-400 to-blue-500';

        var avatar = m.profile_image
            ? '<img src="data:image/jpeg;base64,' + m.profile_image + '" class="w-12 h-12 rounded-full object-cover border-2 border-gray-200 dark:border-slate-600 flex-shrink-0" alt="">'
            : '<div class="w-12 h-12 rounded-full bg-gradient-to-br ' + avatarGradient + ' flex items-center justify-center text-white text-sm font-bold flex-shrink-0">' + escapeHtml(initials.toUpperCase() || '?') + '</div>';

        if (isOfficer) {
            var position = m.position || 'Officer';
            return `
            <div class="list-item-hover flex items-center gap-4 p-3 rounded-xl mb-1">
                ${avatar}
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold text-slate-900 dark:text-white truncate">${escapeHtml(m.full_name)}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5">
                        <i class="fas fa-envelope text-[10px] mr-1 text-slate-400"></i>${escapeHtml(m.email)}
                    </p>
                    <span class="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 rounded-md text-xs font-semibold
                                 bg-amber-100 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400
                                 border border-amber-200 dark:border-amber-800">
                        <i class="fas fa-star text-[10px]"></i> ${escapeHtml(position)}
                    </span>
                </div>
            </div>`;
        } else {
            var studentNum = m.student_number
                ? '<p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 flex items-center gap-1"><i class="fas fa-id-card text-[10px] text-blue-400"></i> ' + escapeHtml(m.student_number) + '</p>'
                : '<p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 italic">No student number on file</p>';

            return `
            <div class="list-item-hover flex items-center gap-4 p-3 rounded-xl mb-1">
                ${avatar}
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold text-slate-900 dark:text-white truncate">${escapeHtml(m.full_name)}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5">
                        <i class="fas fa-envelope text-[10px] mr-1 text-slate-400"></i>${escapeHtml(m.email)}
                    </p>
                    ${studentNum}
                </div>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-semibold
                             bg-blue-100 dark:bg-blue-500/10 text-blue-700 dark:text-blue-400
                             border border-blue-200 dark:border-blue-800 whitespace-nowrap flex-shrink-0">
                    <i class="fas fa-graduation-cap text-[10px]"></i> Student
                </span>
            </div>`;
        }
    }).join('');
}

function updateMembersPagination() {
    document.getElementById('membersModalCount').textContent = membersState.total;
    document.getElementById('membersPageInfo').textContent   = 'Page ' + membersState.page + ' of ' + (membersState.totalPages || 1);
    document.getElementById('membersPrevBtn').disabled       = membersState.page <= 1;
    document.getElementById('membersNextBtn').disabled       = membersState.page >= membersState.totalPages;
}

// ═══════════════════════════════════════════════════════════════
// EVENTS MODAL
// ═══════════════════════════════════════════════════════════════

function openEventsModal(id, type, orgName) {
    eventsState = { id: id, type: type, page: 1, search: '', total: 0, totalPages: 1, loading: false };
    document.getElementById('eventsModalTitle').textContent = orgName + ' — Events';
    document.getElementById('eventsSearch').value = '';
    document.getElementById('eventsModal').classList.remove('hidden');
    loadEvents();
}

function closeEventsModal() {
    document.getElementById('eventsModal').classList.add('hidden');
    if (eventsSearchTimeout) clearTimeout(eventsSearchTimeout);
}

function debounceEventsSearch() {
    if (eventsSearchTimeout) clearTimeout(eventsSearchTimeout);
    eventsSearchTimeout = setTimeout(function () {
        eventsState.search = document.getElementById('eventsSearch').value.trim();
        eventsState.page   = 1;
        loadEvents();
    }, 350);
}

function changeEventsPage(delta) {
    var newPage = eventsState.page + delta;
    if (newPage < 1 || newPage > eventsState.totalPages) return;
    eventsState.page = newPage;
    loadEvents();
}

async function loadEvents() {
    if (eventsState.loading) return;
    eventsState.loading = true;
    var list = document.getElementById('eventsList');
    list.innerHTML = renderSkeletonList(5);

    var formData = new FormData();
    formData.append('action', 'get_events');
    formData.append('id',     eventsState.id);
    formData.append('type',   eventsState.type);
    formData.append('page',   eventsState.page);
    formData.append('search', eventsState.search);

    try {
        var result = await apiCall(formData);
        if (result.success) {
            eventsState.total      = result.total;
            eventsState.totalPages = result.total_pages;
            renderEventsList(result.events);
            updateEventsPagination();
        } else {
            list.innerHTML = renderEmptyState('Failed to load events', 'error');
        }
    } catch (err) {
        list.innerHTML = renderEmptyState(err.message || 'Network error. Please try again.', 'error');
    } finally {
        eventsState.loading = false;
    }
}

function renderEventsList(events) {
    var list = document.getElementById('eventsList');
    if (events.length === 0) {
        list.innerHTML = renderEmptyState('No events found', 'search');
        return;
    }

    var statusColors = {
        pending:  'bg-amber-100 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-800',
        approved: 'bg-emerald-100 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800',
        rejected: 'bg-rose-100 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-200 dark:border-rose-800',
    };

    list.innerHTML = events.map(function (e) {
        var startDate = e.start_datetime ? new Date(e.start_datetime) : null;
        var dateStr   = startDate ? startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'TBD';
        var timeStr   = startDate ? startDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '';
        var colorCls  = statusColors[e.status] || statusColors.pending;

        return `
        <div class="list-item-hover p-4 rounded-xl mb-2 border border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-700/20">
            <div class="flex items-start justify-between gap-3 mb-2">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold text-slate-900 dark:text-white truncate mb-1">${escapeHtml(e.title)}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 line-clamp-2">${escapeHtml(e.description || 'No description')}</p>
                </div>
                <span class="px-2 py-1 rounded-lg text-xs font-semibold border ${colorCls} whitespace-nowrap capitalize">${e.status}</span>
            </div>
            <div class="flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400 mt-2 flex-wrap">
                <span class="flex items-center gap-1.5"><i class="far fa-calendar text-sky-500"></i> ${dateStr}</span>
                ${timeStr ? '<span class="flex items-center gap-1.5"><i class="far fa-clock text-sky-500"></i> ' + timeStr + '</span>' : ''}
                ${e.venue_name ? '<span class="flex items-center gap-1.5"><i class="fas fa-map-marker-alt text-sky-500"></i> ' + escapeHtml(e.venue_name) + '</span>' : ''}
                <span class="flex items-center gap-1.5 ml-auto"><i class="fas fa-user-check text-emerald-500"></i> ${e.registration_count || 0} regs</span>
            </div>
        </div>`;
    }).join('');
}

function updateEventsPagination() {
    document.getElementById('eventsModalCount').textContent = eventsState.total;
    document.getElementById('eventsPageInfo').textContent   = 'Page ' + eventsState.page + ' of ' + (eventsState.totalPages || 1);
    document.getElementById('eventsPrevBtn').disabled       = eventsState.page <= 1;
    document.getElementById('eventsNextBtn').disabled       = eventsState.page >= eventsState.totalPages;
}

// ═══════════════════════════════════════════════════════════════
// SHARED UI HELPERS
// ═══════════════════════════════════════════════════════════════

function renderSkeletonList(count) {
    return Array(count).fill(0).map(function () {
        return `
        <div class="flex items-center gap-3 p-3 rounded-xl mb-1 animate-pulse">
            <div class="w-12 h-12 rounded-full skeleton flex-shrink-0"></div>
            <div class="flex-1 space-y-2">
                <div class="h-3.5 rounded skeleton w-3/4"></div>
                <div class="h-3 rounded skeleton w-1/2"></div>
                <div class="h-3 rounded skeleton w-1/3"></div>
            </div>
        </div>`;
    }).join('');
}

function renderEmptyState(message, iconType) {
    iconType = iconType || 'search';
    var icons  = { search: 'fa-search', error: 'fa-exclamation-circle' };
    var colors = { search: 'text-gray-400', error: 'text-rose-400' };
    return `
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <div class="w-14 h-14 bg-gray-100 dark:bg-slate-700 rounded-full flex items-center justify-center mb-3">
                <i class="fas ${icons[iconType] || icons.search} ${colors[iconType] || colors.search} text-xl"></i>
            </div>
            <p class="text-sm font-medium text-slate-600 dark:text-slate-300">${message}</p>
        </div>`;
}

// ═══════════════════════════════════════════════════════════════
// BUTTON HELPERS
// ═══════════════════════════════════════════════════════════════

function setLoading(btnId, text) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled   = true;
    btn.innerHTML  = '<div class="spinner mr-2"></div><span>' + text + '</span>';
    btn.classList.add('opacity-75', 'cursor-not-allowed');
}

function resetButton(btnId, text) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled  = false;
    btn.innerHTML = '<span>' + text + '</span>';
    btn.classList.remove('opacity-75', 'cursor-not-allowed');
}

// ═══════════════════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════════════════

function showToast(message, type) {
    type = type || 'success';
    var toast   = document.getElementById('toast');
    var content = document.getElementById('toastContent');

    var colors = {
        success: 'bg-emerald-500 shadow-emerald-500/30',
        error:   'bg-rose-500 shadow-rose-500/30',
        info:    'bg-primary-500 shadow-primary-500/30',
    };
    var icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };

    content.className = 'flex items-center gap-2.5 px-4 py-3 rounded-xl shadow-lg text-white text-sm font-medium ' + (colors[type] || colors.success);
    content.innerHTML = '<i class="fas ' + (icons[type] || icons.success) + '"></i><span>' + message + '</span>';

    toast.classList.remove('hidden');
    setTimeout(function () { toast.classList.remove('translate-x-full'); }, 50);
    setTimeout(function () {
        toast.classList.add('translate-x-full');
        setTimeout(function () { toast.classList.add('hidden'); }, 300);
    }, 3200);
}

// ═══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════════════════

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeAddModal();
        closeEditModal();
        closeDeleteModal();
        closeMembersModal();
        closeEventsModal();
    }
});