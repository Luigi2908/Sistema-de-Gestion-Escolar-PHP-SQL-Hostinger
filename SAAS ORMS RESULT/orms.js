/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

// ORMS shared kit — top loading bar, busy buttons, ajax wrapper, searchable
// dropdowns, csv. Everything hangs off window.ORMS, appearance comes from styles.css.
(function (window, document) {
    'use strict';

    var ORMS = window.ORMS || {};

    // jquery/swal resolved at call time — script may load before them
    function jq() { return window.jQuery; }
    function swal() { return window.Swal; }
    function isArr(v) { return Object.prototype.toString.call(v) === '[object Array]'; }
    function trim(s) { return String(s === null || s === undefined ? '' : s).replace(/^\s+|\s+$/g, ''); }

    function onReady(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    // native change so BOTH jquery .on() and inline onchange="" handlers fire once
    function fireChange(el) {
        if (!el) return;
        var ev;
        try { ev = new Event('change', { bubbles: true }); }
        catch (e) { ev = document.createEvent('HTMLEvents'); ev.initEvent('change', true, false); }
        el.dispatchEvent(ev);
    }

    // utils

    ORMS.esc = function (s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };
    var esc = ORMS.esc;

    ORMS.money = function (n, dp) {
        var v = parseFloat(n);
        return (isNaN(v) ? 0 : v).toFixed(dp === undefined || dp === null ? 2 : dp);
    };

    ORMS.debounce = function (fn, ms) {
        var t;
        return function () {
            var ctx = this, a = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, a); }, ms || 250);
        };
    };

    // top loading bar — ref counted, non blocking, classes only

    var barN = 0, barEl = null, barFill = null, barTimer = null, barHide = null, barPct = 0;

    function barNode() {
        if (barEl && barEl.parentNode) return barEl;
        if (!document.body) return null;
        barEl = document.getElementById('ormsBar');
        if (!barEl) {
            barEl = document.createElement('div');
            barEl.id = 'ormsBar';
            barFill = document.createElement('div');
            barFill.className = 'orms-bar-fill';
            barEl.appendChild(barFill);
            document.body.appendChild(barEl);
        } else {
            barFill = barEl.querySelector('.orms-bar-fill');
        }
        return barEl;
    }

    function barWidth(pct) { if (barFill) barFill.style.width = pct + '%'; } // computed width only

    ORMS.bar = {
        start: function () {
            barN++;
            if (barN > 1) return;               // already running
            var el = barNode();
            if (!el) return;
            clearTimeout(barHide); clearInterval(barTimer);
            barPct = 8;
            barWidth(barPct);
            el.classList.add('orms-bar-active');
            barTimer = setInterval(function () {  // trickle, never reaches 100 on its own
                if (barPct >= 92) return;
                barPct += Math.max(0.4, (92 - barPct) * 0.06);
                barWidth(barPct.toFixed(2));
            }, 220);
        },
        done: function () {
            if (barN === 0) return;             // safe when never started
            barN--;
            if (barN > 0) return;
            clearInterval(barTimer); barTimer = null;
            if (!barEl) return;
            barPct = 100;
            barWidth(100);
            barHide = setTimeout(function () {
                if (barN > 0) return;
                if (barEl) barEl.classList.remove('orms-bar-active');
                setTimeout(function () { if (barN === 0) { barPct = 0; barWidth(0); } }, 320);
            }, 220);
        },
        reset: function () { barN = barN > 0 ? 1 : 0; ORMS.bar.done(); }  // stuck-state escape hatch
    };

    // button busy state — stashes original html so restore is exact

    ORMS.busy = function (btn, on, label) {
        var $ = jq();
        if (!$ || !btn) return;
        var $btn = btn.jquery ? btn : $(btn);
        if (!$btn.length) return;
        $btn.each(function () {
            var $b = $(this);
            if (on) {
                if ($b.data('ormsBusy')) return;                 // already spinning
                $b.data('ormsBusy', 1).data('ormsHtml', $b.html());
                var txt = label || trim($b.text()) || 'Working…';
                $b.html('<i class="fas fa-spinner fa-spin"></i> ' + esc(txt))
                    .prop('disabled', true).attr('aria-busy', 'true');
            } else {
                if (!$b.data('ormsBusy')) return;
                var html = $b.data('ormsHtml');
                if (typeof html === 'string') $b.html(html);
                $b.prop('disabled', false).removeAttr('aria-busy')
                    .removeData('ormsBusy').removeData('ormsHtml');
            }
        });
    };

    // ajax — csrf + bar + guaranteed restore

    function csrfToken() {
        if (window.ORMS_CSRF) return window.ORMS_CSRF;
        var m = document.querySelector('meta[name="csrf-token"]');
        if (m && m.content) return m.content;
        var i = document.querySelector('input[name="csrf_token"]');
        return i ? i.value : '';
    }

    // action goes in the query AND the body — template handlers read $_GET or $_POST
    function buildUrl(action, opts) {
        var base = (opts && opts.url) || window.location.pathname;
        if (!action || /[?&]action=/.test(base)) return base;
        return base + (base.indexOf('?') === -1 ? '?' : '&') + 'action=' + encodeURIComponent(action);
    }

    function cut(raw) {
        var s = String(raw === null || raw === undefined ? '' : raw).replace(/\s+/g, ' ');
        s = s.replace(/^\s+/, '');
        return s.length > 200 ? s.slice(0, 200) + '…' : s;
    }

    // thenable used only when jquery is missing, so callers never blow up
    function stubPromise(msg) {
        var api = {};
        api.done = function () { return api; };
        api.fail = function (cb) { try { cb(msg); } catch (e) {} return api; };
        api.always = function (cb) { try { cb(); } catch (e) {} return api; };
        api.then = function (a, b) { if (b) { try { b(msg); } catch (e) {} } return api; };
        api['catch'] = function (cb) { try { cb(msg); } catch (e) {} return api; };
        api.promise = function () { return api; };
        return api;
    }

    ORMS.post = function (action, data, opts) {
        var $ = jq();
        opts = opts || {};
        if (!$) return stubPromise('jQuery is not loaded on this page');

        var dfd = $.Deferred();
        var cfg = { url: buildUrl(action, opts), type: opts.method || 'POST', dataType: 'text', cache: false };
        var tok = csrfToken();

        if (typeof FormData !== 'undefined' && data instanceof FormData) {
            if (action && (!data.has || !data.has('action'))) data.append('action', action);
            if (tok && (!data.has || !data.has('csrf_token'))) data.append('csrf_token', tok);
            cfg.data = data; cfg.processData = false; cfg.contentType = false;
        } else if (typeof data === 'string') {
            cfg.data = data + (data ? '&' : '') + 'action=' + encodeURIComponent(action || '') +
                (tok ? '&csrf_token=' + encodeURIComponent(tok) : '');
        } else {
            var p = $.extend({}, data || {});
            if (action && p.action === undefined) p.action = action;
            if (tok && p.csrf_token === undefined) p.csrf_token = tok;
            cfg.data = p;
        }
        if (opts.timeout) cfg.timeout = opts.timeout;

        // two signals, never both: a read gets the thin bar, a write gets the branded overlay.
        // opts.read forces it when an action name doesn't follow the get*/save* convention
        var isRead = opts.read !== undefined ? !!opts.read : ORMS.isRead(action);
        if (isRead) ORMS.bar.start(); else ORMS.proc.start(opts.verb || ORMS.verbOf(action));
        if (opts.btn) ORMS.busy(opts.btn, true, opts.busyLabel);

        $.ajax(cfg)
            .done(function (raw) {
                var res;
                try { res = JSON.parse(raw); }
                catch (e) {
                    var isHtml = typeof raw === 'string' && (/<(?:!doctype|html|head|body)/i.test(raw));
                    if (isHtml) {
                        if (/login\.php|iniciar sesión|sign in|name=["']username["']/i.test(raw)) {
                            var S = swal();
                            if (S) {
                                S.fire({
                                    icon: 'warning',
                                    title: 'Sesión expirada',
                                    text: 'Tu sesión ha expirado por inactividad. Por favor, inicia sesión nuevamente para continuar.',
                                    confirmButtonText: 'Iniciar sesión'
                                }).then(function () {
                                    window.location.href = 'login.php';
                                });
                            }
                            dfd.reject('Tu sesión ha expirado. Por favor inicia sesión nuevamente.', raw);
                            return;
                        }
                        dfd.reject('El servidor respondió con una página web en lugar de datos JSON. Es posible que haya ocurrido un error temporal o que la sesión haya caducado.', raw);
                        return;
                    }
                    dfd.reject('El servidor devolvió una respuesta no válida: ' + cut(raw), raw);
                    return;
                }
                if (res && res.auth_required) {
                    var S = swal();
                    if (S) {
                        S.fire({
                            icon: 'warning',
                            title: 'Sesión expirada',
                            text: res.message || 'Tu sesión ha expirado. Por favor inicia sesión nuevamente.',
                            confirmButtonText: 'Iniciar sesión'
                        }).then(function () {
                            window.location.href = 'login.php';
                        });
                    }
                    dfd.reject(res.message || 'Sesión expirada', res);
                    return;
                }
                dfd.resolve(res, raw);                       // success:false still resolves
            })
            .fail(function (xhr, status, err) {
                if (status === 'abort') { dfd.reject('Request cancelled', xhr); return; }
                if (xhr && xhr.status === 401) {
                    var S = swal();
                    if (S) {
                        S.fire({
                            icon: 'warning',
                            title: 'Sesión expirada',
                            text: 'Tu sesión ha expirado. Por favor inicia sesión nuevamente.',
                            confirmButtonText: 'Iniciar sesión'
                        }).then(function () {
                            window.location.href = 'login.php';
                        });
                    }
                    dfd.reject('Sesión expirada', xhr);
                    return;
                }
                var msg = 'Error en la solicitud (' + (xhr && xhr.status ? xhr.status : 0) + ' ' +
                    ((xhr && xhr.statusText) || status || err || 'error') + ')';
                if (xhr && xhr.responseText && !/<(?:!doctype|html)/i.test(xhr.responseText)) {
                    msg += ': ' + cut(xhr.responseText);
                }
                dfd.reject(msg, xhr);
            })
            .always(function () {
                if (isRead) ORMS.bar.done(); else ORMS.proc.done();   // always = a thrown error can never leave it stuck
                if (opts.btn) ORMS.busy(opts.btn, false);
            });

        return dfd.promise();
    };

    // same call, but {success:false} and transport errors reject + toast
    ORMS.postOrFail = function (action, data, opts) {
        var $ = jq();
        if (!$) return ORMS.post(action, data, opts);
        var dfd = $.Deferred();
        ORMS.post(action, data, opts).done(function (res) {
            if (res && res.success) { dfd.resolve(res); return; }
            var msg = (res && (res.message || res.error)) || 'Operation failed';
            ORMS.err(msg);
            dfd.reject(msg, res);
        }).fail(function (msg, xhr) {
            ORMS.err(msg || 'Connection error');
            dfd.reject(msg, xhr);
        });
        return dfd.promise();
    };

    // swal helpers

    ORMS.ok = function (msg) {
        var S = swal();
        if (!S) return null;
        return S.fire({
            toast: true, position: 'top-end', icon: 'success',
            title: String(msg || 'Done'), timer: 2000, timerProgressBar: true, showConfirmButton: false
        });
    };

    ORMS.err = function (msg, title) {
        var S = swal();
        if (!S) return null;
        return S.fire({ icon: 'error', title: title || 'Error', text: String(msg || 'Something went wrong') });
    };

    ORMS.confirmDelete = function (text, title) {
        var S = swal();
        if (!S) {
            var yes = window.confirm(text || 'Delete this record? This cannot be undone.');
            return { then: function (cb) { try { cb(yes); } catch (e) {} return this; } };
        }
        return S.fire({
            icon: 'warning',
            title: title || 'Are you sure?',
            text: text || 'This action cannot be undone',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: '<i class="fas fa-trash"></i> Delete',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel'
        }).then(function (r) { return !!(r && r.isConfirmed); });
    };

    // searchable dropdown — real <select> stays in the dom so serialize() keeps working

    var DD = 'ormsDd', ddSeq = 0, ddBound = false;

    function ddInstances() {
        var $ = jq();
        return $ ? $('.orms-dd-wrap.orms-dd-open') : null;
    }

    function ddCloseAll(skip) {
        var $set = ddInstances();
        if (!$set) return;
        $set.each(function () {
            var inst = jq()(this).data('ormsInst');
            if (inst && inst !== skip) ddClose(inst);
        });
    }

    // ---- mobile: panel renders as a bottom sheet (styles.css MOBILE LAYER) ----
    var DD_SHEET_MQ = '(max-width: 768px)';

    function ddSheetMode() {
        return !!(window.matchMedia && window.matchMedia(DD_SHEET_MQ).matches);
    }

    // ios pins position:fixed to the LAYOUT viewport, so an open keyboard covers the
    // sheet. visualViewport is the only thing that knows where the real bottom is
    function ddSheetLift() {
        var vv = window.visualViewport, s = document.documentElement.style;
        if (!vv || !document.getElementById('ormsDdBackdrop')) { s.removeProperty('--orms-dd-lift'); return; }
        s.setProperty('--orms-dd-lift', Math.max(0, window.innerHeight - vv.height - vv.offsetTop) + 'px');
    }

    // single source of truth — every close path funnels through ddClose, so the backdrop
    // and the scroll lock can never outlive the sheet that put them there
    function ddSheetSync() {
        var $ = jq();
        if (!$) return;
        var on = ddSheetMode() && $('.orms-dd-wrap.orms-dd-open').length > 0;
        var $bd = $('#ormsDdBackdrop');
        if (on && !$bd.length) $('<div id="ormsDdBackdrop" class="orms-dd-backdrop"></div>').appendTo(document.body);
        else if (!on && $bd.length) $bd.remove();
        $(document.body).toggleClass('orms-dd-sheet-open', on);
        ddSheetLift();
    }

    function ddBindGlobal() {
        var $ = jq();
        if (!$ || ddBound) return;
        ddBound = true;
        $(document)
            .on('mousedown.ormsdd', function (e) {
                if ($(e.target).closest('.orms-dd-wrap').length) return;   // click outside
                ddCloseAll();
            })
            .on('keydown.ormsdd', function (e) {
                if (e.key === 'Escape' || e.keyCode === 27) ddCloseAll();
            });

        // crossing the breakpoint mid-open would leave a sheet styled as an anchored panel
        // (or the reverse) — close out and let the next open pick the right mode
        if (window.matchMedia) {
            var mq = window.matchMedia(DD_SHEET_MQ), onMq = function () { ddCloseAll(); ddSheetSync(); };
            if (mq.addEventListener) mq.addEventListener('change', onMq);
            else if (mq.addListener) mq.addListener(onMq);
        }
        if (window.visualViewport) window.visualViewport.addEventListener('resize', ddSheetLift);
    }

    function ddText($sel, val) {
        var t = '';
        $sel.find('option').each(function () { if (this.value === val) { t = this.text; return false; } });
        return t;
    }

    function ddPlaceholder(inst) {
        return inst.opts.placeholder || inst.$sel.attr('data-placeholder') ||
            (inst.multi ? 'Select options…' : 'Select…');
    }

    // paint control: label for single, chips for multi
    function ddSync(inst) {
        var $sel = inst.$sel, html;
        if (inst.multi) {
            var vals = $sel.val() || [];
            if (!vals.length) html = '<span class="orms-dd-ph">' + esc(ddPlaceholder(inst)) + '</span>';
            else {
                html = '';
                for (var i = 0; i < vals.length; i++) {
                    html += '<span class="orms-dd-chip">' + esc(ddText($sel, vals[i]) || vals[i]) +
                        '<i class="fas fa-times orms-dd-chip-x" data-val="' + esc(vals[i]) + '"></i></span>';
                }
            }
        } else {
            var $opt = $sel.find('option:selected').first();
            var txt = $opt.length ? $opt.text() : '';
            html = '<span class="orms-dd-label">' + esc(txt || ddPlaceholder(inst)) + '</span>';
        }
        inst.$ctrl.html(html + '<i class="fas fa-chevron-down orms-dd-caret"></i>');
        var off = !!$sel.prop('disabled');
        if (inst.multi) inst.$ctrl.attr('aria-disabled', off ? 'true' : 'false');
        else inst.$ctrl.prop('disabled', off);
    }

    function ddActivate(inst, $opt) {
        inst.$list.find('.orms-dd-opt.active').removeClass('active');
        if (!$opt || !$opt.length) return;
        $opt.addClass('active');
        try { $opt[0].scrollIntoView({ block: 'nearest' }); } catch (e) {}
    }

    function ddRenderList(inst, term) {
        term = String(term || '').toLowerCase();
        var html = '', shown = 0;
        inst.$sel.find('option').each(function () {
            var label = this.text, sub = this.getAttribute('data-sub') || '';
            if (term && (label + ' ' + sub).toLowerCase().indexOf(term) === -1) return;   // filter matches sub too
            shown++;
            var icon = inst.multi
                ? '<i class="' + (this.selected ? 'fas fa-square-check' : 'far fa-square') + '"></i> '
                : '<i class="' + (this.selected ? 'fas fa-check' : 'fas fa-fw') + '"></i> ';
            // data-sub -> two-line option, data-av -> leading avatar chip
            var av = this.getAttribute('data-av') || '';
            var body = sub
                ? (av ? '<span class="orms-dd-av">' + esc(av) + '</span>' : '') +
                  '<span class="orms-dd-rich"><span class="orms-dd-rich-t">' + esc(label) + '</span><small>' + esc(sub) + '</small></span>'
                : esc(label);
            html += '<div class="orms-dd-opt' + (this.disabled ? ' orms-dd-disabled' : '') + (sub ? ' orms-dd-opt-rich' : '') +
                '" role="option" data-val="' + esc(this.value) + '">' + icon + body + '</div>';
        });
        inst.$list.html(shown ? html : '<div class="orms-dd-empty"><i class="fas fa-ban"></i> No matches</div>');
        ddActivate(inst, inst.$list.find('.orms-dd-opt').not('.orms-dd-disabled').first());
    }

    function ddOpen(inst) {
        if (inst.open || inst.$sel.prop('disabled')) return;
        ddCloseAll(inst);
        inst.open = true;
        inst.$wrap.addClass('orms-dd-open');
        inst.$ctrl.addClass('orms-dd-open');
        inst.$panel.addClass('orms-dd-open');
        inst.$search.val('');
        ddRenderList(inst, '');
        ddSheetSync();
        // no autofocus in sheet mode — the keyboard would eat most of a 70dvh sheet before
        // the user has even seen the list. tapping the search box still filters
        if (!ddSheetMode()) setTimeout(function () { inst.$search.trigger('focus'); }, 0);
    }

    function ddClose(inst) {
        if (!inst || !inst.open) return;
        inst.open = false;
        inst.$wrap.removeClass('orms-dd-open');
        inst.$ctrl.removeClass('orms-dd-open');
        inst.$panel.removeClass('orms-dd-open');
        ddSheetSync();
    }

    function ddPick(inst, val) {
        var $sel = inst.$sel;
        if (inst.multi) {
            var cur = $sel.val() || [], i = cur.indexOf(val);
            $sel.val(i === -1 ? cur.concat([val]) : cur.slice(0, i).concat(cur.slice(i + 1)));
        } else {
            $sel.val(val);
        }
        fireChange($sel[0]);                       // page code keeps working
        if (inst.multi) { ddRenderList(inst, inst.$search.val()); return; }
        ddClose(inst);
        // hand focus back only if the page's change handler did not move it (keyboard flow)
        if (inst.$wrap.find(document.activeElement).length) inst.$ctrl.trigger('focus');
    }

    function ddMove(inst, dir) {
        var $opts = inst.$list.find('.orms-dd-opt').not('.orms-dd-disabled');
        if (!$opts.length) return;
        var i = $opts.index(inst.$list.find('.orms-dd-opt.active'));
        i = i < 0 ? (dir > 0 ? 0 : $opts.length - 1) : i + dir;
        if (i < 0) i = $opts.length - 1;
        if (i >= $opts.length) i = 0;
        ddActivate(inst, $opts.eq(i));
    }

    function ddFieldName($sel) {
        var $g = $sel.closest('.form-group,.filter-group');
        var t = ($g.length ? $g.find('label').first().text() : '') || $sel.attr('data-label') || $sel.attr('name') || '';
        return trim(String(t).replace(/\*/g, '')) || 'a value';
    }

    // hidden select + native required = unfocusable-control error, so we guard it ourselves
    function ddFormGuard($sel) {
        var $ = jq(), $f = $sel.closest('form');
        if (!$f.length || $f.data('ormsGuard')) return;
        $f.data('ormsGuard', 1).on('submit.ormsdd', function (e) {
            var bad = null;
            $f.find('select[data-orms-req="1"]').each(function () {
                if (bad) return;
                var v = $(this).val();
                if (v === null || v === '' || (isArr(v) && !v.length)) bad = $(this);
            });
            if (!bad) return;
            e.preventDefault();
            ORMS.err('Please select ' + ddFieldName(bad));
            var inst = bad.data(DD);
            if (inst) ddOpen(inst);
            return false;
        });
    }

    function ddBuild($sel, opts, multi) {
        var $ = jq();
        if (multi && !$sel.prop('multiple')) $sel.prop('multiple', true);
        if ($sel.prop('required')) { $sel.removeAttr('required').attr('data-orms-req', '1'); ddFormGuard($sel); }

        var inst = { $sel: $sel, multi: !!multi, opts: opts || {}, open: false };
        inst.$wrap = $('<div class="orms-dd-wrap"></div>').attr('id', 'ormsDd' + (++ddSeq));
        inst.$ctrl = multi
            ? $('<div class="orms-dd orms-dd-multi" role="button" tabindex="0" aria-haspopup="listbox"></div>')
            : $('<button type="button" class="orms-dd" aria-haspopup="listbox"></button>');
        inst.$panel = $('<div class="orms-dd-panel" role="listbox"></div>');
        inst.$search = $('<input type="text" class="orms-dd-search" autocomplete="off" spellcheck="false">')
            .attr('placeholder', inst.opts.searchPlaceholder || inst.$sel.attr('data-search-ph') || 'Search…');
        inst.$list = $('<div class="orms-dd-list"></div>');

        inst.$panel.append(inst.$search, inst.$list);
        inst.$wrap.append(inst.$ctrl, inst.$panel);
        $sel.addClass('initially-hidden').after(inst.$wrap);
        $sel.data(DD, inst);
        inst.$wrap.data('ormsInst', inst);

        inst.$ctrl.on('click', function (e) {
            if ($(e.target).closest('.orms-dd-chip-x').length) return;   // chip x handled below
            inst.open ? ddClose(inst) : ddOpen(inst);
        });
        inst.$ctrl.on('click', '.orms-dd-chip-x', function (e) {
            e.stopPropagation();
            ddPick(inst, $(this).attr('data-val'));
        });
        inst.$ctrl.on('keydown', function (e) {
            var k = e.key, c = e.keyCode;
            if (k === 'ArrowDown' || k === 'Enter' || k === ' ' || c === 40 || c === 13 || c === 32) {
                e.preventDefault();
                ddOpen(inst);
            }
        });

        inst.$search.on('input', function () { ddRenderList(inst, this.value); });
        inst.$search.on('keydown', function (e) {
            var k = e.key, c = e.keyCode;
            if (k === 'ArrowDown' || c === 40) { e.preventDefault(); ddMove(inst, 1); }
            else if (k === 'ArrowUp' || c === 38) { e.preventDefault(); ddMove(inst, -1); }
            else if (k === 'Enter' || c === 13) {
                e.preventDefault();                                        // never submit the form
                var $a = inst.$list.find('.orms-dd-opt.active').first();
                if ($a.length) ddPick(inst, $a.attr('data-val'));
            } else if (k === 'Escape' || c === 27) { e.preventDefault(); ddClose(inst); inst.$ctrl.trigger('focus'); }
            else if (k === 'Tab' || c === 9) ddClose(inst);
        });

        inst.$list.on('click', '.orms-dd-opt', function () {
            var $o = $(this);
            if ($o.hasClass('orms-dd-disabled')) return;
            ddPick(inst, $o.attr('data-val'));
        });
        inst.$list.on('mouseenter', '.orms-dd-opt', function () {
            var $o = $(this);
            if (!$o.hasClass('orms-dd-disabled')) ddActivate(inst, $o);
        });

        // outside code doing .val(x).trigger('change') repaints the control
        $sel.on('change.ormsdd', function () {
            ddSync(inst);
            if (inst.open) ddRenderList(inst, inst.$search.val());
        });

        ddSync(inst);
        return inst;
    }

    function ddApply(selector, options, multi) {
        var $ = jq();
        if (!$ || !selector) return null;
        var $els = selector.jquery ? selector : $(selector);
        if (!$els.length) return $els || null;                 // missing selector -> no-op
        ddBindGlobal();
        $els.each(function () {
            if (!this.tagName || this.tagName.toLowerCase() !== 'select') return;
            var $sel = $(this), inst = $sel.data(DD);
            if (inst) { ddSync(inst); return; }                // already upgraded
            ddBuild($sel, options || {}, multi);
        });
        return $els;
    }

    ORMS.dropdown = function (selector, options) { return ddApply(selector, options, false); };
    ORMS.multiselect = function (selector, options) { return ddApply(selector, options, true); };

    // call after options are repopulated by ajax (class -> section chains)
    ORMS.dropdown.refresh = function (selector) {
        var $ = jq();
        if (!$ || !selector) return;
        var $els = selector.jquery ? selector : $(selector);
        $els.each(function () {
            var $sel = $(this), inst = $sel.data(DD);
            if (!inst) { ddApply($sel, {}, !!this.multiple); return; }
            ddSync(inst);
            if (inst.open) ddRenderList(inst, inst.$search.val());
        });
    };

    ORMS.dropdown.destroy = function (selector) {
        var $ = jq();
        if (!$ || !selector) return;
        var $els = selector.jquery ? selector : $(selector);
        $els.each(function () {
            var $sel = $(this), inst = $sel.data(DD);
            if (!inst) return;
            inst.$wrap.off().remove();
            $sel.off('.ormsdd').removeClass('initially-hidden').removeData(DD);
            if ($sel.attr('data-orms-req')) $sel.attr('required', 'required').removeAttr('data-orms-req');
        });
    };

    ORMS.multiselect.refresh = ORMS.dropdown.refresh;
    ORMS.multiselect.destroy = ORMS.dropdown.destroy;

    // csv

    // rfc 4180 with delimiter auto-detection (',' or ';'), Excel BOM support, "" escapes, embedded newlines
    ORMS.parseCSV = function (text) {
        var rows = [], row = [], val = '', inQ = false, i, c, s, delim = ',';
        if (text === null || text === undefined) return rows;
        s = String(text);
        if (s.charCodeAt(0) === 0xFEFF) s = s.slice(1);        // strip UTF-8 BOM

        // Check for Excel delimiter directive (sep=; or sep=,)
        var sepMatch = s.match(/^sep=([,;\t])(?:\r\n|\r|\n)/i);
        if (sepMatch) {
            delim = sepMatch[1];
            s = s.slice(sepMatch[0].length);
        } else {
            // Auto-detect delimiter from the first non-empty line (outside quotes)
            var countSemi = 0, countComma = 0, inDetectQ = false;
            for (var dIdx = 0; dIdx < s.length; dIdx++) {
                var dc = s.charAt(dIdx);
                if (dc === '"') inDetectQ = !inDetectQ;
                else if (!inDetectQ) {
                    if (dc === ';') countSemi++;
                    else if (dc === ',') countComma++;
                    else if (dc === '\n' || dc === '\r') {
                        if (countSemi > 0 || countComma > 0) break; // examined first full line
                    }
                }
            }
            if (countSemi > countComma) delim = ';';
            else delim = ',';
        }

        for (i = 0; i < s.length; i++) {
            c = s.charAt(i);
            if (inQ) {
                if (c !== '"') { val += c; continue; }
                if (s.charAt(i + 1) === '"') { val += '"'; i++; }   // escaped quote
                else inQ = false;
            } else if (c === '"') {
                inQ = true;
            } else if (c === delim) {
                row.push(val); val = '';
            } else if (c === '\n' || c === '\r') {
                if (c === '\r' && s.charAt(i + 1) === '\n') i++;    // crlf
                row.push(val); rows.push(row); row = []; val = '';
            } else {
                val += c;
            }
        }
        if (val !== '' || row.length) { row.push(val); rows.push(row); }  // last line, no trailing blank
        return rows;
    };

    function csvCell(v, delim) {
        var s = (v === null || v === undefined) ? '' : String(v);
        var pattern = delim === ';' ? /[";\r\n]/ : /[",\r\n]/;
        return pattern.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }

    ORMS.downloadCSV = function (filename, rows, delim) {
        if (!rows || !rows.length || typeof Blob === 'undefined') return false;
        // Default to ';' so Excel in Spanish automatically separates into columns A, B, C, D...
        delim = delim || ';';
        var out = [];
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i] || [], line = [];
            for (var j = 0; j < r.length; j++) line.push(csvCell(r[j], delim));
            out.push(line.join(delim));
        }
        var blob = new Blob(['\ufeff' + out.join('\r\n')], { type: 'text/csv;charset=utf-8;' }); // bom = excel utf8
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename || 'export.csv';
        a.className = 'initially-hidden';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () {
            URL.revokeObjectURL(url);
            if (a.parentNode) a.parentNode.removeChild(a);
        }, 0);
        return true;
    };

    // print ONE element and nothing else. walks target -> body hiding every sibling on the way up,
    // so page chrome never has to be enumerated into a no-print blacklist (and new chrome can't leak in).
    // display:none rather than visibility, so a card taller than one page still paginates properly
    ORMS.printOnly = function (selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!el) { window.print(); return; }

        var hidden = [], n, sibs, i;
        for (n = el; n && n !== document.body && n.parentNode; n = n.parentNode) {
            sibs = n.parentNode.children;
            for (i = 0; i < sibs.length; i++) {
                if (sibs[i] !== n) { sibs[i].classList.add('print-hidden'); hidden.push(sibs[i]); }
            }
        }

        function restore() {
            for (var j = 0; j < hidden.length; j++) hidden[j].classList.remove('print-hidden');
            window.removeEventListener('afterprint', restore);
        }
        window.addEventListener('afterprint', restore);
        window.print();
        if (!('onafterprint' in window)) restore();      // no event support -> print() already returned
    };

    // ---- verification QR ----
    // fills every .rc-qr[data-qr] once, synchronously. the qrcodejs lib is pinned on card pages
    // only — anywhere it's absent this is a no-op and the card simply prints without a code
    ORMS.qr = function (root) {
        if (!window.QRCode) return;
        var scope = root ? (root.jquery ? root[0] : root) : document;
        if (!scope || !scope.querySelectorAll) return;
        var els = scope.querySelectorAll('.rc-qr[data-qr]:not([data-qr-done])');
        for (var i = 0; i < els.length; i++) {
            els[i].setAttribute('data-qr-done', '1');
            try {
                new window.QRCode(els[i], {
                    text: els[i].getAttribute('data-qr'),
                    width: 84, height: 84,
                    correctLevel: window.QRCode.CorrectLevel.M
                });
            } catch (e) {}
        }
    };

    // ---- processing overlay: writes only ----
    // counter-based so two concurrent writes can't cancel each other's overlay.
    // pure vanilla — login.php has no jQuery and still needs this.

    var procN = 0, procEl = null;

    function procNode() {
        if (procEl && procEl.parentNode) return procEl;
        if (!document.body) return null;
        procEl = document.getElementById('ormsProc');
        if (!procEl) {
            procEl = document.createElement('div');
            procEl.id = 'ormsProc';
            procEl.className = 'proc-ov';
            procEl.setAttribute('role', 'status');
            procEl.setAttribute('aria-live', 'polite');
            procEl.innerHTML = '<img class="proc-logo" alt="" src="' +
                (window.ORMS_LOGO || 'icon-192.png') + '">' +
                '<div class="proc-bar"><i></i></div>' +
                '<div class="proc-label"></div><div class="proc-count"></div>';
            document.body.appendChild(procEl);
        }
        return procEl;
    }

    // esc must not dismiss a write in flight
    document.addEventListener('keydown', function (e) {
        if (procN && (e.key === 'Escape' || e.keyCode === 27)) { e.preventDefault(); e.stopPropagation(); }
    }, true);

    ORMS.proc = {
        start: function (verb, note) {
            procN++;
            var el = procNode();
            if (!el) return;
            el.querySelector('.proc-label').textContent = verb || 'Working…';
            el.querySelector('.proc-count').textContent = note || '';
            el.setAttribute('aria-busy', 'true');
            el.classList.add('proc-on');
        },
        note: function (t) {                        // known-count ops: "120 / 500"
            var el = procN ? procNode() : null;
            if (el) el.querySelector('.proc-count').textContent = t || '';
        },
        done: function () {
            if (procN === 0) return;
            procN--;
            if (procN > 0) return;
            var el = procNode();
            if (!el) return;
            el.classList.remove('proc-on');
            el.removeAttribute('aria-busy');
            el.querySelector('.proc-count').textContent = '';
        },
        reset: function () { procN = procN > 0 ? 1 : 0; ORMS.proc.done(); }
    };

    // verb comes off the action name, so no call site ever passes one by hand.
    // the tail alternation catches the readers that don't start with a read verb —
    // adminStats / studentStats / teacherStats / recentActivity / promotePreview / bulkCards.
    // misfiling a read here is the worst failure mode: it dims the whole screen on a refresh.
    var READ_RE = /^(get|load|fetch|list|search|check|lookup|preview|export|report|tabulation)|(?:stats|preview|activity|cards)$/i;
    var VERBS = [
        [/^(bulk)?import/i,        'Importing…'],
        [/^unpublish/i,            'Unpublishing…'],
        [/^publish/i,              'Publishing…'],
        [/^(bulk)?(delete|remove)/i, 'Deleting…'],
        [/^approve/i,              'Approving…'],
        [/^reject/i,               'Rejecting…'],
        [/^(cancel|void)/i,        'Cancelling…'],
        [/^upload/i,               'Uploading…'],
        [/^(login|signin|signup|register)/i, 'Signing in…'],
        [/^(promote|migrate)/i,    'Processing…'],
        [/^send|^notify|^mail/i,   'Sending…'],
        [/^(toggle|update|save|set|add|create|edit|assign)/i, 'Saving…']
    ];

    ORMS.verbOf = function (fn) {
        for (var i = 0; i < VERBS.length; i++) if (VERBS[i][0].test(fn || '')) return VERBS[i][1];
        return 'Saving…';
    };

    ORMS.isRead = function (fn) { return READ_RE.test(fn || ''); };

    // ---- smooth section switching ----
    // wraps a dom swap in a same-document view transition, so tabs crossfade instead of snapping.
    // the sidebar/bottom-nav keep their view-transition-name + 0s duration, so only content moves.
    // run the dt/chart re-measure INSIDE fn — it lands in the same frame as the swap, no flicker.
    ORMS.swap = function (fn) {
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (typeof fn !== 'function') return;
        if (!document.startViewTransition || reduce) { fn(); return; }   // graceful everywhere else
        try { document.startViewTransition(fn); } catch (e) { fn(); }
    };

    // ---- navigation feedback ----
    // the top bar starts the instant a real page link is clicked, so a slow page never
    // looks like a dead click while the browser is still fetching it
    function navLink(e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return null;
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return null;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#') return null;                       // in-page anchor
        if (a.target && a.target !== '_self') return null;                      // new tab/window
        if (a.hasAttribute('download')) return null;
        if (/^(mailto:|tel:|javascript:|blob:|data:)/i.test(href)) return null;
        if (a.origin && a.origin !== window.location.origin) return null;       // external
        if (a.href.split('#')[0] === window.location.href.split('#')[0]) return null;  // same page
        return a;
    }

    // a real (non-ajax) form post navigates away — login, setup, password reset. the browser shows
    // nothing until the response lands, which is the dead gap the overlay exists to fill.
    // bubble phase on document runs AFTER the form's own handler, so an ajax form has already
    // called preventDefault by now and is skipped (it gets the overlay from ORMS.post instead)
    function formVerb(f) {
        var id = (f.getAttribute('id') || '') + ' ' + (f.getAttribute('action') || '') + ' ' + window.location.pathname;
        if (/login/i.test(id))  return 'Signing in…';
        if (/signup|register/i.test(id)) return 'Creating your account…';
        if (/reset|forgot|otp|verify/i.test(id)) return 'Verifying…';
        if (/setup|install/i.test(id)) return 'Setting up…';
        return 'Saving…';
    }

    // mobile: Responsive folds surplus columns from the right, so the Actions column —
    // always last — is the first thing to disappear and every edit becomes expand-then-tap.
    // pin the identity column and the last one. columnDefs is applied BEFORE columns, so a
    // page that sets its own responsivePriority (teachers) still wins. no page uses
    // columnDefs, so nothing can collide with this default.
    function dtResponsiveDefaults() {
        var $ = jq();
        if (!$ || !$.fn || !$.fn.dataTable) return;
        $.extend(true, $.fn.dataTable.defaults, {
            columnDefs: [
                { responsivePriority: 1, targets: 0 },
                { responsivePriority: 2, targets: -1 }
            ]
        });
    }

    onReady(function () {
        dtResponsiveDefaults();
        ddBindGlobal();

        document.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;                     // ajax form — ORMS.post owns the signal
            var f = e.target;
            if (!f || f.tagName !== 'FORM' || f.hasAttribute('data-no-overlay')) return;
            if ((f.getAttribute('target') || '') !== '') return; // opens elsewhere, page stays put
            ORMS.proc.start(formVerb(f));
        }, false);
        // failsafe: about/backup/sessions ship a FOUC-guarded <body class="initially-hidden"> and
        // uncover it at the end of their own script — one thrown error there and the page stays
        // blank forever. reveal here too, same moment, so that can never happen
        if (document.body) document.body.classList.remove('initially-hidden');
        ORMS.qr();                                          // server-rendered cards get their code on load
        document.addEventListener('click', function (e) { if (navLink(e)) ORMS.bar.start(); }, true);
        // back/forward out of bfcache restores the page exactly as it was left — mid-submit
        // that means a frozen bar and a stuck overlay unless both are cleared on restore
        // bfcache restores the page exactly as it was left — an open sheet comes back with
        // its backdrop and body scroll lock intact, so clear those on the same beat
        window.addEventListener('pageshow', function () { ORMS.bar.reset(); ORMS.proc.reset(); ddCloseAll(); ddSheetSync(); });
    });

    // ---- page actions in the header ----
    // every list section already ships a `.section-header > .btn-group-inline` (Refresh / Add /
    // Template / Import). rather than duplicate those 30-odd buttons per page, the group is MOVED
    // into #headerActions — moving keeps inline onclick and any id-bound jquery handler alive,
    // which cloning would not. tabbed pages tag each group with its pane so only the section you
    // are looking at shows its toolbar.
    function haHost() { return document.getElementById('headerActions'); }

    ORMS.hoistActions = function () {
        var host = haHost();
        if (!host) return;
        var groups = document.querySelectorAll('.section-header > .btn-group-inline');
        for (var i = 0; i < groups.length; i++) {
            var g = groups[i];
            if (g.getAttribute('data-hoisted')) continue;
            g.setAttribute('data-hoisted', '1');
            g.classList.add('ha-group');
            var pane = g.closest ? g.closest('.tab-pane') : null;
            if (pane && pane.id) g.setAttribute('data-pane', pane.id);
            host.appendChild(g);
        }
        // pages with no section toolbar at all (logs, sessions, roles, attendance) still get Refresh
        if (!host.querySelector('.ha-group')) {
            var w = document.createElement('div');
            w.className = 'btn-group-inline ha-group';
            w.innerHTML = '<button type="button" class="btn btn-primary" title="Refresh">' +
                          '<i class="fas fa-sync"></i> <span class="ha-label">Refresh</span></button>';
            w.firstChild.addEventListener('click', function () { window.location.reload(); });
            host.appendChild(w);
        }
        // wrap bare labels so css can drop them on a narrow screen, icon-only
        var btns = host.querySelectorAll('.ha-group > button, .ha-group > a');
        for (var b = 0; b < btns.length; b++) {
            var el = btns[b];
            if (el.getAttribute('data-ha')) continue;
            el.setAttribute('data-ha', '1');
            if (!el.getAttribute('title')) el.setAttribute('title', (el.textContent || '').trim());
            for (var n = 0; n < el.childNodes.length; n++) {
                var node = el.childNodes[n];
                if (node.nodeType === 3 && node.textContent.trim() !== '') {
                    var sp = document.createElement('span');
                    sp.className = 'ha-label';
                    sp.textContent = node.textContent.trim();
                    el.replaceChild(sp, node);
                }
            }
        }
        ORMS.syncActions();
    };

    // show only the active pane's toolbar; untabbed pages show everything they hoisted
    ORMS.syncActions = function () {
        var host = haHost();
        if (!host) return;
        var active = document.querySelector('.tab-pane.active');
        var groups = host.querySelectorAll('.ha-group');
        for (var i = 0; i < groups.length; i++) {
            var p = groups[i].getAttribute('data-pane');
            groups[i].hidden = !!(p && active && p !== active.id);
        }
    };

    // ---- section tabs ----
    // A class has several sections, and on the pages where ONE section IS the view — marks entry,
    // results, the daily register, the timetable grid, the broadsheet — switching it is the single
    // most common thing anybody does, buried in a dropdown among five other filters. This paints the
    // SAME choice as a tab row and keeps the two in step; the page still owns what a change does.
    //   items: [{id, name, students?}]
    //   opts:  {all: true|'label', allValue: 0|'', min: 2, icon: 'fa-users-rectangle'}
    ORMS.sectionTabs = function (barSel, selectSel, items, opts) {
        var $ = jq(); if (!$) return;
        opts = opts || {};
        var $bar = $(barSel);
        var cur = String($(selectSel).val() == null ? '' : $(selectSel).val());
        // one section in reach = nothing to switch between, and the dropdown already says which
        if (!items || items.length < (opts.min === undefined ? 2 : opts.min)) {
            $bar.empty().addClass('initially-hidden').hide();
            return;
        }
        var icon = opts.icon || 'fa-users-rectangle';
        var btn = function (v, label, count) {
            return '<button type="button" class="tab-btn' + (String(v) === cur ? ' active' : '') +
                   '" role="tab" data-sec="' + v + '">' + label +
                   (count === undefined || count === null ? '' : ' <span class="tab-count">' + count + '</span>') + '</button>';
        };
        var h = !opts.all ? '' : btn(opts.allValue === undefined ? 0 : opts.allValue,
                    '<i class="fas fa-layer-group"></i> ' + (typeof opts.all === 'string' ? opts.all : 'All sections'));
        items.forEach(function (s) { h += btn(s.id, '<i class="fas ' + icon + '"></i> ' + esc(s.name), s.students); });
        $bar.html(h).removeClass('initially-hidden').show();
    };

    // wire once per bar. onPick omitted -> the select's own change handler runs, so a page that
    // already reacts to the dropdown needs no new logic at all.
    ORMS.sectionTabs.bind = function (barSel, selectSel, onPick) {
        var $ = jq(); if (!$) return;
        var ns = 'click.secTabs' + String(barSel).replace(/[^a-z0-9]/gi, '');   // one namespace per bar
        $(document).off(ns).on(ns, barSel + ' .tab-btn', function () {
            var v = this.getAttribute('data-sec');
            if (v === String($(selectSel).val() == null ? '' : $(selectSel).val())) return;   // already here
            $(selectSel).val(v);
            if (ORMS.dropdown && ORMS.dropdown.refresh) ORMS.dropdown.refresh(selectSel);
            $(barSel).find('.tab-btn').removeClass('active');
            $(barSel).find('.tab-btn[data-sec="' + v + '"]').addClass('active');
            if (typeof onPick === 'function') onPick(v); else $(selectSel).trigger('change');
        });
    };

    // ---- chip-row builders ----
    // A wide table (12+ columns) drifts its header away from its body and nobody reads the right
    // half. These stack `[label chip] ...... value` rows inside ONE cell, so 13 flat columns become
    // 6 grouped ones. Styling lives in styles.css under `.chip-table` and applies to nothing else.
    ORMS.chip = function (cls, icon, label) {
        return '<span class="chip ' + cls + '"><i class="fas ' + icon + '"></i>' + label + '</span>';
    };
    // one row: chip pinned left, value pinned right
    ORMS.crow = function (chipHtml, value, valCls) {
        return '<div class="chip-row">' + chipHtml + '<span class="val ' + (valCls || '') + '">' + value + '</span></div>';
    };
    // falsy entries drop out, so a permission-gated row is just `cond ? ORMS.crow(...) : ''`
    ORMS.stack = function (rows) { return '<div class="cell-stack">' + rows.filter(Boolean).join('') + '</div>'; };
    ORMS.box   = function (s) { return '<span class="val-box">' + esc(s) + '</span>'; };
    ORMS.DASH  = '<span class="val-muted">&mdash;</span>';
    // exports must ship text, not chip markup
    ORMS.asText = { body: function (d) {
        return String(d).replace(/<[^>]*>/g, ' ')
            .replace(/&(nbsp|mdash|ndash|amp|lt|gt|quot|#39);/g, function (m, e) {
                return { nbsp: ' ', mdash: '—', ndash: '–', amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'" }[e];
            })
            .replace(/\s+/g, ' ').trim();
    } };

    onReady(function () {
        ORMS.hoistActions();
        // tab handlers are per-page and run on the same click, so re-sync on the next frame
        document.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target.closest('.tab-btn') : null;
            if (t) setTimeout(ORMS.syncActions, 0);
        });
    });

    window.ORMS = ORMS;
})(window, document);
