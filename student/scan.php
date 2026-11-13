<?php
require_once __DIR__ . '/../includes/functions.php';
$me = require_role('student');
generate_return_reminders((int)$me['id']);

$pageTitle = 'Scan QR Code';
$pageSubtitle = 'Scan a device label to view its details and availability';
$active = 'scan';
$extraScripts = [asset('vendor/html5-qrcode.min.js'), asset('vendor/jsQR.min.js')];
require __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-2-1">
    <div class="card">
        <div class="card-head"><h2><?= icon('camera') ?> Camera scanner</h2>
            <div class="actions">
                <button class="btn btn-sky" type="button" id="start-btn"><?= icon('camera') ?> Start camera</button>
                <button class="btn btn-light hidden" type="button" id="stop-btn"><?= icon('x') ?> Stop</button>
            </div>
        </div>
        <div class="card-body">
            <div id="insecure" class="alert alert-warning hidden"><?= icon('alert') ?><div>
                Browsers only allow camera access on <strong>https://</strong> pages or on <strong>localhost</strong>.
                You can still scan by uploading a photo of the QR code, or by typing the device code.</div></div>
            <div id="scan-msg" class="alert alert-info hidden"><?= icon('scan') ?><div></div></div>
            <div id="reader"></div>
            <p class="hint mt-2 mb-0" style="text-align:center">Point your camera at the QR label on the device. You will be taken to the device page automatically.</p>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><h2><?= icon('upload') ?> Scan from an image</h2></div>
            <div class="card-body">
                <p class="small muted">Take a photo of the QR label and choose it here.</p>
                <input type="file" id="qr-file" accept="image/*">
                <div id="file-reader" class="hidden"></div>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h2><?= icon('qr') ?> Enter device code</h2></div>
            <div class="card-body">
                <form method="get" action="<?= e(url('student/device.php')) ?>">
                    <div class="field"><label for="code">Device code</label><input type="text" id="code" name="code" placeholder="e.g. DEV-1A2B3C4D" required maxlength="40" style="text-transform:uppercase"></div>
                    <button class="btn btn-block" type="submit"><?= icon('search') ?> Look up device</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
$deviceUrl = json_encode(url('student/device.php?code='));
$inlineScript = <<<JS
(function () {
    var deviceUrl = $deviceUrl;
    var msg = document.getElementById('scan-msg');
    var startBtn = document.getElementById('start-btn'), stopBtn = document.getElementById('stop-btn');
    var scanner = null, done = false;

    function show(text, type) {
        msg.className = 'alert alert-' + (type || 'info');
        msg.querySelector('div').textContent = text;
    }
    function extractCode(text) {
        text = (text || '').trim();
        try {
            var u = new URL(text);
            var c = u.searchParams.get('code');
            if (c) return c;
        } catch (e) { /* not a URL */ }
        if (/^DEV-[A-Z0-9]{4,32}$/i.test(text)) return text.toUpperCase();
        return null;
    }
    function handle(text) {
        if (done) return;
        var code = extractCode(text);
        if (!code) { show('That QR code is not an LearnX device label.', 'warning'); return; }
        done = true;
        show('Device found (' + code + '). Opening details…', 'success');
        var go = function () { window.location.href = deviceUrl + encodeURIComponent(code); };
        if (scanner) { scanner.stop().then(go, go); } else { go(); }
    }

    if (typeof Html5Qrcode === 'undefined') {
        show('The scanner library could not be loaded.', 'error');
        startBtn.disabled = true;
        return;
    }
    if (!window.isSecureContext) {
        document.getElementById('insecure').classList.remove('hidden');
        startBtn.disabled = true;
    }

    startBtn.addEventListener('click', function () {
        done = false;
        scanner = scanner || new Html5Qrcode('reader', { formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE], verbose: false });
        show('Starting camera… allow camera access if your browser asks.');
        scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } }, handle, function () {})
            .then(function () {
                show('Camera ready. Point it at a device QR code.');
                startBtn.classList.add('hidden'); stopBtn.classList.remove('hidden');
            })
            .catch(function (err) {
                show('Could not start the camera: ' + err + '. Try uploading an image instead.', 'error');
            });
    });
    stopBtn.addEventListener('click', function () {
        if (!scanner) return;
        scanner.stop().then(function () {
            stopBtn.classList.add('hidden'); startBtn.classList.remove('hidden');
            show('Camera stopped.');
        });
    });

    document.getElementById('qr-file').addEventListener('change', function (ev) {
        var f = ev.target.files && ev.target.files[0];
        if (!f) return;
        show('Reading image…');
        var notFound = function () { show('No QR code was found in that image. Try a clearer, closer photo.', 'warning'); };
        var viaHtml5 = function () {
            var fileScanner = new Html5Qrcode('file-reader', { formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE], verbose: false });
            return fileScanner.scanFile(f, false)
                .then(function (text) { handle(text); }, notFound)
                .finally(function () { try { fileScanner.clear(); } catch (e) {} });
        };
        // Try jsQR first (more reliable on still photos), at a few sizes; fall back to html5-qrcode.
        var img = new Image(), objUrl = URL.createObjectURL(f);
        img.onload = function () {
            var text = null;
            if (typeof jsQR !== 'undefined') {
                [1600, 1000, 640].some(function (max) {
                    var s = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
                    var c = document.createElement('canvas');
                    c.width = Math.round(img.naturalWidth * s); c.height = Math.round(img.naturalHeight * s);
                    var g = c.getContext('2d'); g.drawImage(img, 0, 0, c.width, c.height);
                    var r = jsQR(g.getImageData(0, 0, c.width, c.height).data, c.width, c.height, { inversionAttempts: 'attemptBoth' });
                    text = r ? r.data : null;
                    return !!text;
                });
            }
            URL.revokeObjectURL(objUrl);
            if (text) { handle(text); ev.target.value = ''; }
            else { viaHtml5().finally(function () { ev.target.value = ''; }); }
        };
        img.onerror = function () { URL.revokeObjectURL(objUrl); notFound(); ev.target.value = ''; };
        img.src = objUrl;
    });
})();
JS;
require __DIR__ . '/../includes/footer.php';
