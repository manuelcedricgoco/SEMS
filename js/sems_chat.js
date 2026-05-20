/* ============================================================
 *  sems_chat.js  —  SEMS Shared Chat Logic
 *  Place at: /js/sems_chat.js
 *
 *  Requires global:
 *    SEMS_CHAT = { myId, myName, myInit, apiUrl, badgeId? }
 *
 *  badgeId (optional): which sidebar badge element to update.
 *    Default: 'sidebarBadge'
 *    Admin-chat pages should pass: 'adminBadge'
 * ============================================================ */
'use strict';

/* ── State ─────────────────────────────────────────────────── */
let currentConvId = null;
let lastMsgId     = 0;
let pollTimer     = null;
let currentTab    = 'convs';
let conversations = [];
let contacts      = [];
let currentFilter = '';

let pendingFiles  = [];

const REACTIONS    = ['👍','❤️','😂','😮','😢','😡'];
const EDIT_LIMIT_MS = 15 * 60 * 1000;

// FIX: single source-of-truth for the pending unsend message ID
let pendingUnsendId = null;

const editOriginals = new Map();

const AVATAR_URL = '/includes/get_avatar.php?uid=';

/* ── Init ──────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    if (window.lucide) lucide.createIcons();
    loadConversations();
    startBadgePoller();

    const deep = parseInt(new URLSearchParams(location.search).get('conv_id') || '0', 10);
    if (deep) openThread(deep, null, null, null);

    const fi = document.getElementById('fileInput');
    if (fi) fi.addEventListener('change', handleFileSelect);

    document.addEventListener('click', e => {
        if (!e.target.closest('.reaction-picker') && !e.target.closest('.react-trigger')) {
            document.querySelectorAll('.reaction-picker').forEach(p => p.remove());
        }
        if (!e.target.closest('.msg-menu-dropdown') && !e.target.closest('.msg-menu-btn')) {
            document.querySelectorAll('.msg-menu-dropdown.open').forEach(d => d.classList.remove('open'));
        }
    });
});

/* ── Tab ───────────────────────────────────────────────────── */
window.switchTab = function (tab) {
    currentTab    = tab;
    currentFilter = '';
    const s = document.getElementById('searchInput');
    if (s) s.value = '';
    document.getElementById('tabConvs')?.classList.toggle('active', tab === 'convs');
    document.getElementById('tabNew')  ?.classList.toggle('active', tab === 'new');
    if (tab === 'convs') renderConversations();
    else                 loadContacts();
};

/* ── Filter ────────────────────────────────────────────────── */
window.filterList = function (val) {
    currentFilter = val.toLowerCase().trim();
    if (currentTab === 'convs') renderConversations();
    else                        renderContacts();
};

/* ── API POST helper ───────────────────────────────────────── */
async function api(action, params = {}, formData = null) {
    const fd = formData || new FormData();
    if (!formData) {
        fd.append('action', action);
        for (const [k, v] of Object.entries(params)) fd.append(k, v);
    } else {
        fd.set('action', action);
    }
    const res  = await fetch(SEMS_CHAT.apiUrl, { method: 'POST', body: fd });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'API error');
    return json;
}

/* ── Conversations ─────────────────────────────────────────── */
async function loadConversations() {
    try {
        const data = await api('get_conversations');
        conversations = data.conversations || [];
        if (currentTab === 'convs') renderConversations();
    } catch (err) {
        console.error('[SEMS Chat] loadConversations failed:', err);
        setListEmpty('Could not load conversations. Check your connection.');
    }
}

