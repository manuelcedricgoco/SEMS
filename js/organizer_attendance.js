/**
 * SEMS Organizer — organizer_attendance.js
 * Handles: Theme · Sidebar · Search/Filter · CSV Export ·
 *          Details Modal · Proof Lightbox · Archive/Unarchive ·
 *          Edit Status (with fraud-detection UI) · Keyboard shortcuts
 *
 * Requires SEMS_ATTENDANCE_DATA defined inline before this script:
 *   <script>
 *     const SEMS_ATTENDANCE_DATA = { exportDate: "<?= date('Y-m-d') ?>" };
 *   <\/script>
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
            track.style.background = '#f59e0b'; // amber-400
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

        // If the row is archived and toggle is off — always hide
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
            row.cells[0].innerText.trim().split('\n')[0],               // event (strip "Archived" badge)
            row.cells[1].innerText.trim().split('\n')[0],               // student name
            row.cells[2] ? row.cells[2].innerText.trim() : '',          // student no
            row.cells[3].innerText.trim().replace(/\n/g, ' '),          // login
            row.cells[4].innerText.trim().replace(/\n/g, ' '),          // logout
            row.cells[5].innerText.trim(),                              // status
            row.dataset.duration || 'N/A',                              // duration
            row.dataset.hasProof === '1' ? 'Yes' : 'No',               // has proof
            row.dataset.archived === '1' ? 'Yes' : 'No'                // archived
        ]);
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
// PROOF IMAGE LIGHTBOX
// Opens on thumbnail click. First click fetches image via AJAX
// (GET ?action=get_proof&id=X on the same PHP page); subsequent
// clicks reuse the cached src stored on the <img> element.
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

    // If already fetched — reuse
    if (thumbEl.dataset.loaded === '1' && thumbEl.src && !thumbEl.src.endsWith('proof-placeholder.png')) {
        _showLightboxImage(thumbEl.src);
        return;
    }

    // Show spinner while loading
    proofSpinner.classList.remove('hidden');
    proofSpinner.classList.add('flex');
    proofLightboxInner.classList.add('hidden');
    proofLightboxInner.classList.remove('flex');

    fetch('?action=get_proof&id=' + attId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                thumbEl.src            = data.src;  // cache on thumbnail
                thumbEl.dataset.loaded = '1';
                _showLightboxImage(data.src);
            } else {
                closeProofLightbox();
                alert('No proof image available for this record.');
            }
        })
        .catch(function () {
            closeProofLightbox();
            alert('Failed to load proof image. Please try again.');
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

var detailsModal  = null;
var _currentRow   = null;    // the TR currently shown in the modal
var _pendingStatus = null;   // 'present' | 'absent' | null

document.addEventListener('DOMContentLoaded', function () {
    detailsModal = document.getElementById('detailsModal');
});

function openDetailsModal(btn) {
    var row       = btn.closest('tr');
    _currentRow   = row;
    _pendingStatus = null;

    var isPresent = row.dataset.status === 'PRESENT';
    var name      = row.dataset.studentName || '—';
    var imgSrc    = row.dataset.studentImg  || '';
    var attId     = parseInt(row.dataset.attId || row.dataset['att-id'] || 0, 10)
                    || parseInt(row.querySelector('[data-att-id]') && row.querySelector('[data-att-id]').dataset.attId || 0, 10)
                    || _getAttId(row);

    // Populate event title + times
    document.getElementById('modalEventTitle').textContent  = row.dataset.eventTitle || '—';
    document.getElementById('modalStudentName').textContent = name;
    document.getElementById('modalLoginTime').textContent   = row.dataset.loginTime   || '—';
    document.getElementById('modalLogoutTime').textContent  = row.dataset.logoutTime  || '—';
    document.getElementById('modalDuration').textContent    = row.dataset.duration    || 'N/A';

    // Account photo
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

    // Status badge
    var badge = document.getElementById('modalStatusBadge');
    badge.innerHTML = isPresent
        ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 border border-brand-200 dark:border-brand-800"><i class="fas fa-check-circle text-[9px]"></i> Present</span>'
        : '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800"><i class="fas fa-times-circle text-[9px]"></i> Absent</span>';

    // Proof photo in fraud panel
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
                            // Also cache on thumbnail
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

    // Reset edit-status buttons to current state
    _updateStatusBtns(isPresent ? 'present' : 'absent', false);
    var msg = document.getElementById('editStatusMsg');
    if (msg) { msg.classList.add('hidden'); msg.textContent = ''; }

    // Show modal
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

/** Helper — read attendance_id from row's data-att-id attribute */
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
    btn.disabled    = true;
    btn.innerHTML   = '<i class="fas fa-spinner fa-spin mr-2"></i> Saving…';

    var fd = new FormData();
    fd.append('action',      'edit_status');
    fd.append('attendance_id', attId);
    fd.append('new_status',  _pendingStatus);

    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                // ── Patch the table row DOM ──────────────────────
                _currentRow.dataset.status     = data.status;
                _currentRow.dataset.duration   = data.duration;
                _currentRow.dataset.loginTime  = data.login_fmt;
                _currentRow.dataset.logoutTime = data.logout_fmt;

                // Status pill
                var pill = _currentRow.querySelector('.att-status-pill');
                if (pill) {
                    var isP = data.status === 'PRESENT';
                    pill.className = 'att-status-pill inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border uppercase '
                        + (isP ? 'bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400 border-brand-200 dark:border-brand-800'
                               : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800');
                    pill.innerHTML = '<i class="fas ' + (isP ? 'fa-check-circle' : 'fa-times-circle') + ' text-[10px]"></i> ' + data.status;
                }

                // Login / logout cells
                var cells = _currentRow.cells;
                if (cells[3]) { // login cell
                    if (data.login_date) {
                        cells[3].innerHTML = '<p class="text-gray-700 dark:text-gray-300 text-xs">' + data.login_date + '</p>'
                            + '<p class="text-brand-500 dark:text-brand-400 text-[11px] font-semibold">' + data.login_time + '</p>';
                    } else {
                        cells[3].innerHTML = '<span class="text-gray-300 dark:text-gray-600 text-xs">—</span>';
                    }
                }
                if (cells[4]) { // logout cell
                    if (data.logout_date) {
                        cells[4].innerHTML = '<p class="text-gray-700 dark:text-gray-300 text-xs">' + data.logout_date + '</p>'
                            + '<p class="text-amber-500 dark:text-amber-400 text-[11px] font-semibold">' + data['logout_time_'] + '</p>';
                    } else {
                        cells[4].innerHTML = '<span class="text-gray-400 text-xs italic">Not logged out</span>';
                    }
                }

                // Refresh modal badge + times
                document.getElementById('modalLoginTime').textContent  = data.login_fmt;
                document.getElementById('modalLogoutTime').textContent = data.logout_fmt;
                document.getElementById('modalDuration').textContent   = data.duration;
                var badge = document.getElementById('modalStatusBadge');
                var isPresent = data.status === 'PRESENT';
                badge.innerHTML = isPresent
                    ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 border border-brand-200 dark:border-brand-800"><i class="fas fa-check-circle text-[9px]"></i> Present</span>'
                    : '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800"><i class="fas fa-times-circle text-[9px]"></i> Absent</span>';

                _showEditMsg('Status updated successfully.', 'success');
                // Re-run filter to refresh stat counters
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
    msg.textContent  = text;
    msg.className    = 'text-xs text-center '
        + (type === 'success' ? 'text-brand-600 dark:text-brand-400' : 'text-red-500');
    msg.classList.remove('hidden');
    setTimeout(function () { msg.classList.add('hidden'); }, 3500);
}


