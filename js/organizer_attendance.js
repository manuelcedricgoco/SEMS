/**
 * SEMS Organizer — organizer_attendance.js  (Enhanced)
 * ──────────────────────────────────────────────────────
 * Changes from original:
 *  • ALL browser confirm() calls removed — replaced with
 *    custom styled modals (archive / restore / delete)
 *  • Archived rows show Restore + Delete buttons
 *  • Delete permanently removes the record (new POST action)
 *  • Modal open/close helpers for all three confirm modals
 *  • Keyboard Esc closes the front-most open modal
 *
 * Requires SEMS_ATTENDANCE_DATA defined inline before this script.
 */

// ═══════════════════════════════════════════════════════════════
// DATA BRIDGE
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
// SHOW-ARCHIVED TOGGLE (custom checkbox visual)
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    var cb    = document.getElementById('showArchived');
    var track = document.getElementById('toggleTrack');
    var dot   = document.getElementById('toggleDot');
    if (!cb) return;

    cb.addEventListener('change', function () {
        if (cb.checked) {
            track.style.background = '#f59e0b';
            dot.style.transform    = 'translateX(1rem)';
        } else {
            track.style.background = '';
            dot.style.transform    = '';
        }
    });
});


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
    var q            = ((document.getElementById('pageSearch')   || {}).value || '').toUpperCase().trim();
    var status       = ((document.getElementById('statusFilter') || {}).value || '').toUpperCase();
    var showArchived = document.getElementById('showArchived') && document.getElementById('showArchived').checked;
    var rows         = document.querySelectorAll('#attendanceTable tbody tr');
    var present = 0, absent = 0, shown = 0;

    rows.forEach(function (row) {
        if (!row.cells || row.cells.length < 6) return;

        var isArchived = row.dataset.archived === '1';
        if (isArchived && !showArchived) { row.style.display = 'none'; return; }

        var event   = (row.cells[0] ? row.cells[0].textContent : '').toUpperCase();
        var student = (row.cells[1] ? row.cells[1].textContent : '').toUpperCase();
        var rowStat = (row.dataset.status || '').toUpperCase();
        var matchQ  = !q      || event.includes(q) || student.includes(q);
        var matchS  = !status || rowStat === status;
        var vis     = matchQ && matchS;

        row.style.display = vis ? '' : 'none';
        if (vis && !isArchived) {
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
    var lines = [['Event', 'Student Name', 'Student No.', 'Log In', 'Log Out', 'Status', 'Duration', 'Has Proof', 'Archived']];

    rows.forEach(function (row) {
        if (row.style.display === 'none' || row.cells.length < 2) return;
        lines.push([
            row.cells[0].innerText.trim().split('\n')[0],
            row.cells[1].innerText.trim().split('\n')[0],
            row.cells[2] ? row.cells[2].innerText.trim() : '',
            row.cells[3].innerText.trim().replace(/\n/g, ' '),
            row.cells[4].innerText.trim().replace(/\n/g, ' '),
            row.cells[5].innerText.trim(),
            row.dataset.duration || 'N/A',
            row.dataset.hasProof === '1' ? 'Yes' : 'No',
            row.dataset.archived === '1' ? 'Yes' : 'No'
        ]);
    });

    var csv = lines.map(function (r) {
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
// PROOF IMAGE LIGHTBOX
// ═══════════════════════════════════════════════════════════════

var proofLightbox      = null;
var proofSpinner       = null;
var proofLightboxInner = null;
var proofLightboxImg   = null;

document.addEventListener('DOMContentLoaded', function () {
    proofLightbox      = document.getElementById('proofLightbox');
    proofSpinner       = document.getElementById('proofSpinner');
    proofLightboxInner = document.getElementById('proofLightboxInner');
    proofLightboxImg   = document.getElementById('proofLightboxImg');
});

function openProofLightbox(attId, thumbEl) {
    if (!proofLightbox) return;
    proofLightbox.classList.remove('hidden');
    proofLightbox.classList.add('flex');
    document.body.style.overflow = 'hidden';

    if (thumbEl.dataset.loaded === '1' && thumbEl.src && !thumbEl.src.endsWith('proof-placeholder.png')) {
        _showLightboxImage(thumbEl.src);
        return;
    }

    proofSpinner.classList.remove('hidden');
    proofSpinner.classList.add('flex');
    proofLightboxInner.classList.add('hidden');
    proofLightboxInner.classList.remove('flex');

    fetch('?action=get_proof&id=' + attId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                thumbEl.src = data.src;
                thumbEl.dataset.loaded = '1';
                _showLightboxImage(data.src);
            } else {
                closeProofLightbox();
                _showToast('No proof image available for this record.', 'error');
            }
        })
        .catch(function () {
            closeProofLightbox();
            _showToast('Failed to load proof image. Please try again.', 'error');
        });
}

function _showLightboxImage(src) {
    proofLightboxImg.src = src;
    proofSpinner.classList.add('hidden');
    proofSpinner.classList.remove('flex');
    proofLightboxInner.classList.remove('hidden');
    proofLightboxInner.classList.add('flex');
    proofLightboxInner.style.flexDirection = 'column';
    proofLightboxInner.style.alignItems    = 'center';
}

function closeProofLightbox() {
    if (!proofLightbox) return;
    proofLightbox.classList.add('hidden');
    proofLightbox.classList.remove('flex');
    document.body.style.overflow = '';
}


// ═══════════════════════════════════════════════════════════════
// DETAILS + EDIT MODAL
// ═══════════════════════════════════════════════════════════════

var detailsModal   = null;
var _currentRow    = null;
var _pendingStatus = null;

document.addEventListener('DOMContentLoaded', function () {
    detailsModal = document.getElementById('detailsModal');
});

function openDetailsModal(btn) {
    var row        = btn.closest('tr');
    _currentRow    = row;
    _pendingStatus = null;

    var isPresent = row.dataset.status === 'PRESENT';
    var name      = row.dataset.studentName || '—';
    var imgSrc    = row.dataset.studentImg  || '';

    document.getElementById('modalEventTitle').textContent  = row.dataset.eventTitle || '—';
    document.getElementById('modalStudentName').textContent = name;
    document.getElementById('modalLoginTime').textContent   = row.dataset.loginTime   || '—';
    document.getElementById('modalLogoutTime').textContent  = row.dataset.logoutTime  || '—';
    document.getElementById('modalDuration').textContent    = row.dataset.duration    || 'N/A';

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

    var proofImg     = document.getElementById('modalProofImg');
    var proofSpin    = document.getElementById('modalProofSpinner');
    var noProof      = document.getElementById('modalNoProof');
    var fraudWarning = document.getElementById('fraudWarning');
    var hasProof     = row.dataset.hasProof === '1';

    proofImg.classList.add('hidden');
    proofSpin.classList.remove('hidden');
    noProof.classList.add('hidden');
    if (fraudWarning) { fraudWarning.classList.add('hidden'); fraudWarning.classList.remove('flex'); }

    if (hasProof) {
        var thumbEl = row.querySelector('.proof-thumb');
        var cached  = (thumbEl && thumbEl.dataset.loaded === '1' && thumbEl.src && !thumbEl.src.endsWith('proof-placeholder.png'))
                       ? thumbEl.src : null;
        if (cached) {
            proofImg.src = cached;
            proofImg.classList.remove('hidden');
            proofSpin.classList.add('hidden');
        } else {
            var aid = _getAttId(row);
            if (aid) {
                fetch('?action=get_proof&id=' + aid)
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            proofImg.src = data.src;
                            proofImg.classList.remove('hidden');
                            proofSpin.classList.add('hidden');
                            if (thumbEl) { thumbEl.src = data.src; thumbEl.dataset.loaded = '1'; }
                        } else {
                            proofSpin.classList.add('hidden');
                            noProof.classList.remove('hidden');
                        }
                    })
                    .catch(function () { proofSpin.classList.add('hidden'); noProof.classList.remove('hidden'); });
            } else {
                proofSpin.classList.add('hidden'); noProof.classList.remove('hidden');
            }
        }
    } else {
        proofSpin.classList.add('hidden');
        noProof.classList.remove('hidden');
    }

    _updateStatusBtns(isPresent ? 'present' : 'absent', false);
    var msg = document.getElementById('editStatusMsg');
    if (msg) { msg.classList.add('hidden'); msg.textContent = ''; }

    detailsModal.classList.remove('hidden');
    detailsModal.classList.add('flex');
    document.body.style.overflow = 'hidden';
}

