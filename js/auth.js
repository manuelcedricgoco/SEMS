// ═══════════════════════════════════════════════════════════
// SEMS - School Event Management System
// auth.js — Authentication Page Script
//
// Depends on the following globals injected inline by PHP:
//   window.SEMS_DATA = {
//     initialPanel, takenByOrg, takenByClub,
//     orgsWithLogo, clubsWithLogo
//   }
// ═══════════════════════════════════════════════════════════

(function () {
    "use strict";

    // ── PHP-injected data (set inline before this file loads) ──
    const DATA            = window.SEMS_DATA || {};
    const initialPanel    = DATA.initialPanel   || "login";
    const TAKEN_BY_ORG    = DATA.takenByOrg     || {};
    const TAKEN_BY_CLUB   = DATA.takenByClub    || {};
    const ORGS_WITH_LOGO  = DATA.orgsWithLogo   || [];
    const CLUBS_WITH_LOGO = DATA.clubsWithLogo  || [];

    // ── Constants ───────────────────────────────────────────
    const SSG_ORG_ID      = "1";
    const MULTI_POSITIONS = ["Councilor", "Board Members"];
    const MULTI_LIMIT     = 7;
    const MAX_SIZE        = 2 * 1024 * 1024; // 2 MB
    const REMEMBER_KEY    = "sems_remembered_emails";
    const MAX_REMEMBERED  = 5;

    // Student number: YY-N-NNNNN  e.g. 24-1-05560
    const SNUM_REGEX  = /^\d{2}-\d{1}-\d{5}$/;
    const SNUM_MAXLEN = 10;

    // ── Element references ───────────────────────────────────
    const card         = document.getElementById("authCard");
    const panelHeading = document.getElementById("panelHeading");
    const panelSub     = document.getElementById("panelSub");
    const panelBtn     = document.getElementById("panelBtn");
    const loginSec     = document.getElementById("loginSection");
    const registerSec  = document.getElementById("registerSection");
    const formScroll   = document.getElementById("formScroll");

    // ═══════════════════════════════════════════════════════
    // Panel switching
    // ═══════════════════════════════════════════════════════
    function switchToLogin() {
        loginSec.classList.remove("hidden");
        registerSec.classList.add("hidden");
        loginSec.classList.remove("slide-right", "slide-left");
        void loginSec.offsetWidth;
        loginSec.classList.add("slide-left");
        card.classList.add("show-login");
        panelHeading.textContent = "Hello, Friend!";
        panelSub.innerHTML       = "Enter your personal details<br>and start your journey with us";
        panelBtn.textContent     = "Sign Up";
        panelBtn.onclick         = switchToRegister;
        formScroll.scrollTop     = 0;
    }

    function switchToRegister() {
        loginSec.classList.add("hidden");
        registerSec.classList.remove("hidden");
        registerSec.classList.remove("slide-right", "slide-left");
        void registerSec.offsetWidth;
        registerSec.classList.add("slide-right");
        card.classList.remove("show-login");
        panelHeading.textContent = "Welcome Back!";
        panelSub.innerHTML       = "To keep connected with us please<br>login with your personal info";
        panelBtn.textContent     = "Sign In";
        panelBtn.onclick         = switchToLogin;
        formScroll.scrollTop     = 0;
    }

    window.switchToLogin    = switchToLogin;
    window.switchToRegister = switchToRegister;

    if (initialPanel === "register") switchToRegister();
    else switchToLogin();

    // ═══════════════════════════════════════════════════════
    // Inline alert helper
    // ═══════════════════════════════════════════════════════
    function showInlineAlert(htmlMessage, type) {
        const existing = document.getElementById("jsInlineAlert");
        if (existing) existing.remove();

        const div       = document.createElement("div");
        div.id          = "jsInlineAlert";
        div.className   = "alert " + (type === "error" ? "alert-error" : "alert-success");
        div.innerHTML   =
            '<i class="fa-solid ' +
            (type === "error" ? "fa-triangle-exclamation" : "fa-circle-check") +
            '"></i> ' + htmlMessage;

        const section = document.getElementById("registerSection");
        section.insertBefore(div, section.firstChild);

        if (formScroll) formScroll.scrollTop = 0;

        setTimeout(function () {
            if (div.parentNode) div.remove();
        }, 8000);
    }

    // ═══════════════════════════════════════════════════════
    // Student Number — mask + FORMAT validation + DB check
    //
    // BUG FIX: Previously, "Valid student number ✓" was shown
    // purely on regex format match — it never checked the DB.
    // Now, after the format passes, an AJAX call is made to
    // /check_snum.php to verify uniqueness before showing green.
    // ═══════════════════════════════════════════════════════
    function initStudentNumberMask() {
        const input = document.getElementById("studentNumInput");
        if (!input) return;

        // Track the last AJAX-checked value so we don't re-check
        // the same number on every keystroke.
        let lastChecked   = "";   // last value sent to the server
        let checkDebounce = null; // debounce timer handle

        // ── Mask helper ──────────────────────────────────────
        function applyMask(digits) {
            digits = digits.slice(0, 8);
            if (digits.length <= 2)      return digits;
            else if (digits.length === 3) return digits.slice(0, 2) + "-" + digits.slice(2);
            else return digits.slice(0, 2) + "-" + digits.slice(2, 3) + "-" + digits.slice(3);
        }

        const hintEl = document.getElementById("snumFormatHint");

        // ── Three visual states ──────────────────────────────
        function setNeutral() {
            const wrap = input.closest(".input-wrap");
            if (wrap) { wrap.style.borderColor = ""; wrap.style.boxShadow = ""; }
            if (hintEl) {
                hintEl.style.color = "#c7d0dd";
                hintEl.innerHTML   = '<i class="fa-solid fa-circle-info" style="font-size:10px;"></i> Format: YY-N-NNNNN &nbsp;(e.g.&nbsp;24-1-05560)';
            }
        }

        function setChecking() {
            const wrap = input.closest(".input-wrap");
            if (wrap) { wrap.style.borderColor = "#f59e0b"; wrap.style.boxShadow = "0 0 0 3px rgba(245,158,11,0.12)"; }
            if (hintEl) {
                hintEl.style.color = "#f59e0b";
                hintEl.innerHTML   = '<i class="fa-solid fa-spinner fa-spin" style="font-size:10px;"></i> Checking availability…';
            }
        }

        function setValid() {
            const wrap = input.closest(".input-wrap");
            if (wrap) { wrap.style.borderColor = "#10b981"; wrap.style.boxShadow = "0 0 0 3px rgba(16,185,129,0.12)"; }
            if (hintEl) {
                hintEl.style.color = "#10b981";
                hintEl.innerHTML   = '<i class="fa-solid fa-circle-check" style="font-size:10px;"></i> Valid &amp; available ✓';
            }
        }

        function setFormatError() {
            const wrap = input.closest(".input-wrap");
            if (wrap) { wrap.style.borderColor = "#ef4444"; wrap.style.boxShadow = "0 0 0 3px rgba(239,68,68,0.10)"; }
            if (hintEl) {
                hintEl.style.color = "#ef4444";
                hintEl.innerHTML   = '<i class="fa-solid fa-circle-xmark" style="font-size:10px;"></i> Format: YY-N-NNNNN &nbsp;(e.g.&nbsp;24-1-05560)';
            }
        }

        // ── FIX: setTaken — shows red "already exists" state ──
        function setTaken(message) {
            const wrap = input.closest(".input-wrap");
            if (wrap) { wrap.style.borderColor = "#ef4444"; wrap.style.boxShadow = "0 0 0 3px rgba(239,68,68,0.10)"; }
            if (hintEl) {
                hintEl.style.color = "#ef4444";
                hintEl.innerHTML   =
                    '<i class="fa-solid fa-circle-xmark" style="font-size:10px;"></i> ' +
                    (message || "Student number already exists.");
            }
            // Also show top-of-form alert so it's unmissable
            showInlineAlert(
                "Student number <strong>" + input.value + "</strong> already exists. " +
                (message || ""),
                "error"
            );
        }

        // ── AJAX uniqueness check ────────────────────────────
        // Called after format passes; debounced 600 ms to avoid
        // flooding the server while the user is still typing.
        function checkUniqueness(value) {
            if (value === lastChecked) return; // skip if same value
            lastChecked = value;
            setChecking();

            fetch("/check_snum.php?snum=" + encodeURIComponent(value))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    // Make sure the input hasn't changed while we were waiting
                    if (input.value !== value) return;

                    if (data.available) {
                        setValid();
                        // Dismiss top-level alert if it was about this number
                        const alert = document.getElementById("jsInlineAlert");
                        if (alert) alert.remove();
                    } else {
                        setTaken(data.message || "");
                    }
                })
                .catch(function () {
                    // Network/server error — fall back to format-only (green)
                    // so the user can still submit; PHP will catch it server-side.
                    if (input.value === value) setValid();
                });
        }

        // ── Master validate: format first, then DB ───────────
        function validateAndTriggerCheck(value) {
            clearTimeout(checkDebounce);

            if (!value) {
                lastChecked = "";
                setNeutral();
                return;
            }

            if (!SNUM_REGEX.test(value)) {
                lastChecked = "";
                setFormatError();
                return;
            }

            // Format is OK — debounce the AJAX call
            checkDebounce = setTimeout(function () {
                checkUniqueness(value);
            }, 600);
        }

        // ── Keystroke guard ──────────────────────────────────
        input.addEventListener("keydown", function (e) {
            const nav = ["Backspace","Delete","ArrowLeft","ArrowRight","Home","End","Tab"];
            if (nav.includes(e.key) || e.ctrlKey || e.metaKey) return;
            if (!/^\d$/.test(e.key)) e.preventDefault();
        });

        // ── Auto-mask on input ───────────────────────────────
        input.addEventListener("input", function () {
            const cursorWasAtEnd = (this.selectionStart === this.value.length);
            const digits  = this.value.replace(/\D/g, "");
            const masked  = applyMask(digits);
            this.value    = masked;
            if (cursorWasAtEnd) this.selectionStart = this.selectionEnd = masked.length;
            validateAndTriggerCheck(masked);
        });

        // ── Paste ────────────────────────────────────────────
        input.addEventListener("paste", function (e) {
            e.preventDefault();
            const raw    = (e.clipboardData || window.clipboardData).getData("text");
            const digits = raw.replace(/\D/g, "");
            this.value   = applyMask(digits);
            validateAndTriggerCheck(this.value);
        });

        // ── Blur: fire AJAX immediately (skip debounce) ──────
        input.addEventListener("blur", function () {
            const val = this.value;
            if (!val || !SNUM_REGEX.test(val)) {
                validateAndTriggerCheck(val);
                return;
            }
            clearTimeout(checkDebounce);
            checkUniqueness(val);
        });

        // ── Submit guard ─────────────────────────────────────
        const form = document.getElementById("registerForm");
        if (form) {
            form.addEventListener("submit", function (e) {
                const role = document.getElementById("roleSelect").value;
                if (role === "student" || role === "organizer") {
                    const val  = input.value.trim();
                    const wrap = input.closest(".input-wrap");

                    // Block if format is wrong
                    if (val && !SNUM_REGEX.test(val)) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        setFormatError();
                        showInlineAlert(
                            "Invalid student number format. Please use " +
                            "<strong>YY-N-NNNNN</strong> (e.g.&nbsp;24-1-05560).",
                            "error"
                        );
                        input.focus();
                        setTimeout(function () {
                            wrap.scrollIntoView({ behavior: "smooth", block: "center" });
                        }, 350);
                        return;
                    }

                    // Block if AJAX already determined it's taken
                    // (border is red AND value matches lastChecked taken value)
                    if (wrap && wrap.style.borderColor === "rgb(239, 68, 68)" && val === lastChecked) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        showInlineAlert(
                            "Student number <strong>" + val + "</strong> is already taken. " +
                            "Please use a different student number.",
                            "error"
                        );
                        input.focus();
                        setTimeout(function () {
                            wrap.scrollIntoView({ behavior: "smooth", block: "center" });
                        }, 350);
                    }
                }
            }, true);
        }

        // Run once on load
        validateAndTriggerCheck(input.value);
    }

    // ═══════════════════════════════════════════════════════
    // Position availability (organizer registration)
    // ═══════════════════════════════════════════════════════
    function updatePositionOptions() {
        const orgVal    = document.getElementById("orgSelect").value;
        const clubVal   = document.getElementById("clubSelect").value;
        const posSelect = document.getElementById("positionSelect");
        const hintBox   = document.getElementById("positionHintBox");
        const hintText  = document.getElementById("positionHintText");
        if (!posSelect) return;

        const isSSG        = orgVal === SSG_ORG_ID;
        const hasSelection = orgVal || clubVal;

        Array.from(posSelect.options).forEach(opt => {
            if (!opt.value) return;
            const group = opt.dataset.group;
            if (!hasSelection) {
                opt.hidden = true;
            } else if (isSSG) {
                opt.hidden = group === "std";
            } else {
                opt.hidden = group === "ssg";
            }
        });

        const cur = posSelect.options[posSelect.selectedIndex];
        if (cur && cur.hidden) posSelect.value = "";

        let takenMap = {};
        if (orgVal  && TAKEN_BY_ORG[orgVal])   takenMap = TAKEN_BY_ORG[orgVal];
        if (clubVal && TAKEN_BY_CLUB[clubVal])  takenMap = TAKEN_BY_CLUB[clubVal];

        let hasAnyTaken = false;
        const hints     = [];

        Array.from(posSelect.options).forEach(opt => {
            if (!opt.value || opt.hidden) return;
            if (!opt.dataset.orig) opt.dataset.orig = opt.value;
            const name  = opt.dataset.orig;
            const taken = takenMap[name] || 0;
            const limit = MULTI_POSITIONS.includes(name) ? MULTI_LIMIT : 1;
            const isFull = taken >= limit;

            opt.disabled = isFull;

            if (isFull) {
                opt.text    = name + "  ✗  Taken";
                hasAnyTaken = true;
                hints.push("<strong>" + name + "</strong>: full (" + taken + "/" + limit + ")");
            } else if (MULTI_POSITIONS.includes(name) && taken > 0) {
                opt.text = name + "  (" + taken + "/" + limit + " filled)";
            } else {
                opt.text = name;
            }
        });

        const cur2 = posSelect.options[posSelect.selectedIndex];
        if (cur2 && cur2.disabled) posSelect.value = "";

        if (hintBox && hintText) {
            if (hasAnyTaken && hasSelection) {
                hintBox.style.display = "block";
                hintText.innerHTML    =
                    "Already taken: " + hints.join(", ") +
                    ". <em>Councilor</em> &amp; <em>Board Members</em> can have up to " +
                    MULTI_LIMIT + " officers.";
            } else {
                hintBox.style.display = "none";
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // File / image helpers
    // ═══════════════════════════════════════════════════════
    function truncateName(name, max) {
        return name.length > max ? name.substring(0, max - 1) + "…" : name;
    }

    function handleImageSelect(input) {
        const container = document.getElementById("uploadContainer");
        const preview   = document.getElementById("imagePreview");
        const fileText  = document.getElementById("fileText");
        if (!input.files || !input.files[0]) return;
        if (input.files[0].size > MAX_SIZE) {
            alert("Image must be 2MB or smaller (server limit).");
            input.value = "";
            preview.classList.remove("show");
            container.classList.remove("has-image");
            fileText.innerHTML = "Click to upload photo<br><small>JPG, PNG (Max 2MB)</small>";
            return;
        }
        const reader = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.classList.add("show");
            container.classList.add("has-image");
            const shortName = truncateName(input.files[0].name, 28);
            fileText.innerHTML =
                '<span class="filename-display" style="color:#6366f1;font-weight:600;">' +
                shortName + '</span><br><small>Click to change photo</small>';
        };
        reader.readAsDataURL(input.files[0]);
    }

    function handleLogoPreview(input, previewId, containerId, textId, labelName) {
        const container = document.getElementById(containerId);
        const preview   = document.getElementById(previewId);
        const fileText  = document.getElementById(textId);
        if (!input.files || !input.files[0]) return;
        if (input.files[0].size > MAX_SIZE) {
            alert("Image must be 2MB or smaller (server limit).");
            input.value = "";
            preview.classList.remove("show");
            container.classList.remove("has-image");
            fileText.innerHTML = "Upload " + labelName + "<br><small>JPG, PNG (Max 2MB)</small>";
            return;
        }
        const reader = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.classList.add("show");
            container.classList.add("has-image");
            const shortName = truncateName(input.files[0].name, 28);
            fileText.innerHTML =
                '<span class="filename-display" style="color:#6366f1;font-weight:600;">' +
                shortName + '</span><br><small>Click to change</small>';
        };
        reader.readAsDataURL(input.files[0]);
    }

    function handleOrgLogo(input) {
        handleLogoPreview(input, "orgLogoPreview", "orgUploadContainer", "orgFileText", "organization logo");
    }

    function handleClubLogo(input) {
        handleLogoPreview(input, "clubLogoPreview", "clubUploadContainer", "clubFileText", "club logo");
    }

    window.handleImageSelect = handleImageSelect;
    window.handleOrgLogo     = handleOrgLogo;
    window.handleClubLogo    = handleClubLogo;

    // ═══════════════════════════════════════════════════════
    // Logo-exists notice
    // ═══════════════════════════════════════════════════════
    function applyLogoExistsNotice(type, selectedId) {
        const noticeEl   = document.getElementById(type + "LogoExistsNotice");
        const uploadArea = document.getElementById(type + "UploadContainer");
        const sourceList = type === "org" ? ORGS_WITH_LOGO : CLUBS_WITH_LOGO;
        const hasLogo    = selectedId && sourceList.includes(parseInt(selectedId, 10));

        if (noticeEl)   noticeEl.style.display = hasLogo ? "flex" : "none";
        if (uploadArea) {
            uploadArea.style.borderStyle = hasLogo ? "dashed" : "";
            uploadArea.style.borderColor = hasLogo ? "rgba(16,185,129,0.35)" : "";
            uploadArea.style.background  = hasLogo ? "rgba(16,185,129,0.04)" : "";
        }
    }

    function updateLogoVisibility() {
        const role    = document.getElementById("roleSelect").value;
        const orgVal  = document.getElementById("orgSelect").value;
        const clubVal = document.getElementById("clubSelect").value;
        const orgLogo  = document.getElementById("orgLogoWrap");
        const clubLogo = document.getElementById("clubLogoWrap");

        if (role !== "organizer") {
            orgLogo.classList.add("hidden-field");
            clubLogo.classList.add("hidden-field");
            return;
        }
        if (orgVal) {
            orgLogo.classList.remove("hidden-field");
            clubLogo.classList.add("hidden-field");
            applyLogoExistsNotice("org", orgVal);
        } else if (clubVal) {
            orgLogo.classList.add("hidden-field");
            clubLogo.classList.remove("hidden-field");
            applyLogoExistsNotice("club", clubVal);
        } else {
            orgLogo.classList.add("hidden-field");
            clubLogo.classList.add("hidden-field");
        }
    }

    // ═══════════════════════════════════════════════════════
    // Role-change field visibility
    // ═══════════════════════════════════════════════════════
    const FIELD_IDS = [
        "adminWarningWrap", "adminKeyWrap", "academicHeader",
        "deptSnumGrid", "yearSectionWrap", "positionWrap", "orgClubWrap"
    ];
    const showField = id => document.getElementById(id).classList.remove("hidden-field");
    const hideField = id => document.getElementById(id).classList.add("hidden-field");

    function handleRoleChange(role) {
        FIELD_IDS.forEach(hideField);
        document.getElementById("orgLogoWrap").classList.add("hidden-field");
        document.getElementById("clubLogoWrap").classList.add("hidden-field");

        const academicInputIds = [
            "deptSelect", "studentNumInput", "yearLevelSelect",
            "sectionSelect", "positionSelect"
        ];
        academicInputIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.removeAttribute("required");
        });

        const snumInput = document.getElementById("studentNumInput");
        const snumWrap  = snumInput && snumInput.closest(".input-wrap");
        if (snumWrap) {
            snumWrap.style.borderColor = "";
            snumWrap.style.boxShadow   = "";
        }

        const membershipTitle = document.getElementById("membershipTitle");
        const orgSelect       = document.getElementById("orgSelect");
        const clubSelect      = document.getElementById("clubSelect");
        if (membershipTitle) membershipTitle.textContent = role === "organizer" ? "Officers" : "Membership";

        if (role === "admin") {
            showField("adminWarningWrap");
            showField("adminKeyWrap");
            if (orgSelect)  orgSelect.removeAttribute("required");
            if (clubSelect) clubSelect.removeAttribute("required");

        } else if (role === "organizer") {
            ["academicHeader", "deptSnumGrid", "yearSectionWrap", "positionWrap", "orgClubWrap"].forEach(showField);
            ["deptSelect", "studentNumInput", "yearLevelSelect", "sectionSelect", "positionSelect"].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.setAttribute("required", "required");
            });
            if (orgSelect)  orgSelect.removeAttribute("required");
            if (clubSelect) clubSelect.removeAttribute("required");

        } else { // student
            ["academicHeader", "deptSnumGrid", "yearSectionWrap", "orgClubWrap"].forEach(showField);
            ["deptSelect", "studentNumInput", "yearLevelSelect", "sectionSelect"].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.setAttribute("required", "required");
            });
            if (orgSelect)  orgSelect.setAttribute("required", "required");
            if (clubSelect) clubSelect.setAttribute("required", "required");
        }

        updateLogoVisibility();
        updatePositionOptions();
    }

    window.handleRoleChange = handleRoleChange;

    // ═══════════════════════════════════════════════════════
    // Password strength checker
    // ═══════════════════════════════════════════════════════
    function setReq(el, met) {
        if (!el) return;
        const icon = el.querySelector("i");
        if (met) { el.classList.add("met");    icon.className = "fa-solid fa-circle-check"; }
        else     { el.classList.remove("met"); icon.className = "fa-solid fa-circle-xmark"; }
    }

    function checkPasswordStrength(val) {
        const bars  = ["pwBar1", "pwBar2", "pwBar3", "pwBar4"].map(id => document.getElementById(id));
        const label = document.getElementById("pwLabel");
        const hasLen   = val.length >= 8;
        const hasSpec  = /[@#_%$!]/.test(val);
        const hasUpper = /[A-Z]/.test(val);
        const hasNum   = /[0-9]/.test(val);

        setReq(document.getElementById("req-len"),     hasLen);
        setReq(document.getElementById("req-special"), hasSpec);
        setReq(document.getElementById("req-upper"),   hasUpper);
        setReq(document.getElementById("req-number"),  hasNum);

        bars.forEach(b => { if (b) b.className = "pw-bar"; });

        if (!val.length) {
            label.textContent = "Enter a password";
            label.style.color = "#c7d0dd";
            return;
        }

        const score  = [hasLen, hasSpec, hasUpper, hasNum].filter(Boolean).length;
        const levels = [
            { cls: "active-weak",   color: "#ef4444", text: "Weak"      },
            { cls: "active-fair",   color: "#f59e0b", text: "Fair"      },
            { cls: "active-good",   color: "#10b981", text: "Good"      },
            { cls: "active-strong", color: "#059669", text: "Strong ✓"  },
        ];
        for (let i = 0; i < score; i++) {
            if (bars[i]) bars[i].classList.add(levels[score - 1].cls);
        }
        label.textContent = levels[score - 1].text;
        label.style.color = levels[score - 1].color;
    }

    window.checkPasswordStrength = checkPasswordStrength;

    // ═══════════════════════════════════════════════════════
    // Eye toggle
    // ═══════════════════════════════════════════════════════
    function toggleEye(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        if (input.type === "password") {
            input.type     = "text";
            icon.className = "fa-regular fa-eye-slash";
        } else {
            input.type     = "password";
            icon.className = "fa-regular fa-eye";
        }
    }

    window.toggleEye = toggleEye;

    // ═══════════════════════════════════════════════════════
    // Ripple effect
    // ═══════════════════════════════════════════════════════
    function attachRipple() {
        document.querySelectorAll(".btn-primary, .btn-ghost").forEach(btn => {
            btn.addEventListener("click", function (e) {
                if (this.disabled) return;
                const ripple = document.createElement("span");
                ripple.classList.add("ripple");
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                ripple.style.cssText =
                    `width:${size}px;height:${size}px;` +
                    `left:${e.clientX - rect.left - size / 2}px;` +
                    `top:${e.clientY - rect.top - size / 2}px;`;
                this.appendChild(ripple);
                ripple.addEventListener("animationend", () => ripple.remove());
            });
        });
    }

    // ═══════════════════════════════════════════════════════
    // Email remember system
    // ═══════════════════════════════════════════════════════
    function getRememberedEmails() {
        try {
            const raw = localStorage.getItem(REMEMBER_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) { return []; }
    }

    function saveRememberedEmail(email) {
        if (!email) return;
        let emails = getRememberedEmails().filter(e => e.toLowerCase() !== email.toLowerCase());
        emails.unshift(email);
        if (emails.length > MAX_REMEMBERED) emails = emails.slice(0, MAX_REMEMBERED);
        localStorage.setItem(REMEMBER_KEY, JSON.stringify(emails));
    }

    function removeRememberedEmail(email) {
        if (!email) return;
        const emails = getRememberedEmails().filter(e => e.toLowerCase() !== email.toLowerCase());
        localStorage.setItem(REMEMBER_KEY, JSON.stringify(emails));
    }

    function renderEmailSuggestions() {
        const emails   = getRememberedEmails();
        const dropdown = document.getElementById("emailSuggestions");
        if (!emails.length) { if (dropdown) dropdown.innerHTML = ""; return; }
        if (dropdown) {
            dropdown.innerHTML = emails.map((e, idx) =>
                `<div class="suggest-item" onclick="selectRememberedEmail('${e.replace(/'/g, "\\'")}')">
                    <i class="fa-regular fa-clock"></i><span>${e}</span>
                    <span class="suggest-remove" onclick="event.stopPropagation();deleteRememberedEmail(${idx})">
                        <i class="fa-solid fa-xmark"></i>
                    </span>
                </div>`
            ).join("");
        }
    }

    function selectRememberedEmail(email) {
        const input    = document.getElementById("loginEmail");
        const dropdown = document.getElementById("emailSuggestions");
        if (input)    input.value = email;
        if (dropdown) dropdown.classList.remove("active");
        document.getElementById("loginPassword").focus();
    }

    function deleteRememberedEmail(index) {
        const emails = getRememberedEmails();
        emails.splice(index, 1);
        localStorage.setItem(REMEMBER_KEY, JSON.stringify(emails));
        renderEmailSuggestions();
        const dropdown = document.getElementById("emailSuggestions");
        if (emails.length) dropdown.classList.add("active");
        else               dropdown.classList.remove("active");
    }

    window.selectRememberedEmail = selectRememberedEmail;
    window.deleteRememberedEmail = deleteRememberedEmail;

    function initEmailRemember() {
        const emailInput = document.getElementById("loginEmail");
        const dropdown   = document.getElementById("emailSuggestions");
        if (!emailInput || !dropdown) return;

        emailInput.addEventListener("focus", function () {
            const emails = getRememberedEmails();
            if (emails.length && this.value === "") {
                renderEmailSuggestions();
                dropdown.classList.add("active");
            }
        });

        emailInput.addEventListener("blur", function () {
            setTimeout(() => dropdown.classList.remove("active"), 200);
        });

        emailInput.addEventListener("input", function () {
            const emails = getRememberedEmails();
            const val    = this.value.toLowerCase();
            if (!emails.length) return;
            const filtered = emails.filter(e => e.toLowerCase().includes(val));
            if (filtered.length) {
                dropdown.innerHTML = filtered.map((e) =>
                    `<div class="suggest-item" onclick="selectRememberedEmail('${e.replace(/'/g, "\\'")}')">
                        <i class="fa-regular fa-clock"></i><span>${e}</span>
                        <span class="suggest-remove" onclick="event.stopPropagation();deleteRememberedEmail(${emails.indexOf(e)})">
                            <i class="fa-solid fa-xmark"></i>
                        </span>
                    </div>`
                ).join("");
                dropdown.classList.add("active");
            } else {
                dropdown.classList.remove("active");
            }
        });

        renderEmailSuggestions();
    }

    // ═══════════════════════════════════════════════════════
    // Form submit handlers
    // ═══════════════════════════════════════════════════════
    function initFormHandlers() {
        const registerForm = document.getElementById("registerForm");
        if (registerForm) {
            registerForm.addEventListener("submit", function (e) {
                const role = document.getElementById("roleSelect").value;
                if (role === "organizer") {
                    const org  = document.getElementById("orgSelect").value;
                    const club = document.getElementById("clubSelect").value;
                    if (org && club) {
                        e.preventDefault();
                        const hint = document.getElementById("orgClubHint");
                        hint.style.display = "block";
                        hint.innerHTML =
                            '<i class="fa-solid fa-triangle-exclamation"></i> <strong>Error:</strong> ' +
                            "Select only one — an Organization <em>or</em> a Club.";
                        hint.scrollIntoView({ behavior: "smooth", block: "center" });
                        return;
                    }
                }
                if (this.checkValidity()) {
                    document.getElementById("pageLoader").classList.add("active");
                }
            });
        }

        const loginForm = document.getElementById("loginForm");
        if (loginForm) {
            loginForm.addEventListener("submit", function (e) {
                if (this.checkValidity()) {
                    e.preventDefault();
                    const form        = this;
                    const emailInput  = document.getElementById("loginEmail");
                    const rememberBox = document.getElementById("rememberMe");
                    if (rememberBox && rememberBox.checked && emailInput.value) {
                        saveRememberedEmail(emailInput.value);
                    } else if (rememberBox && !rememberBox.checked) {
                        removeRememberedEmail(emailInput.value);
                    }
                    document.getElementById("pageLoader").classList.add("active");
                    setTimeout(() => form.submit(), 800);
                }
            });
        }
    }

    // ═══════════════════════════════════════════════════════
    // Org / Club select listeners
    // ═══════════════════════════════════════════════════════
    function initOrgClubListeners() {
        const orgSelect  = document.getElementById("orgSelect");
        const clubSelect = document.getElementById("clubSelect");

        orgSelect.addEventListener("change", function () {
            if (document.getElementById("roleSelect").value === "organizer" && this.value) {
                clubSelect.value = "";
            }
            updateLogoVisibility();
            updatePositionOptions();
        });

        clubSelect.addEventListener("change", function () {
            if (document.getElementById("roleSelect").value === "organizer" && this.value) {
                orgSelect.value = "";
            }
            updateLogoVisibility();
            updatePositionOptions();
        });

        document.getElementById("roleSelect").addEventListener("change", updatePositionOptions);
    }

    // ═══════════════════════════════════════════════════════
    // Animated bubble background
    // ═══════════════════════════════════════════════════════
    function initBubbles() {
        const container = document.getElementById("bubble-bg");
        const palette = [
            { color: "rgba(99,102,241,VAR)",  shadow: "rgba(99,102,241,0.25)"  },
            { color: "rgba(139,92,246,VAR)",  shadow: "rgba(139,92,246,0.20)"  },
            { color: "rgba(167,139,250,VAR)", shadow: "rgba(167,139,250,0.18)" },
            { color: "rgba(16,185,129,VAR)",  shadow: "rgba(16,185,129,0.18)"  },
            { color: "rgba(236,72,153,VAR)",  shadow: "rgba(236,72,153,0.15)"  },
        ];
        const types = [{ style: "filled" }, { style: "outline" }, { style: "glass" }];

        function rand(min, max) { return Math.random() * (max - min) + min; }

        function createBubble() {
            const el     = document.createElement("div");
            el.className = "bubble";
            const size    = rand(14, 90);
            const p       = palette[Math.floor(Math.random() * palette.length)];
            const t       = types[Math.floor(Math.random() * types.length)];
            const opacity = rand(0.25, 0.65);
            const color   = p.color.replace("VAR", opacity.toFixed(2));

            el.style.cssText =
                `width:${size}px;height:${size}px;left:${rand(0, 100)}%;` +
                `bottom:-${size}px;animation-duration:${rand(9, 24)}s;` +
                `animation-delay:${rand(0, 20)}s;`;

            if (t.style === "filled") {
                el.style.background = color;
                el.style.boxShadow  = `0 0 ${size * 0.4}px ${p.shadow}`;
            } else if (t.style === "outline") {
                el.style.background = "transparent";
                el.style.border     = `2px solid ${color}`;
            } else {
                el.style.background = color.replace(opacity.toFixed(2), (opacity * 0.35).toFixed(2));
                el.style.border     = `1px solid ${color}`;
            }

            el.style.animation += `, bubbleSway ${rand(3, 7)}s ease-in-out infinite ${rand(0, 3)}s`;
            container.appendChild(el);
        }

        for (let i = 0; i < 28; i++) setTimeout(createBubble, i * 120);
    }

    // ═══════════════════════════════════════════════════════
    // Boot
    // ═══════════════════════════════════════════════════════
    function init() {
        handleRoleChange(document.getElementById("roleSelect").value);
        initOrgClubListeners();
        updatePositionOptions();
        attachRipple();
        initEmailRemember();
        initFormHandlers();
        initStudentNumberMask();
        initBubbles();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }

})();