function renderConversations() {
    const area = document.getElementById('listScrollArea');
    if (!area) return;

    const list = conversations.filter(c =>
        !currentFilter ||
        (c.other_name || '').toLowerCase().includes(currentFilter) ||
        (c.other_sub  || '').toLowerCase().includes(currentFilter)
    );

    if (!list.length) {
        setListEmpty(currentFilter
            ? 'No matches found.'
            : 'No conversations yet.<br><small>Use "New Message" to start one.</small>');
        return;
    }

    area.innerHTML = list.map(c => {
        const isActive = currentConvId === parseInt(c.conv_id, 10);
        const unread   = parseInt(c.unread || '0', 10);
        const preview  = c.last_msg
            ? truncate(c.last_msg, 40)
            : '<em style="opacity:.55">No messages yet</em>';
        const time = c.last_message_at ? relTime(c.last_message_at) : '';

        return `
        <div class="contact-row ${isActive ? 'active' : ''}"
             data-cid="${c.conv_id}"
             data-name="${attr(c.other_name)}"
             data-sub="${attr(c.other_sub)}"
             data-uid="${c.other_id}"
             onclick="handleConvClick(this)">
          ${avatarHTML(c.other_id, c.other_name)}
          <div style="flex:1;min-width:0;">
            <div class="text-gray-900 dark:text-white"
                 style="font-weight:600;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              ${esc(c.other_name)}
            </div>
            <div class="text-gray-500 dark:text-gray-400"
                 style="font-size:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              ${preview}
            </div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.2rem;flex-shrink:0;">
            ${time ? `<span class="text-gray-400" style="font-size:.67rem;">${time}</span>` : ''}
            ${unread > 0 ? `<span class="unread-dot">${unread}</span>` : ''}
          </div>
        </div>`;
    }).join('');
}

/* ── Contacts (New Message) ────────────────────────────────── */
async function loadContacts() {
    setListEmpty('Loading…');
    try {
        const data = await api('get_contacts');
        contacts = data.contacts || [];
        renderContacts();
    } catch (err) {
        console.error('[SEMS Chat] loadContacts failed:', err);
        setListEmpty('Could not load contacts.');
    }
}

function renderContacts() {
    const area = document.getElementById('listScrollArea');
    if (!area) return;

    const list = contacts.filter(c =>
        !currentFilter ||
        (c.full_name      || '').toLowerCase().includes(currentFilter) ||
        (c.group_name     || '').toLowerCase().includes(currentFilter) ||
        (c.dept_name      || '').toLowerCase().includes(currentFilter) ||
        (c.student_number || '').toLowerCase().includes(currentFilter) ||
        (c.position       || '').toLowerCase().includes(currentFilter)
    );

    if (!list.length) {
        setListEmpty(currentFilter ? 'No contacts match.' : 'No contacts available.');
        return;
    }

    const groups = {};
    list.forEach(c => {
        const key = c.group_name || c.dept_name || 'Others';
        if (!groups[key]) groups[key] = [];
        groups[key].push(c);
    });

    let html = '';
    for (const [grp, members] of Object.entries(groups)) {
        html += `<p class="text-gray-400 dark:text-gray-500"
                    style="font-size:.67rem;font-weight:700;letter-spacing:.08em;
                           text-transform:uppercase;padding:.5rem .75rem .2rem;">
                   ${esc(grp)}
                 </p>`;

        html += members.map(c => {
            const sub = c.student_number
                ? `${esc(c.student_number)}${c.year_level ? ' · ' + esc(c.year_level) + (c.section ? '-' + esc(c.section) : '') : ''}`
                : esc(c.position || '');
            return `
            <div class="contact-row"
                 data-uid="${c.user_id}"
                 data-name="${attr(c.full_name)}"
                 data-sub="${attr(sub)}"
                 onclick="handleContactClick(this)">
              ${avatarHTML(c.user_id, c.full_name)}
              <div style="min-width:0;">
                <div class="text-gray-900 dark:text-white"
                     style="font-weight:600;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  ${esc(c.full_name)}
                </div>
                <div class="text-gray-500 dark:text-gray-400"
                     style="font-size:.74rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  ${sub}
                </div>
              </div>
            </div>`;
        }).join('');
    }
    area.innerHTML = html;
}

/* ── Click handlers ────────────────────────────────────────── */
window.handleContactClick = function (el) {
    const uid  = parseInt(el.dataset.uid,  10);
    const name = el.dataset.name || '';
    const sub  = el.dataset.sub  || '';
    startConversation(uid, name, sub);
};

