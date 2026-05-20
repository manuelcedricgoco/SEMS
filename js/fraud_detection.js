/* ═══════════════════════════════════════════════════════════════════════
 | FILE    : fraud_detection.js
 | PURPOSE : Compare scan-proof photo vs student profile using face-api.js
 |           • 100 % browser-side  — zero API cost, zero server calls
 |           • MIT-licensed (face-api.js by justadudewhohacks)
 |           • Works on both organizer_attendance.php AND organizer_qrscan.php
 |
 | HOW TO ADD:
 |   1. In <head> of BOTH pages, add:
 |        <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
 |   2. Before </body> of BOTH pages, add AFTER the page's own .js script:
 |        <script src="/js/fraud_detection.js"></script>
 |
 |   That's it — no changes to existing JS files required.
 ═══════════════════════════════════════════════════════════════════════ */

(function () {
    'use strict';

    /* ──────────────────────────────────────────────────────────────────
       CONFIG
    ────────────────────────────────────────────────────────────────── */
    var MODEL_CDN = '/weights';

    /**
     * Euclidean distance threshold.
     *   ≤ 0.50  → likely the SAME person   (green ✓)
     *   > 0.50  → likely DIFFERENT people  (orange ⚠)
     * Adjust between 0.45 (stricter) and 0.60 (more lenient) to taste.
     */
    var MATCH_THRESHOLD = 0.50;

    /* ──────────────────────────────────────────────────────────────────
       MODEL LOADING
    ────────────────────────────────────────────────────────────────── */
    var _modelsReady   = false;
    var _modelsLoading = false;
    var _waitQueue     = [];   // [{resolve, reject}] pending calls

    function _ensureModels() {
        if (_modelsReady)   return Promise.resolve(true);
        if (_modelsLoading) return new Promise(function (res, rej) { _waitQueue.push({ resolve: res, reject: rej }); });

        _modelsLoading = true;
        return Promise.all([
            faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_CDN),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_CDN),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_CDN),
        ])
        .then(function () {
            _modelsReady   = true;
            _modelsLoading = false;
            _waitQueue.forEach(function (p) { p.resolve(true); });
            _waitQueue = [];
            console.info('[FraudDetect] Models loaded ✓');
            return true;
        })
        .catch(function (err) {
            _modelsLoading = false;
            _waitQueue.forEach(function (p) { p.reject(err); });
            _waitQueue = [];
            console.warn('[FraudDetect] Model load failed:', err);
            throw err;
        });
    }

    /* Pre-warm models in background after page settles */
    if (typeof faceapi !== 'undefined') {
        setTimeout(function () { _ensureModels().catch(function () {}); }, 1800);
    }

    /* ──────────────────────────────────────────────────────────────────
       CORE COMPARISON
    ────────────────────────────────────────────────────────────────── */

    /** Load a URL or HTMLImageElement into a browser <img> */
    function _loadImg(src) {
        return new Promise(function (resolve, reject) {
            if (src instanceof HTMLImageElement && src.complete && src.naturalWidth > 0) {
                return resolve(src);
            }
            var img         = new Image();
            img.crossOrigin = 'anonymous';
            img.onload      = function () { resolve(img); };
            img.onerror     = function () { reject(new Error('Image load failed: ' + (typeof src === 'string' ? src.slice(0, 60) : 'element'))); };
            img.src = typeof src === 'string' ? src : src.src;
        });
    }

    /**
     * Compare two face images.
     * @param {string|HTMLImageElement} proofSrc
     * @param {string|HTMLImageElement} profileSrc
     * @returns {Promise<{detected:boolean, match:boolean|null, distance:number|null, msg:string}>}
     */
    async function check(proofSrc, profileSrc) {
        if (!proofSrc || !profileSrc) {
            return { detected: false, match: null, distance: null, msg: 'Missing image source.' };
        }

        /* 1. Load models */
        try { await _ensureModels(); }
        catch (e) {
            return { detected: false, match: null, distance: null, msg: 'Face models unavailable — check internet connection.' };
        }

        /* 2. Load images */
        var img1, img2;
        try {
            var results = await Promise.all([_loadImg(proofSrc), _loadImg(profileSrc)]);
            img1 = results[0];
            img2 = results[1];
        } catch (e) {
            return { detected: false, match: null, distance: null, msg: 'Could not load images for comparison.' };
        }

        /* 3. Detect faces + descriptors */
        var opts = new faceapi.SsdMobilenetv1Options({ minConfidence: 0.42 });
        var det1, det2;
        try {
            var detections = await Promise.all([
                faceapi.detectSingleFace(img1, opts).withFaceLandmarks().withFaceDescriptor(),
                faceapi.detectSingleFace(img2, opts).withFaceLandmarks().withFaceDescriptor(),
            ]);
            det1 = detections[0];
            det2 = detections[1];
        } catch (e) {
            return { detected: false, match: null, distance: null, msg: 'Detection error: ' + e.message };
        }

        if (!det1 && !det2) return { detected: false, match: null, distance: null, msg: 'No face detected in either image.' };
        if (!det1)          return { detected: false, match: null, distance: null, msg: 'No face found in the scan proof photo.' };
        if (!det2)          return { detected: false, match: null, distance: null, msg: 'No face found in the student profile photo.' };

        /* 4. Euclidean distance */
        var dist  = faceapi.euclideanDistance(det1.descriptor, det2.descriptor);
        var match = dist <= MATCH_THRESHOLD;

        return {
            detected  : true,
            match     : match,
            distance  : Math.round(dist * 100) / 100,
            confidence: Math.round(Math.max(0, (1 - dist / 0.8)) * 100), // 0–100 %
            msg       : match
                ? 'Identity verified — faces match.'
                : 'Possible mismatch — faces appear to differ.',
        };
    }

    /* Expose public API */
    window.fraudDetect = { check: check, ready: function () { return _ensureModels().catch(function () { return false; }); } };


    /* ══════════════════════════════════════════════════════════════════
       ATTENDANCE PAGE INTEGRATION
       Hooks into organizer_attendance.php's details modal.
       Uses MutationObserver — zero changes to organizer_attendance.js.
    ══════════════════════════════════════════════════════════════════ */

    /* -- Build the fraud-check status badge (injected once into modal) -- */
    function _injectFraudBadge() {
        if (document.getElementById('fdBadge')) return; /* already injected */

        var fraudWarning = document.getElementById('fraudWarning');
        if (!fraudWarning) return;

        /* Status badge — sits just above the existing fraudWarning div */
        var badge = document.createElement('div');
        badge.id        = 'fdBadge';
        badge.className = 'hidden';
        badge.style.cssText = 'transition:opacity .3s;';
        badge.innerHTML = `
            <div id="fdChecking" class="hidden items-center gap-2 px-3 py-2 rounded-xl text-xs font-medium
                 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800
                 text-indigo-600 dark:text-indigo-400" style="animation:fadeUp .3s ease both;">
                <svg class="animate-spin h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                Verifying identity with AI face comparison…
            </div>
            <div id="fdMatch" class="hidden items-center gap-2 px-3 py-2 rounded-xl text-xs font-medium
                 bg-brand-50 dark:bg-brand-900/20 border border-brand-200 dark:border-brand-800
                 text-brand-700 dark:text-brand-400" style="animation:fadeUp .3s ease both;">
                <i class="fas fa-shield-halved text-brand-500 flex-shrink-0"></i>
                <span id="fdMatchMsg">Identity verified — faces match.</span>
                <span id="fdConfBadge" class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full
                      bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 border border-brand-200 dark:border-brand-700"></span>
            </div>
            <div id="fdWarn" class="hidden items-center gap-2 px-3 py-2 rounded-xl text-xs font-medium
                 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800
                 text-orange-700 dark:text-orange-400" style="animation:fadeUp .3s ease both;">
                <i class="fas fa-triangle-exclamation text-orange-500 flex-shrink-0"></i>
                <span id="fdWarnMsg">Possible mismatch — faces appear to differ.</span>
                <span id="fdDistBadge" class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full
                      bg-orange-100 dark:bg-orange-900/40 text-orange-600 dark:text-orange-400 border border-orange-200 dark:border-orange-700"></span>
            </div>
            <div id="fdNoFace" class="hidden items-center gap-2 px-3 py-2 rounded-xl text-xs font-medium
                 bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600
                 text-gray-500 dark:text-gray-400" style="animation:fadeUp .3s ease both;">
                <i class="fas fa-face-frown text-gray-400 flex-shrink-0"></i>
                <span id="fdNoFaceMsg">Could not detect faces — verify manually.</span>
            </div>`;

        fraudWarning.parentNode.insertBefore(badge, fraudWarning);
    }

    function _showFdState(state, result) {
        var badge = document.getElementById('fdBadge');
        if (!badge) return;

        /* hide all sub-states */
        ['fdChecking','fdMatch','fdWarn','fdNoFace'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.classList.add('hidden'); el.classList.remove('flex'); }
        });

        badge.classList.remove('hidden');

        if (state === 'checking') {
            var c = document.getElementById('fdChecking');
            if (c) { c.classList.remove('hidden'); c.classList.add('flex'); }

            /* Also reset legacy fraudWarning */
            var fw = document.getElementById('fraudWarning');
            if (fw) { fw.classList.add('hidden'); fw.classList.remove('flex'); }
            return;
        }

        if (state === 'idle' || !result) {
            badge.classList.add('hidden');
            return;
        }

        if (!result.detected) {
            /* Face not found in one of the images */
            var nf = document.getElementById('fdNoFace');
            var nm = document.getElementById('fdNoFaceMsg');
            if (nf) { nf.classList.remove('hidden'); nf.classList.add('flex'); }
            if (nm) nm.textContent = result.msg;
        } else if (result.match) {
            /* ✓ Match */
            var m  = document.getElementById('fdMatch');
            var mm = document.getElementById('fdMatchMsg');
            var cb = document.getElementById('fdConfBadge');
            if (m)  { m.classList.remove('hidden'); m.classList.add('flex'); }
            if (mm) mm.textContent = result.msg;
            if (cb) cb.textContent = (result.confidence || 0) + '% confidence';

            var fw2 = document.getElementById('fraudWarning');
            if (fw2) { fw2.classList.add('hidden'); fw2.classList.remove('flex'); }
        } else {
            /* ⚠ Mismatch */
            var w   = document.getElementById('fdWarn');
            var wm  = document.getElementById('fdWarnMsg');
            var db  = document.getElementById('fdDistBadge');
            if (w)  { w.classList.remove('hidden'); w.classList.add('flex'); }
            if (wm) wm.textContent = result.msg;
            if (db) db.textContent = 'dist ' + (result.distance || '?');

            /* Also show the existing red fraud warning block */
            var fw3 = document.getElementById('fraudWarning');
            if (fw3) { fw3.classList.remove('hidden'); fw3.classList.add('flex'); }

            /* ── AUTO-SELECT ABSENT on mismatch (organizer can still override) ── */
            if (typeof selectStatus === 'function') selectStatus('absent');
        }
    }
    var _fdDebounce = null;

    function _doFraudCheck() {
        var modal      = document.getElementById('detailsModal');
        var proofImg   = document.getElementById('modalProofImg');
        var profileImg = document.getElementById('modalStudentPhoto');
        if (!modal || modal.classList.contains('hidden')) return;

        /* Both images must be visible and have a real src */
        var proofOk   = proofImg   && !proofImg.classList.contains('hidden')   && proofImg.src   && !proofImg.src.endsWith('#');
        var profileOk = profileImg && !profileImg.classList.contains('hidden') && profileImg.src && !profileImg.src.endsWith('#');

        if (!proofOk || !profileOk) return; /* Not ready yet — observer will re-trigger */

        _showFdState('checking');

        check(proofImg.src, profileImg.src)
            .then(function (result) {
                /* Guard: modal may have been closed during async work */
                var m = document.getElementById('detailsModal');
                if (!m || m.classList.contains('hidden')) return;
                _showFdState('done', result);
            })
            .catch(function (err) {
                console.warn('[FraudDetect] Check error:', err);
                _showFdState('done', { detected: false, match: null, msg: 'Comparison failed — ' + err.message });
            });
    }

    function _scheduleFraudCheck() {
        clearTimeout(_fdDebounce);
        _fdDebounce = setTimeout(_doFraudCheck, 350);
    }

    /* Observe detailsModal open/close via class mutation */
    document.addEventListener('DOMContentLoaded', function () {
        _injectFraudBadge();

        var modal = document.getElementById('detailsModal');
        if (!modal) return;

        /* Watch modal visibility */
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                if (m.attributeName === 'class') {
                    if (!modal.classList.contains('hidden')) {
                        /* Modal just opened — reset then schedule check */
                        _showFdState('idle');
                        _scheduleFraudCheck();
                    } else {
                        /* Modal closed — clean up */
                        _showFdState('idle');
                    }
                }
            });
        }).observe(modal, { attributes: true, attributeFilter: ['class'] });

        /* Watch proof image src — fires when proof loads async */
        var proofImg = document.getElementById('modalProofImg');
        if (proofImg) {
            new MutationObserver(function () { _scheduleFraudCheck(); })
                .observe(proofImg, { attributes: true, attributeFilter: ['src', 'class'] });
            proofImg.addEventListener('load', _scheduleFraudCheck);
        }

        /* Watch student profile image — fires when it changes */
        var profileImg = document.getElementById('modalStudentPhoto');
        if (profileImg) {
            profileImg.addEventListener('load', _scheduleFraudCheck);
        }
    });


    /* ══════════════════════════════════════════════════════════════════
       QR SCANNER PAGE INTEGRATION
       Hooks into organizer_qrscan.php's post-scan flow.

       REQUIREMENT: the PHP AJAX response must include 'profile_image_b64'.
       See the PHP patch at the bottom of this file.
    ══════════════════════════════════════════════════════════════════ */

    document.addEventListener('DOMContentLoaded', function () {

        /* Only run on the QR scan page */
        if (!document.getElementById('scannerModal') && !document.getElementById('manualModal')) return;

        /* Patch window.postScan to intercept responses that have a profile_image_b64 */
        var _origPostScan = window.postScan;
        if (typeof _origPostScan !== 'function') return;

        window.postScan = async function (qrValue, scanType, proofB64) {
            var data = await _origPostScan(qrValue, scanType, proofB64);

            if (data && data.success && data.profile_image_b64 && proofB64) {
                /* Run comparison in background — don't block UI */
                _runQrFraudCheck(proofB64, data.profile_image_b64, data.name || 'Student');
            }

            return data;
        };
    });

    /**
     * Show a compact fraud result toast/banner in the QR scanner and Manual modals.
     */
    function _runQrFraudCheck(proofB64, profileB64, studentName) {
        var proofSrc   = 'data:image/jpeg;base64,' + proofB64;
        var profileSrc = 'data:image/jpeg;base64,' + profileB64;

        check(proofSrc, profileSrc).then(function (result) {
            _showQrFraudBanner(result, studentName);
        }).catch(function () {});
    }

    function _showQrFraudBanner(result, name) {
        /* Find which modal is open */
        var scannerOpen = document.getElementById('scannerModal') &&
                          !document.getElementById('scannerModal').classList.contains('hidden');
        var manualOpen  = document.getElementById('manualModal')  &&
                          !document.getElementById('manualModal').classList.contains('hidden');

        if (!scannerOpen && !manualOpen) return;

        var containerId = scannerOpen ? 'scannerFdBanner' : 'manualFdBanner';
        var existing = document.getElementById(containerId);

        if (!existing) {
            existing = document.createElement('div');
            existing.id            = containerId;
            existing.style.cssText = 'animation:fadeUp .35s ease both; transition:opacity .3s;';

            if (scannerOpen) {
                var feedbackEl = document.getElementById('scan-feedback');
                if (feedbackEl) feedbackEl.parentNode.insertBefore(existing, feedbackEl.nextSibling);
            } else {
                var manualFb = document.getElementById('manual-feedback');
                if (manualFb) manualFb.parentNode.insertBefore(existing, manualFb.nextSibling);
            }
        }

        /* Render banner */
        if (!result.detected) {
            existing.className = 'flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-xs font-medium border mt-2 ' +
                (scannerOpen
                    ? 'bg-gray-800/80 border-gray-700 text-gray-400'
                    : 'bg-gray-50 dark:bg-gray-700/50 border-gray-200 dark:border-gray-600 text-gray-500 dark:text-gray-400');
            existing.innerHTML = '<i class="fas fa-face-frown flex-shrink-0"></i>' +
                '<span>Face comparison: ' + _esc(result.msg) + '</span>';
        } else if (result.match) {
            existing.className = 'flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-xs font-medium border mt-2 ' +
                (scannerOpen
                    ? 'bg-brand-900/30 border-brand-800 text-brand-300'
                    : 'bg-brand-50 dark:bg-brand-900/20 border-brand-200 dark:border-brand-800 text-brand-700 dark:text-brand-400');
            existing.innerHTML = '<i class="fas fa-shield-halved flex-shrink-0"></i>' +
                '<span><strong>' + _esc(name) + '</strong> — Identity verified</span>' +
                '<span class="ml-auto opacity-60">' + (result.confidence || 0) + '%</span>';
        } else {
            existing.className = 'flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-xs font-semibold border mt-2 ' +
                (scannerOpen
                    ? 'bg-orange-900/30 border-orange-700 text-orange-300'
                    : 'bg-orange-50 dark:bg-orange-900/20 border-orange-200 dark:border-orange-800 text-orange-700 dark:text-orange-400');
            existing.innerHTML = '<i class="fas fa-triangle-exclamation flex-shrink-0"></i>' +
                '<span>⚠ Possible mismatch for <strong>' + _esc(name) + '</strong> — verify manually</span>' +
                '<span class="ml-auto opacity-60">dist ' + (result.distance || '?') + '</span>';
        }

        /* Auto-hide after 8 s */
        clearTimeout(existing._hideTimer);
        existing._hideTimer = setTimeout(function () {
            existing.style.opacity = '0';
            setTimeout(function () { if (existing.parentNode) existing.remove(); }, 400);
        }, 8000);
    }

    function _esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
        });
    }

})();


