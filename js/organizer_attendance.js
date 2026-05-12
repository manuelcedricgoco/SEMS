/**
 * SEMS Organizer — organizer_attendance.js
 * Handles: Theme, Sidebar, Search/Filter, CSV Export,
 *          Details Modal, Keyboard shortcuts
 *
 * Requires SEMS_ATTENDANCE_DATA to be defined inline before this script:
 *   <script>
 *     const SEMS_ATTENDANCE_DATA = {
 *         exportDate: "<?= date('Y-m-d') ?>",
 *     };
 *   </script>
 */

// ═══════════════════════════════════════════════════════════════
// DATA (from bridge)
// ═══════════════════════════════════════════════════════════════

var _d         = (typeof SEMS_ATTENDANCE_DATA !== 'undefined') ? SEMS_ATTENDANCE_DATA : {};
var exportDate = _d.exportDate || new Date().toISOString().slice(0, 10);

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
// SEARCH + FILTER
// ═══════════════════════════════════════════════════════════════

function syncSearch(val) {
    var h = document.getElementById('headerSearch');
    var p = document.getElementById('pageSearch');
    if (h) h.value = val;
    if (p) p.value = val;
    filterAttendance();
}

function filterAttendance() {
    var q      = ((document.getElementById('pageSearch')   || {}).value || '').toUpperCase().trim();
    var status = ((document.getElementById('statusFilter') || {}).value || '').toUpperCase();
    var rows   = document.querySelectorAll('#attendanceTable tbody tr');
    var present = 0, absent = 0, shown = 0;

    rows.forEach(function (row) {
        if (!row.cells || row.cells.length < 6) return;
        var event   = (row.cells[0] ? row.cells[0].textContent : '').toUpperCase();
        var student = (row.cells[1] ? row.cells[1].textContent : '').toUpperCase();
        var rowStat = (row.dataset.status || '').toUpperCase();
        var matchQ  = !q      || event.includes(q) || student.includes(q);
        var matchS  = !status || rowStat === status;
        var vis     = matchQ && matchS;
        row.style.display = vis ? '' : 'none';
        if (vis) {
            shown++;
            rowStat === 'PRESENT' ? present++ : absent++;
        }
    });

    var pc = document.getElementById('presentCount');
    var ac = document.getElementById('absentCount');
    var rc = document.getElementById('rowCount');
    if (pc) pc.textContent = present;
    if (ac) ac.textContent = absent;
    if (rc) rc.textContent = 'Showing ' + shown + ' record' + (shown !== 1 ? 's' : '');
}

// ═══════════════════════════════════════════════════════════════
// CSV EXPORT
// ═══════════════════════════════════════════════════════════════

function exportAttendanceCSV() {
    var rows  = document.querySelectorAll('#attendanceTable tbody tr');
    var lines = [['Event', 'Student Name', 'Student No.', 'Log In', 'Log Out', 'Status', 'Duration']];

    rows.forEach(function (row) {
        if (row.style.display !== 'none' && row.cells.length > 1) {
            lines.push([
                row.cells[0].innerText.trim(),
                row.cells[1].innerText.trim().split('\n')[0],
                row.cells[2] ? row.cells[2].innerText.trim() : '',
                row.cells[3].innerText.trim().replace(/\n/g, ' '),
                row.cells[4].innerText.trim().replace(/\n/g, ' '),
                row.cells[5].innerText.trim(),
                row.dataset.duration || 'N/A'
            ]);
        }
    });

    var csv  = lines.map(function (r) {
        return r.map(function (f) { return '"' + String(f).replace(/"/g, '""') + '"'; }).join(',');
    }).join('\n');

    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = 'attendance_report_' + exportDate + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
}

// ═══════════════════════════════════════════════════════════════
// DETAILS MODAL
// ═══════════════════════════════════════════════════════════════

var detailsModal = null;

document.addEventListener('DOMContentLoaded', function () {
    detailsModal = document.getElementById('detailsModal');
});

function openDetailsModal(btn) {
    var row       = btn.closest('tr');
    var isPresent = row.dataset.status === 'PRESENT';
    var name      = row.dataset.studentName;
    var imgSrc    = row.dataset.studentImg || '';

    document.getElementById('modalEventTitle').textContent  = row.dataset.eventTitle;
    document.getElementById('modalStudentName').textContent = name;
    document.getElementById('modalLoginTime').textContent   = row.dataset.loginTime;
    document.getElementById('modalLogoutTime').textContent  = row.dataset.logoutTime;
    document.getElementById('modalDuration').textContent    = row.dataset.duration;

    var photoEl   = document.getElementById('modalStudentPhoto');
    var initialEl = document.getElementById('modalStudentInitial');

    if (imgSrc) {
        photoEl.src = imgSrc;
        photoEl.classList.remove('hidden');
        initialEl.classList.add('hidden');
    } else {
        photoEl.classList.add('hidden');
        initialEl.classList.remove('hidden');
        initialEl.textContent = row.dataset.studentInitial || name.charAt(0).toUpperCase();
    }

    var badge = document.getElementById('modalStatusBadge');
    badge.innerHTML = isPresent
        ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 border border-brand-200 dark:border-brand-800"><i class="fas fa-check-circle text-[9px]"></i> Present</span>'
        : '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800"><i class="fas fa-times-circle text-[9px]"></i> Absent</span>';

    detailsModal.classList.remove('hidden');
    detailsModal.classList.add('flex');
    document.body.style.overflow = 'hidden';
}

function closeDetailsModal() {
    detailsModal.classList.add('hidden');
    detailsModal.classList.remove('flex');
    document.body.style.overflow = '';
}

// ═══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════════════════

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && detailsModal && !detailsModal.classList.contains('hidden')) {
        closeDetailsModal();
    }
});