window.handleConvClick = function (el) {
    const convId = parseInt(el.dataset.cid, 10);
    const name   = el.dataset.name || '';
    const sub    = el.dataset.sub  || '';
    const uid    = parseInt(el.dataset.uid, 10);
    openThread(convId, name, sub, uid);
};

/* ── Start new conversation ────────────────────────────────── */
async function startConversation(otherId, name, sub) {
    try {
        const data = await api('open_conversation', { other_id: otherId });
        await openThread(data.conv_id, name, sub, otherId);
        loadConversations();
    } catch (e) {
        console.error('[SEMS Chat] startConversation failed:', e);
        alert('Could not start conversation: ' + e.message);
    }
}

/* ── Open thread ───────────────────────────────────────────── */
window.openThread = async function (convId, name, sub, otherId) {
    convId        = parseInt(convId, 10);
    currentConvId = convId;
    lastMsgId     = 0;

    if (!name) {
        const match = conversations.find(c => parseInt(c.conv_id, 10) === convId);
        if (match) { name = match.other_name; sub = match.other_sub; otherId = match.other_id; }
    }

    const empty = document.getElementById('threadEmpty');
    const view  = document.getElementById('threadView');
    if (empty) empty.style.display = 'none';
    if (view)  {
        view.classList.remove('hidden');
        view.style.display = 'flex'; // FIX: explicit flex so layout is correct immediately
    }

    const av = document.getElementById('threadAvatar');
    if (av) {
        av.innerHTML = '';
        if (otherId) {
            const img = document.createElement('img');
            img.src   = AVATAR_URL + otherId;
            img.alt   = name || '';
            img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;';
            img.onerror = function () { this.style.display = 'none'; };
            av.appendChild(img);
        }
    }
    const nm = document.getElementById('threadName');
    const sb = document.getElementById('threadSub');
    if (nm) nm.textContent = name || '—';
    if (sb) sb.textContent = sub  || '';

    document.getElementById('msgInput')?.focus();

    const area = document.getElementById('messagesArea');
    if (area) area.innerHTML = spinnerHTML();
    await fetchMessages();
    markRead();

    clearInterval(pollTimer);
    pollTimer = setInterval(pollNew, 2500);

    if (window.innerWidth <= 680) hideList();

    // Highlight active conversation in list
    document.querySelectorAll('.contact-row[data-cid]').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.cid, 10) === convId);
    });
};

/* ── Fetch all messages ────────────────────────────────────── */
async function fetchMessages() {
    if (!currentConvId) return;
    try {
        const res  = await fetch(
            `${SEMS_CHAT.apiUrl}?action=fetch_messages&conv_id=${currentConvId}`
        );
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (!data.ok) return;
        const msgs = data.messages || [];
        renderAllMessages(msgs);
        if (msgs.length) lastMsgId = parseInt(msgs[msgs.length - 1].msg_id, 10);
    } catch (err) {
        console.error('[SEMS Chat] fetchMessages failed:', err);
    }
}

/* ── Poll new messages ─────────────────────────────────────── */
async function pollNew() {
    if (!currentConvId) return;
    try {
        const res  = await fetch(
            `${SEMS_CHAT.apiUrl}?action=fetch_messages&conv_id=${currentConvId}&after_id=${lastMsgId}`
        );
        if (!res.ok) return;
        const data = await res.json();
        if (!data.ok || !data.messages.length) return;

        const area  = document.getElementById('messagesArea');
        const atBtm = isAtBottom(area);
        data.messages.forEach(m => appendMsg(m, area));
        lastMsgId = parseInt(data.messages[data.messages.length - 1].msg_id, 10);
        if (atBtm) scrollBottom(area);
        markRead();
        loadConversations();
    } catch {}
}

