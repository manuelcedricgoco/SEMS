/* ── Theme ── */
const html = document.documentElement;
const themeIcon = document.getElementById("themeIcon");
function applyTheme(dark) {
    dark ? html.classList.add("dark") : html.classList.remove("dark");
    themeIcon.className = dark ? "fas fa-sun text-sm" : "fas fa-moon text-sm";
}
function toggleTheme() {
    const d = !html.classList.contains("dark");
    localStorage.setItem("theme", d ? "dark" : "light");
    applyTheme(d);
}
(function () {
    const s = localStorage.getItem("theme");
    if (s === "dark" || (!s && window.matchMedia("(prefers-color-scheme:dark)").matches))
        applyTheme(true);
})();

/* ── Sidebar ── */
const sidebar   = document.getElementById("sidebar");
const sbOverlay = document.getElementById("sb-overlay");
function openSidebar()  { sidebar.classList.remove("-translate-x-full"); sbOverlay.classList.add("show"); }
function closeSidebar() { sidebar.classList.add("-translate-x-full");    sbOverlay.classList.remove("show"); }

/* ── Filter + Search ── */
let activeFilter = "all";
function setFilter(f) {
    activeFilter = f;
    document.querySelectorAll(".filter-btn")
        .forEach(btn => btn.classList.toggle("active", btn.dataset.filter === f));
    applyFilters();
}
function applyFilters() {
    const q     = (document.getElementById("pageSearch").value || "").toLowerCase().trim();
    const cards = document.querySelectorAll(".event-card");
    let shown   = 0;
    cards.forEach(c => {
        const vis = (activeFilter === "all" || c.dataset.status === activeFilter) &&
                    (!q || c.dataset.title.includes(q));
        c.style.display = vis ? "" : "none";
        if (vis) shown++;
    });
    const nr = document.getElementById("noResults");
    if (nr) nr.classList.toggle("hidden", shown > 0);
}


/* ══════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════ */
function fileToBase64(file) {
    return new Promise(resolve => {
        const r = new FileReader();
        r.onload = e => resolve(e.target.result.split(',')[1]);
        r.readAsDataURL(file);
    });
}
function captureVideoFrame() {
    const video = document.querySelector('#interactive-scanner video');
    if (!video || !video.videoWidth) return null;
    const cv  = document.createElement('canvas');
    cv.width  = Math.min(video.videoWidth, 640);
    cv.height = Math.round(cv.width * (video.videoHeight / video.videoWidth));
    cv.getContext('2d').drawImage(video, 0, 0, cv.width, cv.height);
    return cv.toDataURL('image/jpeg', 0.72).split(',')[1];
}


/* ══════════════════════════════════════════════════════════════
   PROOF CAMERA OVERLAY
   ─────────────────────────────────────────────────────────────
   Uses navigator.mediaDevices.getUserMedia so it works on:
     • Desktop / laptop  (webcam, any browser)
     • Mobile            (rear camera preferred via 'environment')
   Falls back to the file picker if getUserMedia is denied or
   unavailable.
══════════════════════════════════════════════════════════════ */
let proofCameraStream = null;