function closeDetailsModal() {
    detailsModal.classList.add('hidden');
    detailsModal.classList.remove('flex');
    document.body.style.overflow = '';
    _currentRow    = null;
    _pendingStatus = null;
}

function _getAttId(row) {
    return parseInt(row.getAttribute('data-att-id') || 0, 10);
}


// ═══════════════════════════════════════════════════════════════
// EDIT STATUS
// ═══════════════════════════════════════════════════════════════

function selectStatus(s) {
    _pendingStatus = s;
    _updateStatusBtns(s, true);
}

function _updateStatusBtns(s, isPending) {
    var bp = document.getElementById('btnSetPresent');
    var ba = document.getElementById('btnSetAbsent');
    if (!bp || !ba) return;
    bp.className = 'status-btn' + (s === 'present' ? ' sel-present' : '');
    ba.className = 'status-btn' + (s === 'absent'  ? ' sel-absent'  : '');
}

function saveEditedStatus() {
    if (!_pendingStatus || !_currentRow) return;

    var attId = _getAttId(_currentRow);
    if (!attId) { _showEditMsg('Could not identify record.', 'error'); return; }

    var btn = document.getElementById('btnSaveStatus');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Saving…';

    var fd = new FormData();
    fd.append('action',        'edit_status');
    fd.append('attendance_id', attId);
    fd.append('new_status',    _pendingStatus);

    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                _currentRow.dataset.status     = data.status;
                _currentRow.dataset.duration   = data.duration;
                _currentRow.dataset.loginTime  = data.login_fmt;
                _currentRow.dataset.logoutTime = data.logout_fmt;

                var pill = _currentRow.querySelector('.att-status-pill');
                if (pill) {
                    var isP = data.status === 'PRESENT';
                    pill.className = 'att-status-pill inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border uppercase '
                        + (isP ? 'bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400 border-brand-200 dark:border-brand-800'
                               : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800');
                    pill.innerHTML = '<i class="fas ' + (isP ? 'fa-check-circle' : 'fa-times-circle') + ' text-[10px]"></i> ' + data.status;
                }

                var cells = _currentRow.cells;
                if (cells[3]) {
                    cells[3].innerHTML = data.login_date
                        ? '<p class="text-gray-700 dark:text-gray-300 text-xs">' + data.login_date + '</p><p class="text-brand-500 dark:text-brand-400 text-[11px] font-semibold">' + data.login_time + '</p>'
                        : '<span class="text-gray-300 dark:text-gray-600 text-xs">—</span>';
                }
                if (cells[4]) {
                    cells[4].innerHTML = data.logout_date
                        ? '<p class="text-gray-700 dark:text-gray-300 text-xs">' + data.logout_date + '</p><p class="text-amber-500 dark:text-amber-400 text-[11px] font-semibold">' + data['logout_time_'] + '</p>'
                        : '<span class="text-gray-400 text-xs italic">Not logged out</span>';
                }

                document.getElementById('modalLoginTime').textContent  = data.login_fmt;
                document.getElementById('modalLogoutTime').textContent = data.logout_fmt;
                document.getElementById('modalDuration').textContent   = data.duration;

                var bdg = document.getElementById('modalStatusBadge');
                var isPresent2 = data.status === 'PRESENT';
                bdg.innerHTML = isPresent2
                    ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 border border-brand-200 dark:border-brand-800"><i class="fas fa-check-circle text-[9px]"></i> Present</span>'
                    : '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800"><i class="fas fa-times-circle text-[9px]"></i> Absent</span>';

                _showEditMsg('Status updated successfully.', 'success');
                filterAttendance();
            } else {
                _showEditMsg(data.error || 'Failed to update.', 'error');
            }
        })
        .catch(function () { _showEditMsg('Network error. Please retry.', 'error'); })
        .finally(function () {
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save Status';
        });
}