/* ── Render all messages ───────────────────────────────────── */
function renderAllMessages(msgs) {
    const area = document.getElementById('messagesArea');
    if (!msgs.length) {
        area.innerHTML =
            '<div style="flex:1;display:flex;align-items:center;justify-content:center;' +
            'font-size:.82rem;" class="text-gray-400">No messages yet. Say hello! 👋</div>';
        return;
    }
    area.innerHTML = '';
    let lastDate = '';
    msgs.forEach(m => {
        const d = m.sent_at.substring(0, 10);
        if (d !== lastDate) {
            lastDate = d;
            const sep = document.createElement('div');
            sep.className   = 'date-sep';
            sep.textContent = dateSep(m.sent_at);
            area.appendChild(sep);
        }
        appendMsg(m, area);
    });
    scrollBottom(area);
}

/* ── Append one message ────────────────────────────────────── */
function appendMsg(m, area) {
    const mine  = parseInt(m.sender_id, 10) === SEMS_CHAT.myId;
    const msgId = m.msg_id;

    if (m.is_deleted == 1 || m.is_deleted === true) {
        const wrapper = document.createElement('div');
        wrapper.className   = `msg-row${mine ? ' mine' : ''}`;
        wrapper.dataset.mid = msgId;
        const noticeText = mine ? 'You unsent this message' : 'This message was unsent';
        wrapper.innerHTML = `
            <div class="bubble-outer${mine ? ' mine' : ''}">
                <div class="unsent-notice">
                    <i class="fas fa-ban" style="margin-right:.3rem;font-size:.72rem;opacity:.6;"></i>
                    ${noticeText}
                </div>
            </div>`;
        area.appendChild(wrapper);
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className   = `msg-row${mine ? ' mine' : ''}`;
    wrapper.dataset.mid = msgId;

    const sentMs  = new Date((m.sent_at || '').replace(' ', 'T')).getTime();
    const canEdit = mine && !isNaN(sentMs) && (Date.now() - sentMs) < EDIT_LIMIT_MS && !m.file_url;
    const isEdited = m.is_edited == 1 || m.is_edited === true;

    let bubbleContent = '';
    if (m.file_url) {
        if (m.file_type && m.file_type.startsWith('image/')) {
            bubbleContent = `
                <div class="attachment-img-wrap">
                    <img src="${esc(m.file_url)}" alt="image" class="chat-img"
                         onclick="openLightbox(this.src)"
                         onerror="this.parentElement.innerHTML='<span style=\\'opacity:.5;font-size:.8rem\\'>Image unavailable</span>'">
                    ${m.message ? `<div class="img-caption">${esc(m.message).replace(/\n/g,'<br>')}</div>` : ''}
                </div>`;
        } else {
            const fname = m.file_name || 'Attachment';
            const fsize = m.file_size ? formatBytes(m.file_size) : '';
            bubbleContent = `
                <a href="${esc(m.file_url)}" download="${esc(fname)}" class="file-bubble-link">
                    <span class="file-icon">📎</span>
                    <span class="file-info">
                        <span class="file-name">${esc(fname)}</span>
                        ${fsize ? `<span class="file-size">${fsize}</span>` : ''}
                    </span>
                    <span class="file-dl">↓</span>
                </a>
                ${m.message ? `<div style="margin-top:.35rem;font-size:.85rem;">${esc(m.message).replace(/\n/g,'<br>')}</div>` : ''}`;
        }
    } else {
        bubbleContent = esc(m.message).replace(/\n/g, '<br>');
        if (isEdited) bubbleContent += `<span class="edited-tag">(edited)</span>`;
    }

    const reactionsHTML = buildReactionsHTML(m.reactions || {}, msgId);

    const menuHTML = mine ? `
        <div class="msg-menu-wrap">
            <button class="msg-menu-btn" onclick="toggleMsgMenu(event,${msgId},this)" title="Message options">
                <i class="fas fa-ellipsis-v" style="pointer-events:none;"></i>
            </button>
            <div class="msg-menu-dropdown" id="menu-${msgId}">
                ${canEdit
                    ? `<button class="msg-menu-item" onclick="startEdit(${msgId})">
                            <i class="fas fa-pencil-alt"></i> Edit
                       </button>`
                    : ''}
                <button class="msg-menu-item danger" onclick="confirmUnsend(${msgId})">
                    <i class="fas fa-trash-alt"></i> Unsend
                </button>
            </div>
        </div>` : '';

    wrapper.innerHTML = `
        <div class="bubble-outer${mine ? ' mine' : ''}">
            <div class="bubble-and-react${mine ? ' mine' : ''}">
                ${menuHTML}
                <button class="react-trigger" title="React"
                        onclick="toggleReactionPicker(event,${msgId},this)">
                    <i class="far fa-face-smile"></i>
                </button>
                <div class="bubble ${mine ? 'mine' : 'theirs'}" id="bubble-${msgId}"
                     data-text="${attr(m.message || '')}">${bubbleContent}</div>
            </div>
            <div class="msg-ts${mine ? ' mine' : ''}">${fmtTime(m.sent_at)}</div>
            <div class="reactions-row" id="reactions-${msgId}">${reactionsHTML}</div>
        </div>`;

    area.appendChild(wrapper);
}

/* ── 3-dot message menu ────────────────────────────────────── */
window.toggleMsgMenu = function (e, msgId, btn) {
    e.stopPropagation();
    document.querySelectorAll('.msg-menu-dropdown.open').forEach(d => {
        if (d.id !== `menu-${msgId}`) d.classList.remove('open');
    });
    document.getElementById(`menu-${msgId}`)?.classList.toggle('open');
};

/* ── Unsend ────────────────────────────────────────────────── */
// FIX: confirmUnsend sets the SINGLE pendingUnsendId in this file's scope.
//      The PHP page must NOT redeclare these — all unsend logic lives here.
window.confirmUnsend = function (msgId) {
    document.getElementById(`menu-${msgId}`)?.classList.remove('open');
    pendingUnsendId = msgId;
    const modal = document.getElementById('unsendModal');
    if (modal) modal.classList.add('open');
};

window.closeUnsendModal = function () {
    pendingUnsendId = null;
    document.getElementById('unsendModal')?.classList.remove('open');
};

window.handleUnsendBgClick = function (e) {
    if (e.target === document.getElementById('unsendModal')) closeUnsendModal();
};

window.doUnsend = async function () {
    if (!pendingUnsendId) return;
    const msgId     = pendingUnsendId;
    const confirmBtn = document.getElementById('unsendConfirmBtn');
    if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.textContent = 'Unsending…'; }

    try {
        await api('unsend_message', { msg_id: msgId });

        const row = document.querySelector(`.msg-row[data-mid="${msgId}"]`);
        if (row) {
            const outer = row.querySelector('.bubble-outer');
            if (outer) {
                outer.innerHTML = `
                    <div class="unsent-notice">
                        <i class="fas fa-ban" style="margin-right:.3rem;font-size:.72rem;opacity:.6;"></i>
                        You unsent this message
                    </div>`;
            }
        }
        closeUnsendModal();
        loadConversations();
    } catch (err) {
        console.error('[SEMS Chat] doUnsend failed:', err);
        alert('Could not unsend message: ' + err.message);
    } finally {
        if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = 'Unsend'; }
    }
};