// ═══════════════════════════════════════════════════════════════
// ARCHIVE / UNARCHIVE
// ═══════════════════════════════════════════════════════════════

function archiveRecord(btn) {
    var row   = btn.closest('tr');
    var attId = _getAttId(row);
    if (!attId) return;

    if (!confirm('Archive this attendance record? It will be hidden from the default view.')) return;

    _postAction({ action: 'archive', attendance_id: attId }, function (data) {
        if (data.success) {
            // Mark row as archived
            row.dataset.archived = '1';
            row.classList.add('att-archived');

            // Mark event cell
            var evCell = row.cells[0];
            if (evCell && !evCell.querySelector('.archived-badge')) {
                var badge = document.createElement('span');
                badge.className = 'archived-badge block text-[10px] text-amber-500 font-semibold mt-0.5';
                badge.innerHTML = '<i class="fas fa-box-archive mr-1"></i>Archived';
                evCell.appendChild(badge);
            }

            // Swap archive→unarchive button
            btn.title     = 'Unarchive';
            btn.innerHTML = '<i class="fas fa-box-open text-xs"></i>';
            btn.className = btn.className
                .replace('text-amber-500', 'text-amber-600')
                .replace('bg-gray-100 dark:bg-gray-700', 'bg-amber-100 dark:bg-amber-900/30')
                .replace('border-gray-200 dark:border-gray-600', 'border-amber-200 dark:border-amber-800');
            btn.setAttribute('onclick', 'unarchiveRecord(this)');

            // Increment archived count
            var ac = document.getElementById('archivedCount');
            if (ac) ac.textContent = parseInt(ac.textContent || 0, 10) + 1;

            filterAttendance();
        } else {
            alert(data.error || 'Could not archive record.');
        }
    });
}

function unarchiveRecord(btn) {
    var row   = btn.closest('tr');
    var attId = _getAttId(row);
    if (!attId) return;

    _postAction({ action: 'unarchive', attendance_id: attId }, function (data) {
        if (data.success) {
            row.dataset.archived = '0';
            row.classList.remove('att-archived');

            // Remove archived badge
            var badge = row.querySelector('.archived-badge');
            if (badge) badge.remove();

            // Swap unarchive→archive button
            btn.title     = 'Archive';
            btn.innerHTML = '<i class="fas fa-box-archive text-xs"></i>';
            btn.className = btn.className
                .replace('text-amber-600', 'text-amber-500')
                .replace('bg-amber-100 dark:bg-amber-900/30', 'bg-gray-100 dark:bg-gray-700')
                .replace('border-amber-200 dark:border-amber-800', 'border-gray-200 dark:border-gray-600');
            btn.setAttribute('onclick', 'archiveRecord(this)');

            // Decrement archived count
            var ac = document.getElementById('archivedCount');
            if (ac) ac.textContent = Math.max(0, parseInt(ac.textContent || 0, 10) - 1);

            filterAttendance();
        } else {
            alert(data.error || 'Could not unarchive record.');
        }
    });
}

/** Generic POST helper */
function _postAction(payload, cb) {
    var fd = new FormData();
    Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(cb)
        .catch(function () { alert('Network error. Please try again.'); });
}


// ═══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════════════════

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        if (proofLightbox && !proofLightbox.classList.contains('hidden')) { closeProofLightbox(); return; }
        if (detailsModal  && !detailsModal.classList.contains('hidden'))  { closeDetailsModal();  return; }
    }
});