function openProofCamera() {
    const overlay = document.getElementById('proofCameraOverlay');

    // Safety net: if the overlay HTML is somehow missing, fall back to file picker
    if (!overlay) {
        document.getElementById('manualProofInput').click();
        return;
    }

    // Show the overlay
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');

    const video  = document.getElementById('proofCameraVideo');
    const hint   = document.getElementById('proofCameraHint');
    const errBox = document.getElementById('proofCameraError');
    const capBtn = document.getElementById('proofCaptureBtn');

    // Reset state
    if (errBox) errBox.classList.add('hidden');
    if (capBtn) capBtn.disabled = true;
    if (hint)   hint.textContent = 'Starting camera…';

    // ── Try rear camera first (ideal for mobile), then any camera (desktop) ──
    const rearConstraints = {
        video: {
            facingMode: { ideal: 'environment' },
            width:  { ideal: 1280 },
            height: { ideal: 720 }
        },
        audio: false
    };
    const anyConstraints = { video: true, audio: false };

    navigator.mediaDevices.getUserMedia(rearConstraints)
        .then(stream  => _startProofStream(stream, hint, capBtn, video))
        .catch(() =>
            navigator.mediaDevices.getUserMedia(anyConstraints)
                .then(stream  => _startProofStream(stream, hint, capBtn, video))
                .catch(err => {
                    console.warn('Proof camera unavailable:', err);
                    if (hint)   hint.textContent = 'Camera unavailable';
                    if (capBtn) capBtn.disabled  = true;
                    if (errBox) errBox.classList.remove('hidden');
                })
        );
}

function _startProofStream(stream, hint, capBtn, video) {
    proofCameraStream  = stream;
    video.srcObject    = stream;
    video.play();
    if (hint)   hint.textContent = 'Position the student in frame, then tap Capture';
    if (capBtn) capBtn.disabled  = false;
}

/**
 * Snap a still from the live proof camera, store as base64, close overlay.
 */
function captureProofPhoto() {
    const video = document.getElementById('proofCameraVideo');
    if (!video || !video.videoWidth) return;

    const canvas  = document.createElement('canvas');
    canvas.width  = Math.min(video.videoWidth, 640);
    canvas.height = Math.round(canvas.width * (video.videoHeight / video.videoWidth));
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    const dataUrl  = canvas.toDataURL('image/jpeg', 0.82);
    manualProofB64 = dataUrl.split(',')[1];

    // Show the thumbnail inside the proof zone
    document.getElementById('manualProofImg').src = dataUrl;
    document.getElementById('manualProofPlaceholder').classList.add('hidden');
    document.getElementById('manualProofPreview').classList.remove('hidden');
    document.getElementById('manualProofZone').classList.add('captured');

    closeProofCamera();
}

/**
 * Stop the camera stream and hide the overlay.
 */
function closeProofCamera() {
    if (proofCameraStream) {
        proofCameraStream.getTracks().forEach(t => t.stop());
        proofCameraStream = null;
    }
    const overlay = document.getElementById('proofCameraOverlay');
    if (overlay) {
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
    }
}

/**
 * Called by the "Browse" button inside the proof camera overlay.
 * Closes the camera and opens the OS file picker as a fallback.
 */
function proofFallbackFilePicker() {
    closeProofCamera();
    document.getElementById('manualProofInput').click();
}

/**
 * Clear the captured proof photo and reset the zone to its empty state.
 */
function resetManualProof() {
    manualProofB64 = null;
    const input = document.getElementById('manualProofInput');
    if (input) input.value = '';
    const placeholder = document.getElementById('manualProofPlaceholder');
    const preview     = document.getElementById('manualProofPreview');
    const zone        = document.getElementById('manualProofZone');
    if (placeholder) placeholder.classList.remove('hidden');
    if (preview)     preview.classList.add('hidden');
    if (zone)        { zone.classList.remove('captured'); zone.style.borderColor = ''; zone.style.background = ''; }
}

/**
 * Handles the hidden file input — used only when the user taps "Browse" or
 * getUserMedia is unavailable (proofFallbackFilePicker).
 */
function handleManualProof(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    fileToBase64(file).then(b64 => { manualProofB64 = b64; });
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('manualProofImg').src = e.target.result;
        document.getElementById('manualProofPlaceholder').classList.add('hidden');
        document.getElementById('manualProofPreview').classList.remove('hidden');
        document.getElementById('manualProofZone').classList.add('captured');
    };
    reader.readAsDataURL(file);
}


/* ══════════════════════════════════════════════════════════════
   QR SCANNER MODAL
══════════════════════════════════════════════════════════════ */
let html5QrCode, currentEventId = null;