/* ── Inline edit ───────────────────────────────────────────── */
window.startEdit = function (msgId) {
    document.getElementById(`menu-${msgId}`)?.classList.remove('open');

    const bubble = document.getElementById(`bubble-${msgId}`);
    if (!bubble) return;

    const originalText = bubble.dataset.text || '';
    editOriginals.set(msgId, { text: originalText, html: bubble.innerHTML });

    bubble.innerHTML = `
        <textarea class="edit-ta" id="edit-ta-${msgId}" rows="1"
                  onkeydown="handleEditKey(event,${msgId})"
                  oninput="autoResize(this)">${esc(originalText)}</textarea>
        <div class="edit-actions">
            <span class="edit-hint">Enter to save · Esc to cancel</span>
            <button class="edit-cancel-btn" onclick="cancelEdit(${msgId})">Cancel</button>
            <button class="edit-save-btn" id="edit-save-${msgId}" onclick="saveEdit(${msgId})">Save</button>
        </div>`;

    const ta = document.getElementById(`edit-ta-${msgId}`);
    if (ta) {
        autoResize(ta);
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
    }
};

window.cancelEdit = function (msgId) {
    const bubble = document.getElementById(`bubble-${msgId}`);
    const orig   = editOriginals.get(msgId);
    if (bubble && orig) bubble.innerHTML = orig.html;
    editOriginals.delete(msgId);
};