function _showEditMsg(text, type) {
    var msg = document.getElementById('editStatusMsg');
    if (!msg) return;
    msg.textContent = text;
    msg.className   = 'text-xs text-center ' + (type === 'success' ? 'text-brand-600 dark:text-brand-400' : 'text-red-500');
    msg.classList.remove('hidden');
    setTimeout(function () { msg.classList.add('hidden'); }, 3500);
}


// ═══════════════════════════════════════════════════════════════
// CUSTOM CONFIRMATION MODALS — SHARED STATE
// ═══════════════════════════════════════════════════════════════

var _pendingActionRow = null;   // TR the modal was triggered from
var _pendingActionBtn = null;   // button that triggered it

/* ── helper: lock/unlock body scroll ── */
function _lockScroll()   { document.body.style.overflow = 'hidden'; }
function _unlockScroll() { document.body.style.overflow = ''; }

/* ── helper: show/hide any modal el ── */
function _showModal(el)  { el.classList.remove('hidden'); el.classList.add('flex'); _lockScroll(); }
function _hideModal(el)  { el.classList.add('hidden'); el.classList.remove('flex'); _unlockScroll(); }

/* ── helper: set btn loading state ── */
function _btnLoading(btn, loading, originalHtml) {
    if (loading) {
        btn.disabled  = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i> Processing…';
    } else {
        btn.disabled  = false;
        btn.innerHTML = originalHtml;
    }
}