function isMobileHttp() {
    return /iPhone|iPad|iPod|Android/i.test(navigator.userAgent) &&
           location.protocol === "http:" && !location.hostname.includes("localhost");
}

function setScanType(type) {
    document.getElementById("qrScanTypeHidden").value = type;
    const l = document.getElementById("qrBtnLogin");
    const o = document.getElementById("qrBtnLogout");
    if (type === "login") { l.className = "scan-pill-btn active-login";  o.className = "scan-pill-btn"; }
    else                  { l.className = "scan-pill-btn"; o.className = "scan-pill-btn active-logout"; }
}

function scanFeedback(type, msg) {
    const el  = document.getElementById("scan-feedback");
    const map = { idle:"scan-feedback-bar sfb-idle", busy:"scan-feedback-bar sfb-busy",
                  success:"scan-feedback-bar sfb-success", error:"scan-feedback-bar sfb-error",
                  warn:"scan-feedback-bar sfb-warn" };
    el.className = map[type] || map.idle;
    el.innerHTML = msg;
}

function addScanLog(name, type, status) {
    const log   = document.getElementById("scanLog");
    const empty = log.querySelector("p");
    if (empty) empty.remove();
    const div = document.createElement("div");
    div.className = "scan-log-item";
    let bCls, bTxt;
    if (status === "success") { bCls = type === "login" ? "badge-in" : "badge-out"; bTxt = type === "login" ? "IN" : "OUT"; }
    else if (status === "warn") { bCls = "badge-warn"; bTxt = type === "login" ? "DUP↑" : "DUP↓"; }
    else { bCls = "badge-err"; bTxt = "ERR"; }
    const t = new Date().toLocaleTimeString([], { hour:"2-digit", minute:"2-digit" });
    div.innerHTML = `<span class="scan-log-badge ${bCls}">${bTxt}</span>
        <span class="flex-1 text-xs font-medium truncate" style="color:rgba(255,255,255,.75);">${name}</span>
        <span class="text-[11px] flex-shrink-0" style="color:rgba(255,255,255,.3);">${t}</span>`;
    log.prepend(div);
}

async function openScanner(title, eventId) {
    currentEventId = eventId;
    document.getElementById("scannerTitle").textContent = title;
    scanFeedback("idle", '<i class="fas fa-wifi text-xs opacity-50 mr-2"></i>Ready to scan…');
    document.getElementById("photoUploadContent").classList.remove("hidden");
    document.getElementById("photoPreview").classList.add("hidden");
    document.getElementById("qrPhotoInput").value = "";
    document.getElementById("scanLog").innerHTML =
        '<p class="text-xs text-center py-3" style="color:rgba(255,255,255,.2);">No scans yet this session</p>';

    showModal("scannerModal");
    setScanType("login");

    if (isMobileHttp()) {
        document.getElementById("liveScannerArea").classList.add("hidden");
        document.getElementById("photoUploadArea").classList.remove("hidden");
        document.getElementById("scannerSub").textContent = "Take a photo of the QR code";
        return;
    }

    document.getElementById("liveScannerArea").classList.remove("hidden");
    document.getElementById("photoUploadArea").classList.add("hidden");
    document.getElementById("scannerSub").textContent = "Align QR code within the frame";

    try {
        html5QrCode = new Html5Qrcode("interactive-scanner");
        await html5QrCode.start(
            { facingMode: "environment" },
            { fps:15, qrbox:(w,h)=>{ const s=Math.floor(Math.min(w,h)*.85); return{width:s,height:s}; },
              aspectRatio:1.0,
              videoConstraints:{ facingMode:{ideal:"environment"}, width:{ideal:1280,min:640}, height:{ideal:720,min:480} },
              rememberLastUsedCamera:true, showTorchButtonIfSupported:true },
            async decoded => {
                const proofB64 = captureVideoFrame();
                html5QrCode.pause(true);
                const scanType = document.getElementById("qrScanTypeHidden").value;
                scanFeedback("busy", '<i class="fas fa-spinner fa-spin mr-2"></i>Processing…');
                const result = await postScan(decoded, scanType, proofB64);
                if (result.success) {
                    scanFeedback("success", `<i class="fas fa-check-circle mr-2"></i>${result.scan_type==="login"?"Logged In":"Logged Out"}: <strong>${result.name}</strong>`);
                    addScanLog(result.name, result.scan_type, "success");
                } else if (result.already) {
                    const verb = result.state === "login" ? "logged in" : "logged out";
                    scanFeedback("warn", `<i class="fas fa-clock-rotate-left mr-2"></i><strong>${result.name}</strong> already ${verb} at ${result.time}`);
                    addScanLog(result.name, result.state, "warn");
                } else {
                    scanFeedback("error", `<i class="fas fa-exclamation-circle mr-2"></i>${result.message||"Error"}`);
                    addScanLog(result.message||"Error", scanType, "error");
                }
                setTimeout(() => { scanFeedback("idle",'<i class="fas fa-wifi text-xs opacity-50 mr-2"></i>Ready for next scan…'); html5QrCode.resume(); }, 3000);
            }
        );
    } catch (err) {
        scanFeedback("error", '<i class="fas fa-exclamation-circle mr-2"></i>Camera error · Try Manual Entry instead');
    }
}