window.saveEdit = async function (msgId) {
    const ta = document.getElementById(`edit-ta-${msgId}`);
    if (!ta) return;

    const newText = ta.value.trim();
    if (!newText) { ta.focus(); return; }

    const saveBtn = document.getElementById(`edit-save-${msgId}`);
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

    try {
        await api('edit_message', { msg_id: msgId, message: newText });
        const bubble = document.getElementById(`bubble-${msgId}`);
        if (bubble) {
            bubble.dataset.text = newText;
            bubble.innerHTML    = esc(newText).replace(/\n/g, '<br>') +
                `<span class="edited-tag">(edited)</span>`;
        }
        editOriginals.delete(msgId);
    } catch (err) {
        console.error('[SEMS Chat] saveEdit failed:', err);
        alert('Could not save edit: ' + err.message);
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
    }
};

window.handleEditKey = function (e, msgId) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveEdit(msgId); }
    if (e.key === 'Escape')               { cancelEdit(msgId); }
};

/* ── Reaction picker ───────────────────────────────────────── */
window.toggleReactionPicker = function (e, msgId, btn) {
    e.stopPropagation();
    document.querySelectorAll('.reaction-picker').forEach(p => p.remove());

    const existing = document.getElementById(`rpicker-${msgId}`);
    if (existing) { existing.remove(); return; }

    const picker = document.createElement('div');
    picker.className = 'reaction-picker';
    picker.id        = `rpicker-${msgId}`;
    picker.innerHTML = REACTIONS.map(em =>
        `<button class="react-emoji-btn" onclick="sendReaction(${msgId},'${em}',this)">${em}</button>`
    ).join('');

    const rect = btn.getBoundingClientRect();
    picker.style.cssText = `position:fixed;z-index:999;top:${rect.top - 52}px;left:${Math.max(4, rect.left - 80)}px;`;

    document.body.appendChild(picker);
    requestAnimationFrame(() => picker.classList.add('visible'));
};

window.sendReaction = async function (msgId, emoji, btn) {
    document.getElementById(`rpicker-${msgId}`)?.remove();

    const row = document.querySelector(`.msg-row[data-mid="${msgId}"] .reactions-row`);
    if (row) {
        const existing = row.querySelector(`[data-emoji="${emoji}"]`);
        if (existing) {
            const cnt = parseInt(existing.dataset.count || '1', 10);
            if (cnt <= 1) existing.remove();
            else {
                existing.dataset.count = cnt - 1;
                existing.querySelector('.rcnt').textContent = cnt - 1;
            }
        } else {
            const chip = document.createElement('button');
            chip.className     = 'reaction-chip mine';
            chip.dataset.emoji = emoji;
            chip.dataset.count = '1';
            chip.innerHTML     = `${emoji}<span class="rcnt">1</span>`;
            chip.onclick       = () => sendReaction(msgId, emoji, chip);
            row.appendChild(chip);
        }
    }

    try { await api('react_message', { msg_id: msgId, emoji }); } catch {}
};

function buildReactionsHTML(reactions, msgId) {
    return Object.entries(reactions).map(([emoji, data]) => {
        const cls = data.mine ? 'reaction-chip mine' : 'reaction-chip';
        return `<button class="${cls}" data-emoji="${emoji}" data-count="${data.count}"
                        onclick="sendReaction(${msgId},'${emoji}',this)">
                  ${emoji}<span class="rcnt">${data.count}</span>
                </button>`;
    }).join('');
}

/* ── File select ───────────────────────────────────────────── */
function handleFileSelect(e) {
    const files = Array.from(e.target.files || []);
    if (!files.length) return;

    const toAdd = files.slice(0, 5 - pendingFiles.length);
    toAdd.forEach(file => {
        const reader = new FileReader();
        reader.onload = ev => {
            pendingFiles.push({ file, dataUrl: ev.target.result, type: file.type });
            renderFilePreview();
        };
        reader.readAsDataURL(file);
    });
    e.target.value = '';
}