// ═══════════════════════════════════════════════════════════════
// ARCHIVE MODAL
// ═══════════════════════════════════════════════════════════════

var _archiveModal = null;
document.addEventListener('DOMContentLoaded', function () {
    _archiveModal = document.getElementById('archiveModal');
});

function openArchiveModal(btn) {
    var row = btn.closest('tr');
    _pendingActionRow = row;
    _pendingActionBtn = btn;

    // Populate info
    document.getElementById('archiveModalStudent').textContent = row.dataset.studentName || '—';
    document.getElementById('archiveModalEvent').textContent   = row.dataset.eventTitle  || '—';

    // Reset confirm button
    var confirmBtn = document.getElementById('archiveModalConfirmBtn');
    confirmBtn.disabled  = false;
    confirmBtn.innerHTML = '<i class="fas fa-box-archive"></i> Archive';

    _showModal(_archiveModal);
}

function closeArchiveModal() {
    if (_archiveModal) _hideModal(_archiveModal);
    _pendingActionRow = null;
    _pendingActionBtn = null;
}

function confirmArchive() {
    if (!_pendingActionRow) return;
    var row   = _pendingActionRow;
    var btn   = _pendingActionBtn;
    var attId = _getAttId(row);
    if (!attId) { closeArchiveModal(); return; }

    var confirmBtn = document.getElementById('archiveModalConfirmBtn');
    _btnLoading(confirmBtn, true);

    _postAction({ action: 'archive', attendance_id: attId }, function (data) {
        if (data.success) {
            closeArchiveModal();

            // Mark row as archived
            row.dataset.archived = '1';
            row.classList.add('att-archived');

            // Add archived badge to event cell
            var evCell = row.cells[0];
            if (evCell && !evCell.querySelector('.archived-badge')) {
                var badge = document.createElement('span');
                badge.className = 'archived-badge block text-[10px] text-amber-500 font-semibold mt-0.5';
                badge.innerHTML = '<i class="fas fa-box-archive mr-1"></i>Archived';
                evCell.appendChild(badge);
            }

            // Replace action buttons: remove Archive btn → add Restore + Delete
            var actionsCell = row.cells[row.cells.length - 1];
            if (actionsCell) {
                var wrap = actionsCell.querySelector('.flex');
                if (wrap) {
                    // Remove archive button
                    var archiveBtn = wrap.querySelector('[title="Archive record"]');
                    if (archiveBtn) archiveBtn.remove();

                    // Add Restore button
                    var restoreBtn = document.createElement('button');
                    restoreBtn.title     = 'Restore record';
                    restoreBtn.className = 'w-8 h-8 rounded-lg flex items-center justify-center bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 border border-brand-200 dark:border-brand-800 hover:bg-brand-500 hover:text-white hover:border-brand-500 transition-all active:scale-95';
                    restoreBtn.innerHTML = '<i class="fas fa-rotate-left text-xs"></i>';
                    restoreBtn.setAttribute('onclick', 'openRestoreModal(this)');
                    wrap.appendChild(restoreBtn);

                    // Add Delete button
                    var deleteBtn = document.createElement('button');
                    deleteBtn.title     = 'Delete permanently';
                    deleteBtn.className = 'w-8 h-8 rounded-lg flex items-center justify-center bg-red-100 dark:bg-red-900/30 text-red-500 dark:text-red-400 border border-red-200 dark:border-red-800 hover:bg-red-500 hover:text-white hover:border-red-500 transition-all active:scale-95';
                    deleteBtn.innerHTML = '<i class="fas fa-trash text-xs"></i>';
                    deleteBtn.setAttribute('onclick', 'openDeleteModal(this)');
                    wrap.appendChild(deleteBtn);
                }
            }

            // Update archived counter
            var ac = document.getElementById('archivedCount');
            if (ac) ac.textContent = parseInt(ac.textContent || 0, 10) + 1;

            filterAttendance();
            _showToast('Record archived successfully.', 'success');
        } else {
            _btnLoading(confirmBtn, false, '<i class="fas fa-box-archive"></i> Archive');
            _showToast(data.error || 'Could not archive record.', 'error');
        }
    });
}


