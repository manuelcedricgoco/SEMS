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
    if (
        s === "dark" ||
        (!s && window.matchMedia("(prefers-color-scheme:dark)").matches)
    )
        applyTheme(true);
})();

/* ── Sidebar ── */
const sidebar = document.getElementById("sidebar");
const sbOverlay = document.getElementById("sb-overlay");

function openSidebar() {
    sidebar.classList.remove("-translate-x-full");
    sbOverlay.classList.add("show");
}

function closeSidebar() {
    sidebar.classList.add("-translate-x-full");
    sbOverlay.classList.remove("show");
}

/* ── Photo upload ── */
function handlePhotoSelect(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (file.size > 2 * 1024 * 1024) {
        alert("File must be under 2 MB.");
        input.value = "";
        return;
    }
    const reader = new FileReader();
    reader.onload = (e) => {
        document.getElementById("photoPreviewImg").src = e.target.result;
        document.getElementById("photoPreviewWrap").classList.remove("hidden");
        document.getElementById("uploadPlaceholder").classList.add("hidden");
        document.getElementById("photoFileName").textContent = file.name;
        document.getElementById("photoFileName").classList.remove("hidden");
        document.getElementById("uploadZone").classList.add("has-file");
        /* sync header avatar */
        const img = document.getElementById("avatarImg");
        const ini = document.getElementById("avatarInitials");
        if (img) {
            img.src = e.target.result;
            img.classList.remove("hidden");
        }
        if (ini) ini.classList.add("hidden");
    };
    reader.readAsDataURL(file);
}

function resetPhotoPreview() {
    document.getElementById("profile_image").value = "";
    document.getElementById("photoPreviewWrap").classList.add("hidden");
    document.getElementById("photoPreviewImg").src = "";
    document.getElementById("uploadPlaceholder").classList.remove("hidden");
    document.getElementById("photoFileName").classList.add("hidden");
    document.getElementById("uploadZone").classList.remove("has-file");
}

/* ── Logo upload ── */
function handleLogoSelect(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (file.size > 5 * 1024 * 1024) {
        alert("Logo must be under 5 MB.");
        input.value = "";
        return;
    }
    const reader = new FileReader();
    reader.onload = (e) => {
        document.getElementById("logoPreviewImg").src = e.target.result;
        document.getElementById("logoPreviewWrap").classList.remove("hidden");
        document.getElementById("currentLogoBox").classList.add("hidden");
        document.getElementById("logoPlaceholder").classList.add("hidden");
        document.getElementById("logoFileName").textContent = file.name;
        document.getElementById("logoFileName").classList.remove("hidden");
        document.getElementById("logoZone").classList.add("has-file");
    };
    reader.readAsDataURL(file);
}

function resetLogoPreview() {
    document.getElementById("org_logo").value = "";
    document.getElementById("logoPreviewWrap").classList.add("hidden");
    document.getElementById("logoPreviewImg").src = "";
    document.getElementById("currentLogoBox").classList.remove("hidden");
    document.getElementById("logoFileName").classList.add("hidden");
    document.getElementById("logoZone").classList.remove("has-file");
}

/* ── Password visibility ── */
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const show = btn.querySelector(".eye-show");
    const hide = btn.querySelector(".eye-hide");
    if (input.type === "password") {
        input.type = "text";
        show.classList.add("hidden");
        hide.classList.remove("hidden");
    } else {
        input.type = "password";
        hide.classList.add("hidden");
        show.classList.remove("hidden");
    }
}

/* ── Password strength ── */
function setReq(id, met) {
    const el = document.getElementById(id);
    const icon = el.querySelector("i");
    el.className = "req-item " + (met ? "text-brand-500" : "text-gray-400");
    icon.className = met ? "fas fa-check" : "fas fa-circle text-[6px]";
}

function checkStrength(pw) {
    const bar = document.getElementById("strengthBar");
    const label = document.getElementById("strengthLabel");
    const hasLen = pw.length >= 8;
    const hasUp = /[A-Z]/.test(pw);
    const hasNum = /[0-9]/.test(pw);
    const hasSym = /[^A-Za-z0-9]/.test(pw);
    setReq("req-len", hasLen);
    setReq("req-up", hasUp);
    setReq("req-num", hasNum);
    setReq("req-sym", hasSym);
    const score = [hasLen, hasUp, hasNum, hasSym].filter(Boolean).length;
    const levels = [
        {
            w: "0%",
            c: "#e5e7eb",
            t: "",
            tc: "#9ca3af",
        },
        {
            w: "25%",
            c: "#ef4444",
            t: "Weak",
            tc: "#ef4444",
        },
        {
            w: "50%",
            c: "#f59e0b",
            t: "Fair",
            tc: "#f59e0b",
        },
        {
            w: "75%",
            c: "#3b82f6",
            t: "Good",
            tc: "#3b82f6",
        },
        {
            w: "100%",
            c: "#22c55e",
            t: "Strong",
            tc: "#22c55e",
        },
    ];
    const lvl = pw.length === 0 ? levels[0] : levels[Math.min(score, 4)];
    bar.style.width = lvl.w;
    bar.style.background = lvl.c;
    label.textContent = lvl.t;
    label.style.color = lvl.tc;
    checkMatch();
}

/* ── Password match ── */
function checkMatch() {
    const np = document.getElementById("new_password").value;
    const cp = document.getElementById("confirm_password").value;
    const mt = document.getElementById("matchText");
    const btn = document.getElementById("pwSubmitBtn");
    if (!cp) {
        mt.classList.add("hidden");
        if (btn) btn.disabled = false;
        return;
    }
    mt.classList.remove("hidden");
    if (cp === np) {
        mt.textContent = "✓ Passwords match";
        mt.style.color = "#22c55e";
        if (btn) btn.disabled = false;
    } else {
        mt.textContent = "✗ Passwords do not match";
        mt.style.color = "#ef4444";
        if (btn) btn.disabled = true;
    }
}

/* ── Reset password form ── */
function resetPwForm() {
    const bar = document.getElementById("strengthBar");
    if (bar) {
        bar.style.width = "0";
        bar.style.background = "#e5e7eb";
    }
    const lbl = document.getElementById("strengthLabel");
    if (lbl) {
        lbl.textContent = "";
    }
    const mt = document.getElementById("matchText");
    if (mt) {
        mt.classList.add("hidden");
    }
    const btn = document.getElementById("pwSubmitBtn");
    if (btn) btn.disabled = false;
    ["req-len", "req-up", "req-num", "req-sym"].forEach((id) =>
        setReq(id, false),
    );
}