/* ═══════════════════════════════════════════════════════════════════════
 |  PHP PATCH FOR organizer_qrscan.php
 |  ─────────────────────────────────────────────────────────────────────
 |  In the AJAX handler (case where $success is true, just before
 |  `echo json_encode(['success' => true, ...])` ), add the profile
 |  image to the response so the JS can compare it client-side.
 |
 |  Replace this block:
 |
 |      echo json_encode(['success' => true, 'name' => $student['name'], 'scan_type' => $scanType]);
 |
 |  With:
 |
 |      $profImgB64 = '';
 |      $piQ = $pdo->prepare("SELECT profile_image FROM profiles WHERE user_id = ?");
 |      $piQ->execute([$studentId]);
 |      $piRow = $piQ->fetch(PDO::FETCH_ASSOC);
 |      if (!empty($piRow['profile_image'])) {
 |          $profImgB64 = base64_encode($piRow['profile_image']);
 |      }
 |      echo json_encode([
 |          'success'           => true,
 |          'name'              => $student['name'],
 |          'scan_type'         => $scanType,
 |          'profile_image_b64' => $profImgB64,
 |      ]);
 |
 ═══════════════════════════════════════════════════════════════════════ */

/* ═══════════════════════════════════════════════════════════════════════
 |  HTML PATCH FOR organizer_attendance.php  (add to <head>)
 |  ─────────────────────────────────────────────────────────────────────
 |  <!-- face-api.js — free, MIT, browser-only -->
 |  <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
 |
 |  And before </body>, AFTER <script src="/js/organizer_attendance.js">:
 |  <script src="/js/fraud_detection.js"></script>
 |
 ═══════════════════════════════════════════════════════════════════════ */

/* ═══════════════════════════════════════════════════════════════════════
 |  HTML PATCH FOR organizer_qrscan.php  (add to <head>)
 |  ─────────────────────────────────────────────────────────────────────
 |  <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
 |
 |  And before </body>, AFTER <script src="/js/organizer_qrscan.js">:
 |  <script src="/js/fraud_detection.js"></script>
 |
 ═══════════════════════════════════════════════════════════════════════ */