// ═══════════════════════════════════════════════════════════════
// RESTORE MODAL
// ═══════════════════════════════════════════════════════════════

var _restoreModal = null;
document.addEventListener('DOMContentLoaded', function () {
    _restoreModal = document.getElementById('restoreModal');
});

function openRestoreModal(btn) {
    var row = btn.closest('tr');
    _pendingActionRow = row;
    _pendingActionBtn = btn;

    document.getElementById('restoreModalStudent').textContent = row.dataset.studentName || '—';
    document.getElementById('restoreModalEvent').textContent   = row.dataset.eventTitle  || '—';

    var confirmBtn = document.getElementById('restoreModalConfirmBtn');
    confirmBtn.disabled  = false;
    confirmBtn.innerHTML = '<i class="fas fa-rotate-left"></i> Restore';

    _showModal(_restoreModal);
}

function closeRestoreModal() {
    if (_restoreModal) _hideModal(_restoreModal);
    _pendingActionRow = null;
    _pendingActionBtn = null;
}

function confirmRestore() {
    if (!_pendingActionRow) return;
    var row   = _pendingActionRow;
    var attId = _getAttId(row);
    if (!attId) { closeRestoreModal(); return; }

    var confirmBtn = document.getElementById('restoreModalConfirmBtn');
    _btnLoading(confirmBtn, true);

    _postAction({ action: 'unarchive', attendance_id: attId }, function (data) {
        if (data.success) {
            closeRestoreModal();

            // Mark row as active
            row.dataset.archived = '0';
            row.classList.remove('att-archived');

            // Remove archived badge
            var badge = row.querySelector('.archived-badge');
            if (badge) badge.remove();

            // Replace Restore + Delete buttons → Details + Archive
            var actionsCell = row.cells[row.cells.length - 1];
            if (actionsCell) {
                var wrap = actionsCell.querySelector('.flex');
                if (wrap) {
                    // Clear restore/delete
                    var rBtn = wrap.querySelector('[title="Restore record"]');
                    var dBtn = wrap.querySelector('[title="Delete permanently"]');
                    if (rBtn) rBtn.remove();
                    if (dBtn) dBtn.remove();

                    // Re-add Details button if not present
                    if (!wrap.querySelector('[title="View / Edit"]')) {
                        var detBtn = document.createElement('button');
                        detBtn.title     = 'View / Edit';
                        detBtn.className = 'w-8 h-8 rounded-lg flex items-center justify-center bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-600 hover:bg-brand-500 hover:text-white hover:border-brand-500 transition-all active:scale-95';
                        detBtn.innerHTML = '<i class="fas fa-clock-rotate-left text-xs"></i>';
                        detBtn.setAttribute('onclick', 'openDetailsModal(this)');
                        wrap.prepend(detBtn);
                    }

                    // Add Archive button
                    var archBtn = document.createElement('button');
                    archBtn.title     = 'Archive record';
                    archBtn.className = 'w-8 h-8 rounded-lg flex items-center justify-center bg-gray-100 dark:bg-gray-700 text-amber-500 border border-gray-200 dark:border-gray-600 hover:bg-amber-500 hover:text-white hover:border-amber-500 transition-all active:scale-95';
                    archBtn.innerHTML = '<i class="fas fa-box-archive text-xs"></i>';
                    archBtn.setAttribute('onclick', 'openArchiveModal(this)');
                    wrap.appendChild(archBtn);
                }
            }

            // Update counter
            var ac = document.getElementById('archivedCount');
            if (ac) ac.textContent = Math.max(0, parseInt(ac.textContent || 0, 10) - 1);

            filterAttendance();
            _showToast('Record restored successfully.', 'success');
        } else {
            _btnLoading(confirmBtn, false, '<i class="fas fa-rotate-left"></i> Restore');
            _showToast(data.error || 'Could not restore record.', 'error');
        }
    });
}


// ═══════════════════════════════════════════════════════════════
// DELETE MODAL  (permanent — danger)
// ═══════════════════════════════════════════════════════════════