function renderFilePreview() {
    let bar = document.getElementById('filePreviewBar');
    if (!pendingFiles.length) { if (bar) bar.remove(); return; }
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'filePreviewBar';
        bar.className = 'file-preview-bar';
        const inputBar = document.querySelector('.input-bar');
        if (inputBar) inputBar.parentNode.insertBefore(bar, inputBar);
    }

    bar.innerHTML = pendingFiles.map((pf, i) => {
        const isImg = pf.type.startsWith('image/');
        return `
        <div class="fpreview-item" data-idx="${i}">
            ${isImg
                ? `<img src="${pf.dataUrl}" class="fpreview-img" alt="">`
                : `<div class="fpreview-file"><i class="fas fa-file"></i>
                   <span>${esc(pf.file.name.substring(0,12))}${pf.file.name.length > 12 ? '…' : ''}</span></div>`
            }
            <button class="fpreview-remove" onclick="removePendingFile(${i})" title="Remove">×</button>
            <div class="fpreview-name">${esc(pf.file.name.substring(0,10))}${pf.file.name.length > 10 ? '…' : ''}</div>
        </div>`;
    }).join('');
}

window.removePendingFile = function (idx) {
    pendingFiles.splice(idx, 1);
    renderFilePreview();
};

/* ── Send ──────────────────────────────────────────────────── */
window.sendMessage = async function () {
    const inp    = document.getElementById('msgInput');
    const msg    = (inp?.value || '').trim();
    const hasMsg = msg.length > 0;
    const hasFil = pendingFiles.length > 0;

    if ((!hasMsg && !hasFil) || !currentConvId) return;

    const btn = document.getElementById('sendBtn');
    if (btn) btn.disabled = true;
    inp.value = '';
    autoResize(inp);

    const filesToSend = [...pendingFiles];
    pendingFiles = [];
    renderFilePreview();

    try {
        if (filesToSend.length > 0) {
            for (let i = 0; i < filesToSend.length; i++) {
                const fd = new FormData();
                fd.append('action',     'send_message');
                fd.append('conv_id',    currentConvId);
                fd.append('message',    i === 0 ? msg : '');
                fd.append('attachment', filesToSend[i].file);
                const res  = await fetch(SEMS_CHAT.apiUrl, { method: 'POST', body: fd });
                const json = await res.json();
                if (!json.ok) throw new Error(json.error || 'Upload failed');
            }
        } else {
            await api('send_message', { conv_id: currentConvId, message: msg });
        }
        await pollNew();
    } catch (e) {
        console.error('[SEMS Chat] sendMessage failed:', e);
        alert('Send failed: ' + e.message);
        inp.value    = msg;
        pendingFiles = filesToSend;
        renderFilePreview();
    } finally {
        if (btn) btn.disabled = false;
        inp?.focus();
    }
};

window.handleKey = function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
};

window.autoResize = function (el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
};

/* ── Lightbox ──────────────────────────────────────────────── */
window.openLightbox = function (src) {
    let lb = document.getElementById('imgLightbox');
    if (!lb) {
        lb = document.createElement('div');
        lb.id = 'imgLightbox';
        lb.className = 'img-lightbox';
        lb.innerHTML = `<div class="lb-backdrop" onclick="closeLightbox()"></div>
                        <div class="lb-inner">
                            <img id="lbImg" src="" alt="Preview">
                            <button class="lb-close" onclick="closeLightbox()">×</button>
                        </div>`;
        document.body.appendChild(lb);
    }
    document.getElementById('lbImg').src = src;
    lb.classList.add('open');
};
window.closeLightbox = function () {
    document.getElementById('imgLightbox')?.classList.remove('open');
};

window.triggerFileInput = function () {
    document.getElementById('fileInput')?.click();
};

/* ── Mark read ─────────────────────────────────────────────── */
function markRead() {
    if (!currentConvId) return;
    const fd = new FormData();
    fd.append('action',  'mark_read');
    fd.append('conv_id', currentConvId);
    fetch(SEMS_CHAT.apiUrl, { method: 'POST', body: fd }).catch(() => {});
}