async function postScan(qrValue, scanType, proofB64 = null) {
    const fd = new FormData();
    fd.append("ajax_scan","1"); fd.append("qr_value",qrValue);
    fd.append("event_id",currentEventId); fd.append("scan_type",scanType);
    if (proofB64) fd.append("proof_image", proofB64);
    try { const res = await fetch(window.location.href,{method:"POST",body:fd}); return await res.json(); }
    catch (e) { return { success:false, message:"Network error" }; }
}

async function handlePhotoScan(input) {
    if (!input.files || !input.files[0]) return;
    const photoFile = input.files[0];
    const proofB64  = await fileToBase64(photoFile);
    const reader    = new FileReader();
    reader.onload = e => {
        document.getElementById("previewImage").src = e.target.result;
        document.getElementById("photoUploadContent").classList.add("hidden");
        document.getElementById("photoPreview").classList.remove("hidden");
    };
    reader.readAsDataURL(photoFile);
    scanFeedback("busy", '<i class="fas fa-spinner fa-spin mr-2"></i>Scanning QR from photo…');
    try {
        const tmp    = new Html5Qrcode("temp-qr-scanner");
        const result = await tmp.scanFile(photoFile, false);
        const sType  = document.getElementById("qrScanTypeHidden").value;
        const data   = await postScan(result, sType, proofB64);
        if (data.success) {
            scanFeedback("success",`<i class="fas fa-check-circle mr-2"></i>${data.scan_type==="login"?"Logged In":"Logged Out"}: <strong>${data.name}</strong>`);
            addScanLog(data.name, data.scan_type, "success");
        } else if (data.already) {
            const verb = data.state==="login"?"logged in":"logged out";
            scanFeedback("warn",`<i class="fas fa-clock-rotate-left mr-2"></i><strong>${data.name}</strong> already ${verb} at ${data.time}`);
            addScanLog(data.name, data.state, "warn");
        } else {
            scanFeedback("error",`<i class="fas fa-exclamation-circle mr-2"></i>${data.message||"QR not recognized"}`);
            addScanLog(data.message||"Unrecognized", sType, "error");
        }
    } catch (e) {
        scanFeedback("error",'<i class="fas fa-exclamation-circle mr-2"></i>No QR found in photo – try again');
    }
    setTimeout(()=>{
        scanFeedback("idle",'<i class="fas fa-wifi text-xs opacity-50 mr-2"></i>Ready to scan…');
        document.getElementById("photoUploadContent").classList.remove("hidden");
        document.getElementById("photoPreview").classList.add("hidden");
        input.value="";
    },3500);
}

