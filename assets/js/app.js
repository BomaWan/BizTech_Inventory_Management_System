/* LearnX Inventory — UI behaviour */
(function () {
    'use strict';

    // Sidebar (mobile)
    document.querySelectorAll('[data-toggle-sidebar]').forEach(function (b) {
        b.addEventListener('click', function () { document.body.classList.toggle('sidebar-open'); });
    });
    document.querySelectorAll('[data-close-sidebar]').forEach(function (b) {
        b.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });
    });

    // Dismissable alerts (auto-hide success after 6s)
    document.querySelectorAll('[data-dismissable]').forEach(function (a) {
        var close = a.querySelector('.alert-close');
        if (close) close.addEventListener('click', function () { a.remove(); });
        if (a.classList.contains('alert-success')) setTimeout(function () { a.remove(); }, 6000);
    });

    // Confirm before submitting dangerous forms
    document.addEventListener('submit', function (ev) {
        var f = ev.target;
        var msg = f.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) ev.preventDefault();
    });

    // Modals: <button data-modal="id" data-fill='{"field":"value"}'>
    document.addEventListener('click', function (ev) {
        var opener = ev.target.closest('[data-modal]');
        if (opener) {
            var dlg = document.getElementById(opener.getAttribute('data-modal'));
            if (!dlg) return;
            ev.preventDefault();
            var form = dlg.querySelector('form');
            if (form && opener.hasAttribute('data-reset')) form.reset();
            var fill = opener.getAttribute('data-fill');
            if (fill && form) {
                try {
                    var data = JSON.parse(fill);
                    Object.keys(data).forEach(function (k) {
                        var el = form.querySelector('[name="' + k + '"]');
                        if (el) { el.value = data[k] === null ? '' : data[k]; }
                        dlg.querySelectorAll('[data-text="' + k + '"]').forEach(function (t) { t.textContent = data[k] === null ? '' : data[k]; });
                    });
                } catch (e) { /* ignore malformed JSON */ }
            }
            var title = opener.getAttribute('data-title');
            var h = dlg.querySelector('[data-modal-title]');
            if (title && h) h.textContent = title;
            dlg.dispatchEvent(new CustomEvent('modal:open', { detail: { opener: opener } }));
            if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
            var first = dlg.querySelector('input:not([type=hidden]), select, textarea');
            if (first) first.focus();
            return;
        }
        var closer = ev.target.closest('[data-close]');
        if (closer) {
            var d = closer.closest('dialog');
            if (d) { ev.preventDefault(); d.close(); }
        }
    });
    // Click on backdrop closes the dialog
    document.querySelectorAll('dialog.modal').forEach(function (d) {
        d.addEventListener('click', function (ev) { if (ev.target === d) d.close(); });
    });

    // Client-side filter for tables/cards: <input data-filter="#target"> filters [data-search] children
    document.querySelectorAll('[data-filter]').forEach(function (input) {
        var target = document.querySelector(input.getAttribute('data-filter'));
        if (!target) return;
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            target.querySelectorAll('[data-search]').forEach(function (row) {
                row.style.display = row.getAttribute('data-search').toLowerCase().indexOf(q) !== -1 ? '' : 'none';
            });
        });
    });

    // ---------------------------------------------------------------- QR helpers
    var NAVY = '#062551';

    window.LearnXQR = {
        /**
         * Draws a QR code (qrcode-generator library) onto a canvas with a white quiet zone.
         * Modules are whole pixels so the code stays sharp; size is the approximate target width.
         */
        canvas: function (text, size, level, margin) {
            if (typeof qrcode === 'undefined') return null;
            var qr = qrcode(0, level || 'M');
            qr.addData(text, 'Byte');
            qr.make();
            var n = qr.getModuleCount(), m = margin === undefined ? 4 : margin;
            var cell = Math.max(1, Math.floor((size || 160) / (n + 2 * m)));
            var px = cell * (n + 2 * m), c = document.createElement('canvas');
            c.width = px; c.height = px;
            var g = c.getContext('2d');
            g.fillStyle = '#ffffff'; g.fillRect(0, 0, px, px);
            g.fillStyle = NAVY;
            for (var r = 0; r < n; r++) for (var col = 0; col < n; col++) {
                if (qr.isDark(r, col)) g.fillRect((col + m) * cell, (r + m) * cell, cell, cell);
            }
            return c;
        },
        render: function (el, text, size) {
            el.innerHTML = '';
            var c = window.LearnXQR.canvas(text, (size || 160) * 2, 'M', 2); // drawn at 2x for sharp display
            if (!c) { el.textContent = 'QR library failed to load.'; return; }
            c.style.width = (size || 160) + 'px'; c.style.height = (size || 160) + 'px';
            c.style.imageRendering = 'pixelated';
            el.appendChild(c);
            el.setAttribute('title', text);
        },
        /** Draws a printable sticker label: brand header, QR, device name, location and code. Returns a canvas. */
        label: function (text, name, code, location) {
            var qrCanvas = window.LearnXQR.canvas(text, 480, 'Q', 4);
            if (!qrCanvas) return null;

            var W = 640, font = 'Segoe UI, Arial, sans-serif';
            var c = document.createElement('canvas');
            c.width = W; c.height = 10;
            var g = c.getContext('2d');
            function wrap(str, maxW, maxLines) {
                var words = String(str).split(/\s+/), lines = [], line = '';
                words.forEach(function (w) {
                    var t = line ? line + ' ' + w : w;
                    if (g.measureText(t).width > maxW && line) { lines.push(line); line = w; } else { line = t; }
                });
                if (line) lines.push(line);
                if (lines.length > maxLines) { lines = lines.slice(0, maxLines); lines[maxLines - 1] = lines[maxLines - 1].replace(/\s*\S*$/, '') + '…'; }
                return lines;
            }
            function roundRect(x, y, w, h, r) {
                g.beginPath(); g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r);
                g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath();
            }

            // measure wrapped text first so the canvas height fits the content
            g.font = 'bold 34px ' + font;
            var nameLines = wrap(name, W - 70, 2);
            g.font = 'bold 32px ' + font;
            var locLines = wrap(location || 'Not set', W - 72 - 110, 2);
            var py = 652 + nameLines.length * 42 + 6, ph = 132;
            var H = py + ph + 112;
            c.height = H; // resizing resets the context state
            g = c.getContext('2d');

            // background + cut border
            g.fillStyle = '#ffffff'; g.fillRect(0, 0, W, H);
            g.strokeStyle = '#cfd8e5'; g.lineWidth = 4; roundRect(2, 2, W - 4, H - 4, 22); g.stroke();
            // header
            g.save(); roundRect(2, 2, W - 4, 96, 22); g.clip(); g.fillStyle = NAVY; g.fillRect(0, 0, W, 100); g.restore();
            g.fillStyle = NAVY; g.fillRect(2, 60, W - 4, 40);
            g.fillStyle = '#0d9dd2'; g.fillRect(2, 100, W - 4, 8);
            g.textBaseline = 'middle';
            g.font = '800 40px ' + font;
            g.fillStyle = '#ffffff'; g.fillText('Learn', 36, 54);
            var lw = g.measureText('Learn').width;
            g.fillStyle = '#0d9dd2'; g.fillText('X', 36 + lw, 54);
            g.fillStyle = '#a60612'; g.fillRect(36 + lw + g.measureText('X').width + 2, 30, 12, 12);
            g.fillStyle = '#9fbbe0'; g.font = '600 20px ' + font; g.textAlign = 'right';
            g.fillText('INVENTORY TAG', W - 36, 56);
            g.textAlign = 'center';
            // QR
            g.imageSmoothingEnabled = false;
            g.drawImage(qrCanvas, (W - 480) / 2, 128, 480, 480);
            g.imageSmoothingEnabled = true;
            // device name
            g.fillStyle = NAVY; g.font = 'bold 34px ' + font;
            var y = 652;
            nameLines.forEach(function (l) { g.fillText(l, W / 2, y); y += 42; });
            // location panel
            g.fillStyle = '#ecf8fd'; roundRect(36, py, W - 72, ph, 16); g.fill();
            g.strokeStyle = '#0d9dd2'; g.lineWidth = 3; roundRect(36, py, W - 72, ph, 16); g.stroke();
            // pin icon
            var px = 82, pyc = py + ph / 2 - 6;
            g.fillStyle = '#a60612'; g.beginPath(); g.arc(px, pyc - 6, 18, Math.PI, 0); g.lineTo(px, pyc + 30); g.closePath(); g.fill();
            g.beginPath(); g.arc(px, pyc - 6, 18, 0, Math.PI * 2); g.fill();
            g.fillStyle = '#ffffff'; g.beginPath(); g.arc(px, pyc - 6, 7, 0, Math.PI * 2); g.fill();
            g.textAlign = 'left';
            g.fillStyle = '#0b85b3'; g.font = '700 20px ' + font; g.fillText('LOCATION', 120, py + 32);
            g.fillStyle = NAVY; g.font = 'bold 32px ' + font;
            var ly = py + (locLines.length > 1 ? 70 : 82);
            locLines.forEach(function (l) { g.fillText(l, 120, ly); ly += 38; });
            // footer
            g.textAlign = 'center';
            g.fillStyle = '#44526a'; g.font = 'bold 26px Consolas, monospace';
            g.fillText(code, W / 2, py + ph + 40);
            g.fillStyle = '#0b85b3'; g.font = '600 21px ' + font;
            g.fillText('Scan to locate this device & check availability', W / 2, py + ph + 78);
            return c;
        },
        fileName: function (code, name) {
            return 'QR-' + code + '-' + String(name).replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') + '.png';
        },
        /** Downloads one device label as PNG. */
        download: function (text, name, code, location) {
            var c = window.LearnXQR.label(text, name, code, location);
            if (!c) { alert('Your browser cannot create the QR image.'); return; }
            var a = document.createElement('a');
            a.download = window.LearnXQR.fileName(code, name);
            c.toBlob(function (blob) {
                a.href = URL.createObjectURL(blob);
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
            }, 'image/png');
        },
        /** Downloads labels for many devices in one ZIP file. items: [{text,name,code,location}] */
        downloadAll: function (items, zipName, onProgress) {
            if (typeof JSZip === 'undefined') { alert('ZIP library failed to load.'); return Promise.resolve(); }
            var zip = new JSZip(), i = 0;
            function next() {
                if (i >= items.length) return Promise.resolve();
                var it = items[i++];
                return new Promise(function (resolve) {
                    var c = window.LearnXQR.label(it.text, it.name, it.code, it.location);
                    if (!c) { resolve(); return; }
                    c.toBlob(function (blob) { zip.file(window.LearnXQR.fileName(it.code, it.name), blob); if (onProgress) onProgress(i, items.length); resolve(); }, 'image/png');
                }).then(next);
            }
            return next().then(function () { return zip.generateAsync({ type: 'blob' }); }).then(function (blob) {
                var a = document.createElement('a');
                a.download = zipName || 'LearnX-QR-labels.zip';
                a.href = URL.createObjectURL(blob);
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
            });
        }
    };

    // Auto-render any [data-qr] element and wire [data-qr-download] buttons
    if (typeof qrcode !== 'undefined') {
        document.querySelectorAll('[data-qr]').forEach(function (el) {
            window.LearnXQR.render(el, el.getAttribute('data-qr'), parseInt(el.getAttribute('data-qr-size') || '160', 10));
        });
    }
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-qr-download]');
        if (!b) return;
        ev.preventDefault();
        window.LearnXQR.download(b.getAttribute('data-qr-download'), b.getAttribute('data-name') || 'Device', b.getAttribute('data-code') || '', b.getAttribute('data-location') || '');
    });
})();