var _deleteModal = null;
document.addEventListener('DOMContentLoaded', function () {
    _deleteModal = document.getElementById('deleteModal');
});

function openDeleteModal(btn) {
    var row = btn.closest('tr');
    _pendingActionRow = row;
    _pendingActionBtn = btn;

    document.getElementById('deleteModalStudent').textContent = row.dataset.studentName || '—';
    document.getElementById('deleteModalEvent').textContent   = row.dataset.eventTitle  || '—';

    var confirmBtn = document.getElementById('deleteModalConfirmBtn');
    confirmBtn.disabled  = false;
    confirmBtn.innerHTML = '<i class="fas fa-trash-can"></i> Delete';

    _showModal(_deleteModal);
}

function closeDeleteModal() {
    if (_deleteModal) _hideModal(_deleteModal);
    _pendingActionRow = null;
    _pendingActionBtn = null;
}

function confirmDelete() {
    if (!_pendingActionRow) return;
    var row   = _pendingActionRow;
    var attId = _getAttId(row);
    if (!attId) { closeDeleteModal(); return; }

    var confirmBtn = document.getElementById('deleteModalConfirmBtn');
    _btnLoading(confirmBtn, true);

    _postAction({ action: 'delete', attendance_id: attId }, function (data) {
        if (data.success) {
            closeDeleteModal();

            // Animate row removal
            row.style.transition = 'opacity .3s, transform .3s';
            row.style.opacity    = '0';
            row.style.transform  = 'translateX(-20px)';
            setTimeout(function () { row.remove(); filterAttendance(); }, 320);

            // Update archived counter (it was archived)
            var ac = document.getElementById('archivedCount');
            if (ac) ac.textContent = Math.max(0, parseInt(ac.textContent || 0, 10) - 1);

            _showToast('Record permanently deleted.', 'success');
        } else {
            _btnLoading(confirmBtn, false, '<i class="fas fa-trash-can"></i> Delete');
            _showToast(data.error || 'Could not delete record.', 'error');
        }
    });
}


// ═══════════════════════════════════════════════════════════════
// GENERIC POST HELPER
// ═══════════════════════════════════════════════════════════════

function _postAction(payload, cb) {
    var fd = new FormData();
    Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(cb)
        .catch(function () { _showToast('Network error. Please try again.', 'error'); });
}


// ═══════════════════════════════════════════════════════════════
// TOAST NOTIFICATION (replaces remaining alert() calls)
// ═══════════════════════════════════════════════════════════════

var _toastTimer = null;

function _showToast(msg, type) {
    var existing = document.getElementById('semsToast');
    if (existing) existing.remove();
    if (_toastTimer) clearTimeout(_toastTimer);

    var t   = document.createElement('div');
    t.id    = 'semsToast';
    var isS = type === 'success';
    t.className = 'fixed bottom-20 right-5 z-[80] flex items-center gap-3 px-4 py-3 rounded-xl shadow-xl text-sm font-medium border transition-all duration-300 translate-y-2 opacity-0 '
        + (isS
            ? 'bg-brand-50 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300 border-brand-200 dark:border-brand-800'
            : 'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800');
    t.innerHTML = '<i class="fas ' + (isS ? 'fa-circle-check' : 'fa-circle-exclamation') + ' flex-shrink-0"></i><span>' + msg + '</span>';
    document.body.appendChild(t);

    // Animate in
    requestAnimationFrame(function () {
        t.style.transform = 'translateY(0)';
        t.style.opacity   = '1';
    });

    // Auto dismiss
    _toastTimer = setTimeout(function () {
        t.style.opacity   = '0';
        t.style.transform = 'translateY(8px)';
        setTimeout(function () { if (t.parentNode) t.remove(); }, 300);
    }, 3500);
}


// ═══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════════════════

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;

    // Close front-most modal first
    if (_deleteModal  && !_deleteModal.classList.contains('hidden'))  { closeDeleteModal();    return; }
    if (_restoreModal && !_restoreModal.classList.contains('hidden')) { closeRestoreModal();   return; }
    if (_archiveModal && !_archiveModal.classList.contains('hidden')) { closeArchiveModal();   return; }
    if (proofLightbox && !proofLightbox.classList.contains('hidden')) { closeProofLightbox(); return; }
    if (detailsModal  && !detailsModal.classList.contains('hidden'))  { closeDetailsModal();  return; }
});