function closeScanner() {
    const doClose = () => hideModal("scannerModal");
    if (html5QrCode) {
        Promise.race([html5QrCode.stop(), new Promise(r=>setTimeout(r,1000))])
            .finally(()=>{ html5QrCode=null; doClose(); });
    } else doClose();
}


/* ══════════════════════════════════════════════════════════════
   MANUAL ENTRY MODAL
══════════════════════════════════════════════════════════════ */
let manualInputMode = "qr";
let manualProofB64  = null;

function setManualType(type) {
    document.getElementById("manualTypeHidden").value = type;
    const btnL = document.getElementById("mBtnLogin");
    const btnO = document.getElementById("mBtnLogout");
    const lbl  = document.getElementById("manualBtnLabel");
    const btn  = document.getElementById("manualSubmitBtn");
    if (type === "login") {
        btnL.className="manual-pill-btn active-login"; btnO.className="manual-pill-btn";
        lbl.textContent="Record Login"; btn.className="manual-submit-btn btn-login";
    } else {
        btnL.className="manual-pill-btn"; btnO.className="manual-pill-btn active-logout";
        lbl.textContent="Record Logout"; btn.className="manual-submit-btn btn-logout";
    }
}

function switchInputTab(mode) {
    manualInputMode = mode;
    document.getElementById("tabQR").classList.toggle("active-tab", mode==="qr");
    document.getElementById("tabSN").classList.toggle("active-tab", mode==="sn");
    document.getElementById("panelQR").classList.toggle("hidden", mode!=="qr");
    document.getElementById("panelSN").classList.toggle("hidden", mode!=="sn");
    setTimeout(()=>document.getElementById(mode==="qr"?"qr_value_input":"sn_value_input").focus(), 80);
}

function openManual(title, eventId) {
    currentEventId = eventId;
    document.getElementById("manualTitle").textContent = title;
    document.getElementById("qr_value_input").value   = "";
    document.getElementById("sn_value_input").value   = "";
    document.getElementById("manual-feedback").style.opacity = "0";
    document.getElementById("manualLog").innerHTML =
        '<p class="text-xs text-center py-2 text-gray-300 dark:text-gray-600">No entries yet</p>';
    resetManualProof();
    showModal("manualModal");
    setManualType("login");
    switchInputTab("qr");
}

function closeManual() {
    closeProofCamera();
    hideModal("manualModal");
}

function addManualLog(value, name, type, status) {
    const log   = document.getElementById("manualLog");
    const empty = log.querySelector("p");
    if (empty) empty.remove();
    const div = document.createElement("div");
    div.className = "flex items-center gap-2 px-3 py-2 rounded-lg border text-xs";
    let bgStyle, iconCls, iconColor, displayName;
    if (status === "success") {
        bgStyle="background:rgba(34,197,94,.05); border-color:rgba(34,197,94,.2);";
        iconCls=type==="login"?"fa-sign-in-alt":"fa-sign-out-alt"; iconColor=type==="login"?"#16a34a":"#d97706"; displayName=name;
    } else if (status === "warn") {
        bgStyle="background:rgba(251,191,36,.06); border-color:rgba(251,191,36,.25);";
        iconCls="fa-clock-rotate-left"; iconColor="#d97706"; displayName=name+" (duplicate)";
    } else {
        bgStyle="background:rgba(239,68,68,.05); border-color:rgba(239,68,68,.2);";
        iconCls="fa-times-circle"; iconColor="#dc2626"; displayName=value||"Unknown";
    }
    div.style.cssText = bgStyle+" animation:fadeUp .3s ease both;";
    const t = new Date().toLocaleTimeString([],{hour:"2-digit",minute:"2-digit"});
    div.innerHTML = `<i class="fas ${iconCls} flex-shrink-0" style="color:${iconColor};font-size:11px;"></i>
        <span class="flex-1 truncate font-medium text-gray-700 dark:text-gray-300">${displayName}</span>
        <span class="flex-shrink-0 text-gray-400" style="font-size:10px;">${t}</span>`;
    log.prepend(div);
}