/* ── Sidebar unread badge ──────────────────────────────────── */
// FIX: respect SEMS_CHAT.badgeId so the correct nav badge is updated.
//      Regular organizer chat pages: badgeId = 'sidebarBadge' (default)
//      Admin chat page:              badgeId = 'adminBadge'
function startBadgePoller() {
    updateBadge();
    setInterval(updateBadge, 8000);
}

async function updateBadge() {
    try {
        const res   = await fetch(`${SEMS_CHAT.apiUrl}?action=unread_count`);
        if (!res.ok) return;
        const data  = await res.json();
        const badgeId = (SEMS_CHAT.badgeId) || 'sidebarBadge';
        const badge   = document.getElementById(badgeId);
        if (!badge) return;
        const n = parseInt(data.count, 10) || 0;
        if (n > 0) {
            badge.textContent   = n > 99 ? '99+' : n;
            badge.style.display = '';
            badge.classList.remove('hidden');
        } else {
            badge.style.display = 'none';
            badge.classList.add('hidden');
        }
    } catch {}
}

/* ── Mobile ────────────────────────────────────────────────── */
window.showList = function () {
    document.getElementById('listPanel')?.classList.remove('hide');
};
function hideList() {
    document.getElementById('listPanel')?.classList.add('hide');
}

/* ── Avatar HTML helper ────────────────────────────────────── */
function avatarHTML(uid, name) {
    return `<div class="c-avatar" style="overflow:hidden;position:relative;">
              <img src="${AVATAR_URL}${uid}"
                   alt="${esc(name)}"
                   style="width:100%;height:100%;object-fit:cover;border-radius:50%;position:absolute;inset:0;">
            </div>`;
}

/* ── Utilities ─────────────────────────────────────────────── */
function esc(s) {
    return String(s || '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function attr(s) {
    return String(s || '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
}
function truncate(s, n) { return s.length > n ? s.substring(0, n) + '…' : s; }
function formatBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(1) + ' MB';
}
function relTime(ts) {
    const diff = Math.floor((Date.now() - new Date(ts.replace(' ','T')).getTime()) / 1000);
    if (diff < 60)    return 'now';
    if (diff < 3600)  return Math.floor(diff / 60)   + 'm';
    if (diff < 86400) return Math.floor(diff / 3600)  + 'h';
    return Math.floor(diff / 86400) + 'd';
}
function fmtTime(ts) {
    return new Date(ts.replace(' ','T'))
        .toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
function dateSep(ts) {
    const d    = new Date(ts.replace(' ','T'));
    const now  = new Date();
    if (d.toDateString() === now.toDateString()) return 'Today';
    const yest = new Date(now); yest.setDate(now.getDate() - 1);
    if (d.toDateString() === yest.toDateString()) return 'Yesterday';
    return d.toLocaleDateString([], { month:'long', day:'numeric', year:'numeric' });
}
function isAtBottom(el) { return el.scrollHeight - el.scrollTop - el.clientHeight < 80; }
function scrollBottom(el) { el.scrollTop = el.scrollHeight; }
function setListEmpty(msg) {
    const a = document.getElementById('listScrollArea');
    if (a) a.innerHTML =
        `<div class="text-gray-400" style="padding:2.5rem 1rem;text-align:center;font-size:.82rem;">${msg}</div>`;
}
function spinnerHTML() {
    return `<div style="display:flex;align-items:center;justify-content:center;height:100%;gap:4px;">
        <span style="width:7px;height:7px;border-radius:50%;background:#94a3b8;display:inline-block;animation:db .9s infinite;"></span>
        <span style="width:7px;height:7px;border-radius:50%;background:#94a3b8;display:inline-block;animation:db .9s .15s infinite;"></span>
        <span style="width:7px;height:7px;border-radius:50%;background:#94a3b8;display:inline-block;animation:db .9s .3s infinite;"></span>
        <style>@keyframes db{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-6px)}}</style>
    </div>`;
}