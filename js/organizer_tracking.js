/**
 * SEMS Organizer — organizer_tracking.js
 * Handles: Theme, Sidebar, Search/Filter,
 *          Copy QR, CSV Export, Delete Modal,
 *          Keyboard shortcuts
 *
 * Requires SEMS_TRACKING_DATA to be defined inline before this script:
 *   <script>
 *     const SEMS_TRACKING_DATA = {
 *         exportDate: "<?= date('Y-m-d') ?>",
 *     };
 *   </script>
 */

// ═══════════════════════════════════════════════════════════════
// DATA (from bridge)
// ═══════════════════════════════════════════════════════════════

var _d         = (typeof SEMS_TRACKING_DATA !== 'undefined') ? SEMS_TRACKING_DATA : {};
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
    if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme:dark)').matches)) applyTheme(true);
})();

// ═══════════════════════════════════════════════════════════════
// SIDEBAR
// ═══════════════════════════════════════════════════════════════

var sidebar   = document.getElementById('sidebar');
var sbOverlay = document.getElementById('sb-overlay');

function openSidebar()  { sidebar.classList.remove('-translate-x-full'); sbOverlay.classList.add('show'); }
function closeSidebar() { sidebar.classList.add('-translate-x-full');    sbOverlay.classList.remove('show'); }

// ═══════════════════════════════════════════════════════════════
// SEARCH + FILTER
// ═══════════════════════════════════════════════════════════════

function filterTable() {
    var q      = (document.getElementById('pageSearch').value   || '').toUpperCase().trim();
    var status = (document.getElementById('statusFilter').value || '').toLowerCase();
    var rows   = document.querySelectorAll('#regTable tbody tr');
    var shown  = 0;

    rows.forEach(function (row) {
        var cells = row.cells;
        if (!cells || cells.length < 6) return;
        var event   = (cells[0] ? cells[0].textContent : '').toUpperCase();
        var student = (cells[1] ? cells[1].textContent : '').toUpperCase();
        var email   = (cells[2] ? cells[2].textContent : '').toUpperCase();
        var qrVal   = (cells[3] ? cells[3].textContent : '').toUpperCase();
        var rowStat = (row.dataset.status || '').toLowerCase();
        var matchQ  = !q || event.includes(q) || student.includes(q) || email.includes(q) || qrVal.includes(q);
        var matchS  = !status || rowStat === status;
        var vis     = matchQ && matchS;
        row.style.display = vis ? '' : 'none';
        if (vis) shown++;
    });

    var rc = document.getElementById('rowCount');
    if (rc) rc.textContent = 'Showing ' + shown + ' record' + (shown !== 1 ? 's' : '');
}

// ═══════════════════════════════════════════════════════════════
// COPY QR VALUE
// ═══════════════════════════════════════════════════════════════

function copyQR(btn, value) {
    var toast = document.getElementById('copyToast');

    function showToast() {
        toast.style.opacity   = '1';
        toast.style.transform = 'translateX(-50%) translateY(-4px)';
        setTimeout(function () {
            toast.style.opacity   = '0';
            toast.style.transform = 'translateX(-50%) translateY(0)';
        }, 2000);
        btn.innerHTML = '<i class="fas fa-check text-[10px] text-brand-500"></i>';
        setTimeout(function () {
            btn.innerHTML = '<i class="fas fa-copy text-[10px]"></i>';
        }, 1500);
    }

    if (navigator.clipboard) {
        navigator.clipboard.writeText(value).then(showToast).catch(fallbackCopy);
    } else {
        fallbackCopy();
    }

    function fallbackCopy() {
        var ta = document.createElement('textarea');
        ta.value = value;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast();
    }
}

// ═══════════════════════════════════════════════════════════════
// CSV EXPORT
// ═══════════════════════════════════════════════════════════════

function exportToCSV() {
    var rows  = document.querySelectorAll('#regTable tbody tr');
    var lines = [['Event', 'Student Name', 'Student Number', 'Email', 'QR Value', 'Registered On', 'Status']];

    rows.forEach(function (row) {
        if (row.style.display === 'none' || row.cells.length < 7) return;
        var event    = row.cells[0].innerText.trim().replace(/\n.*/, '');
        var stuParts = row.cells[1].innerText.trim().split('\n');
        var student  = (stuParts[0] || '').trim();
        var stuNum   = (stuParts[1] || '').trim();
        var email    = row.cells[2].innerText.trim();
        var qrVal    = row.cells[3].innerText.trim().replace(/\s+/g, ' ');
        var date     = row.cells[4].innerText.trim().replace(/\n/g, ' ');
        var rowStatus = row.dataset.status || row.cells[5].innerText.trim();
        lines.push([event, student, stuNum, email, qrVal, date, rowStatus]);
    });

    var csv  = lines.map(function (r) {
        return r.map(function (f) { return '"' + String(f).replace(/"/g, '""') + '"'; }).join(',');
    }).join('\n');

    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = 'registrations_' + exportDate + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
}

// ═══════════════════════════════════════════════════════════════
// DELETE MODAL
// ═══════════════════════════════════════════════════════════════

var currentDeleteForm = null;
var deleteModal       = null;

document.addEventListener('DOMContentLoaded', function () {
    deleteModal = document.getElementById('deleteModal');

    document.querySelectorAll('.delete-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = this.closest('.delete-form');
            currentDeleteForm = form;
            document.getElementById('modalStudentName').textContent = form.dataset.studentName;
            document.getElementById('modalEventTitle').textContent  = form.dataset.eventTitle;
            deleteModal.classList.remove('hidden');
            deleteModal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        });
    });

    document.getElementById('confirmDeleteBtn').addEventListener('click', function () {
        if (!currentDeleteForm) return;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing\u2026';
        this.disabled  = true;
        if (!currentDeleteForm.querySelector('input[name="cancel_reg"]')) {
            var h   = document.createElement('input');
            h.type  = 'hidden';
            h.name  = 'cancel_reg';
            h.value = '1';
            currentDeleteForm.appendChild(h);
        }
        currentDeleteForm.submit();
    });
});

function closeDeleteModal() {
    deleteModal.classList.add('hidden');
    deleteModal.classList.remove('flex');
    document.body.style.overflow = '';
    currentDeleteForm = null;
}

// ═══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════════════════

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && deleteModal && !deleteModal.classList.contains('hidden')) {
        closeDeleteModal();
    }
});