async function submitManual() {
    const mode     = manualInputMode;
    const rawVal   = mode === "qr"
        ? document.getElementById("qr_value_input").value.trim()
        : document.getElementById("sn_value_input").value.trim();
    const scanType = document.getElementById("manualTypeHidden").value;
    const fb       = document.getElementById("manual-feedback");
    const fbText   = document.getElementById("manualFeedbackText");

    fb.style.opacity = "1";
    function setFB(cls, html) { fb.className="manual-feedback-bar "+cls; fbText.innerHTML=html; }

    if (!rawVal) {
        setFB("mfb-error",'<i class="fas fa-exclamation-circle"></i> Please enter a QR value or student number');
        return;
    }

    if (!manualProofB64) {
        setFB("mfb-error",'<i class="fas fa-camera-slash"></i> Capture the student\'s photo first — tap the camera zone above');
        const zone = document.getElementById('manualProofZone');
        if (zone) {
            zone.style.borderColor = 'rgba(239,68,68,.65)';
            zone.style.background  = 'rgba(239,68,68,.04)';
            setTimeout(()=>{ zone.style.borderColor=''; zone.style.background=''; }, 1800);
        }
        return;
    }

    setFB("mfb-busy",'<i class="fas fa-spinner fa-spin"></i> Processing…');
    const data = await postScan(rawVal, scanType, manualProofB64);

    if (data.success) {
        setFB("mfb-success",`<i class="fas fa-check-circle"></i> ${data.scan_type==="login"?"Logged In":"Logged Out"}: <strong>${data.name}</strong>`);
        addManualLog(rawVal, data.name, data.scan_type, "success");
        document.getElementById("qr_value_input").value = "";
        document.getElementById("sn_value_input").value = "";
        resetManualProof();
        setTimeout(()=>document.getElementById(mode==="qr"?"qr_value_input":"sn_value_input").focus(), 50);
    } else if (data.already) {
        const verb = data.state==="login"?"logged in":"logged out";
        setFB("mfb-warn",`<i class="fas fa-clock-rotate-left"></i> <strong>${data.name}</strong> already ${verb} at ${data.time}`);
        addManualLog(rawVal, data.name, data.state, "warn");
    } else {
        setFB("mfb-error",`<i class="fas fa-exclamation-circle"></i> ${data.message||"Error"}`);
        addManualLog(rawVal, "", scanType, "error");
    }
}


/* ── Modal helpers ── */
function showModal(id) { const m=document.getElementById(id); m.classList.remove("hidden"); m.classList.add("flex"); document.body.style.overflow="hidden"; }
function hideModal(id) { const m=document.getElementById(id); m.classList.add("hidden"); m.classList.remove("flex"); document.body.style.overflow=""; }

/* ── Keyboard shortcuts ── */
document.getElementById("qr_value_input").addEventListener("keydown", e=>{ if(e.key==="Enter") submitManual(); });
document.getElementById("sn_value_input").addEventListener("keydown", e=>{ if(e.key==="Enter") submitManual(); });

document.addEventListener("keydown", e => {
    if (e.key !== "Escape") return;
    // 1. Close proof camera overlay first (highest z-index)
    const cam = document.getElementById('proofCameraOverlay');
    if (cam && !cam.classList.contains('hidden')) { closeProofCamera(); return; }
    // 2. Then QR scanner modal
    if (!document.getElementById("scannerModal").classList.contains("hidden")) { closeScanner(); return; }
    // 3. Then manual entry modal
    if (!document.getElementById("manualModal").classList.contains("hidden"))  closeManual();
});