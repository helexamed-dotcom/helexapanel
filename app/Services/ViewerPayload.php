<?php
declare(strict_types=1);

namespace HeleXa\Services;

/**
 * Builds the block injected into every lesson right after <head>.
 *
 * It runs before the lesson's own scripts, which is why it can install a
 * localStorage stand-in and a watermark that the page cannot accidentally
 * overwrite. Everything here is a DETERRENT layer: the real protection is
 * that the bytes are only served to an authorised, authenticated session.
 */
final class ViewerPayload
{
    public static function build(
        array $user,
        array $content,
        array $clientState,
        string $parentOrigin,
        array $highlights = []
    ): string {
        $json = static fn (mixed $value): string => (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $watermarkOn = Settings::bool('watermark_enabled', true);
        $printBlock  = Settings::bool('print_protection_enabled', true) && (int) $content['is_printable'] === 0;

        // Date only, not the clock time: the payload must stay byte-identical
        // for the same student on the same day so ETag revalidation can work.
        $watermarkText = sprintf(
            '%s · %s · %s',
            (string) $user['full_name'],
            (string) $user['username'],
            Jalali::date(time())
        );

        $config = $json([
            'origin'     => $parentOrigin,
            'contentId'  => (string) $content['uuid'],
            'state'      => (object) $clientState,
            'watermark'  => $watermarkOn ? $watermarkText : '',
            'blockPrint' => $printBlock,
            'highlight'  => [
                'enabled' => Settings::bool('highlight_enabled', true),
                'colors'  => \HeleXa\Controllers\HighlightController::COLORS,
                'items'   => $highlights,
            ],
        ]);

        $printCss = $printBlock ? <<<'CSS'
@media print {
  html, body { display: none !important; visibility: hidden !important; }
  body::after {
    content: "چاپ این محتوا مجاز نیست."; display: block !important; visibility: visible !important;
    position: fixed; inset: 0; background: #fff; color: #666;
    font: 16px/2 system-ui, sans-serif; text-align: center; padding-top: 40vh;
  }
}
CSS : '';

        // A local Persian face registered under the family names lessons commonly
        // request. If the lesson's own web font loads, its later declaration wins;
        // if the CDN is unreachable, the text still renders in a real Persian font
        // instead of a system fallback.
        $fontLink = Settings::bool('viewer_local_font', true)
            ? '<link rel="stylesheet" href="' . htmlspecialchars($parentOrigin, ENT_QUOTES, 'UTF-8') . '/assets/css/lesson-fonts.css">'
            : '';

        return <<<HTML
{$fontLink}
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex, nofollow, noarchive, noimageindex">
<style id="helexa-guard">
{$printCss}
#helexa-watermark{
  position:fixed; inset:0; z-index:2147483000; pointer-events:none;
  display:flex; flex-wrap:wrap; align-content:center; justify-content:center;
  gap:120px 60px; overflow:hidden; user-select:none;
}
#helexa-watermark span{
  transform:rotate(-30deg); font:600 15px/1.4 system-ui,-apple-system,'Segoe UI',Tahoma,sans-serif;
  color:rgba(23,59,103,.075); white-space:nowrap; letter-spacing:.4px;
}
#helexa-print-shield{
  position:fixed; inset:0; background:#fff; z-index:2147483600; display:none;
}
mark.hlx{
  background:var(--hlx-c,rgba(255,214,0,.42)); color:inherit; padding:0; border-radius:2px;
  box-decoration-break:clone; -webkit-box-decoration-break:clone; cursor:pointer;
}
mark.hlx[data-color="yellow"]{--hlx-c:rgba(255,214,0,.45)}
mark.hlx[data-color="green"] {--hlx-c:rgba(52,211,153,.40)}
mark.hlx[data-color="blue"]  {--hlx-c:rgba(96,165,250,.40)}
mark.hlx[data-color="pink"]  {--hlx-c:rgba(244,114,182,.38)}
mark.hlx[data-color="purple"]{--hlx-c:rgba(167,139,250,.40)}
.hlx-area{
  position:absolute; border-radius:4px; cursor:pointer; pointer-events:auto;
  background:var(--hlx-c,rgba(255,214,0,.30)); outline:2px solid var(--hlx-o,rgba(234,179,8,.75));
}
.hlx-area[data-color="green"] {--hlx-c:rgba(52,211,153,.28);--hlx-o:rgba(16,185,129,.8)}
.hlx-area[data-color="blue"]  {--hlx-c:rgba(96,165,250,.28);--hlx-o:rgba(59,130,246,.8)}
.hlx-area[data-color="pink"]  {--hlx-c:rgba(244,114,182,.26);--hlx-o:rgba(236,72,153,.8)}
.hlx-area[data-color="purple"]{--hlx-c:rgba(167,139,250,.28);--hlx-o:rgba(139,92,246,.8)}
.hlx-wrap{position:relative; display:inline-block; max-width:100%}
.hlx-bar{
  position:absolute; z-index:2147483500; display:flex; gap:6px; align-items:center;
  background:#101a2c; padding:7px 9px; border-radius:12px; direction:rtl;
  box-shadow:0 10px 30px rgba(0,0,0,.35); font:400 12px/1 system-ui,Tahoma,sans-serif;
}
.hlx-bar button{
  width:22px; height:22px; border-radius:50%; border:2px solid rgba(255,255,255,.35);
  cursor:pointer; padding:0; background:#ffd600;
}
.hlx-bar button[data-c="green"] {background:#34d399}
.hlx-bar button[data-c="blue"]  {background:#60a5fa}
.hlx-bar button[data-c="pink"]  {background:#f472b6}
.hlx-bar button[data-c="purple"]{background:#a78bfa}
.hlx-bar .hlx-del{
  width:22px; height:22px; padding:0; border-radius:50%;
  background:#ef4444; border-color:rgba(255,255,255,.4);
  display:flex; align-items:center; justify-content:center;
  margin-right:2px;
}
.hlx-bar .hlx-del:hover{ background:#dc2626; }
.hlx-area-mode img{outline:2px dashed rgba(37,99,235,.6); cursor:crosshair}
.hlx-drag{
  position:absolute; border:2px dashed #2563eb; background:rgba(37,99,235,.15);
  pointer-events:none; border-radius:4px; z-index:2147483400;
}
@media print{ mark.hlx{background:transparent!important} .hlx-bar,.hlx-area{display:none!important} }
</style>
<script>
(function () {
  'use strict';
  var CFG = {$config};

  /* ---------------------------------------------------------------
     localStorage stand-in.
     The lesson runs in a sandboxed frame with an opaque origin, where
     real browser storage throws. Values are seeded from the server and
     written back through the parent, so quiz scores survive a device
     change instead of living only in one browser.
  ----------------------------------------------------------------- */
  var store = Object.assign({}, CFG.state || {});
  var timer = null;

  function push() {
    if (timer) { clearTimeout(timer); }
    timer = setTimeout(function () {
      try {
        parent.postMessage({ source: 'helexa-viewer', type: 'state', contentId: CFG.contentId, state: store }, CFG.origin);
      } catch (e) {}
    }, 400);
  }

  var shim = {
    getItem: function (k) { k = String(k); return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
    setItem: function (k, v) { store[String(k)] = String(v); push(); },
    removeItem: function (k) { delete store[String(k)]; push(); },
    clear: function () { store = {}; push(); },
    key: function (i) { var keys = Object.keys(store); return i < keys.length ? keys[i] : null; }
  };
  Object.defineProperty(shim, 'length', { get: function () { return Object.keys(store).length; } });

  ['localStorage', 'sessionStorage'].forEach(function (name) {
    try {
      Object.defineProperty(window, name, { value: shim, configurable: true });
    } catch (e) { /* browser refuses the override; the lesson's own try/catch takes over */ }
  });

  /* ------------------------------- per-student watermark ------------- */
  function paintWatermark() {
    if (!CFG.watermark || document.getElementById('helexa-watermark')) { return; }
    var layer = document.createElement('div');
    layer.id = 'helexa-watermark';
    layer.setAttribute('aria-hidden', 'true');
    for (var i = 0; i < 28; i++) {
      var tag = document.createElement('span');
      tag.textContent = CFG.watermark;
      layer.appendChild(tag);
    }
    document.body.appendChild(layer);
  }

  /* ------------------------------- print deterrent ------------------- */
  function shield(on) {
    var el = document.getElementById('helexa-print-shield');
    if (!el) {
      el = document.createElement('div');
      el.id = 'helexa-print-shield';
      document.body.appendChild(el);
    }
    el.style.display = on ? 'block' : 'none';
  }

  if (CFG.blockPrint) {
    window.addEventListener('beforeprint', function () { shield(true); });
    window.addEventListener('afterprint', function () { shield(false); });
    if (window.matchMedia) {
      var mq = window.matchMedia('print');
      if (mq.addEventListener) { mq.addEventListener('change', function (e) { shield(e.matches); }); }
    }
    document.addEventListener('keydown', function (e) {
      var k = (e.key || '').toLowerCase();
      if ((e.ctrlKey || e.metaKey) && (k === 'p' || k === 's' || k === 'u')) {
        e.preventDefault();
        try { parent.postMessage({ source: 'helexa-viewer', type: 'blocked', action: k }, CFG.origin); } catch (err) {}
      }
    }, true);
  }

  document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  document.addEventListener('dragstart', function (e) { e.preventDefault(); });

  /* ------------------------------------------------------- copy deterrent
     The copy/cut events are what actually get blocked — they fire for every
     way of copying (Ctrl+C, the right-click menu were it not already
     disabled above, and mobile's "Copy" button in the selection toolbar) in
     one place, so this one listener covers desktop and mobile alike.

     Selection itself is deliberately left alone: highlighting depends on
     the browser's normal text-selection gesture, so nothing here touches
     mousedown, mouseup, or selectionchange. A student can still select a
     sentence to highlight it; they just cannot copy the selected text out. */
  function blockClipboardEvent(e) {
    e.preventDefault();
    if (e.clipboardData) {
      try { e.clipboardData.setData('text/plain', ''); } catch (err) {}
    }
  }
  document.addEventListener('copy', blockClipboardEvent);
  document.addEventListener('cut', blockClipboardEvent);

  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toLowerCase();
    if ((e.ctrlKey || e.metaKey) && (k === 'a' || k === 'c' || k === 'x')) {
      e.preventDefault();
    }
  }, true);


  /* ---------------------------------------------------------- highlights
     Runs inside the lesson frame, because the frame has an opaque origin and
     the parent cannot reach its DOM at all. Everything crosses by postMessage.

     Anchoring is by character offset across the whole document rather than by
     a DOM path: wrapping a selection in <mark> changes the child counts of the
     elements around it, so a path recorded a moment earlier would already be
     wrong. Character offsets survive, because wrapping adds elements without
     adding a single character.
  ----------------------------------------------------------------------- */
  var HL = CFG.highlight || { enabled: false, colors: [], items: [] };
  var areaMode = false;

  function textNodes() {
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        if (!node.nodeValue) { return NodeFilter.FILTER_REJECT; }
        var parent = node.parentNode;
        while (parent && parent !== document.body) {
          var tag = parent.nodeName;
          if (tag === 'SCRIPT' || tag === 'STYLE' || parent.id === 'helexa-watermark') {
            return NodeFilter.FILTER_REJECT;
          }
          parent = parent.parentNode;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var nodes = [], node;
    while ((node = walker.nextNode())) { nodes.push(node); }
    return nodes;
  }

  /** Absolute character offset of a (node, offset) pair. */
  function offsetOf(target, targetOffset) {
    var nodes = textNodes(), total = 0;
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i] === target) { return total + targetOffset; }
      total += nodes[i].nodeValue.length;
    }
    return -1;
  }

  /** The reverse: turn an absolute offset back into a (node, offset) pair. */
  function locate(offset) {
    var nodes = textNodes(), total = 0;
    for (var i = 0; i < nodes.length; i++) {
      var len = nodes[i].nodeValue.length;
      if (offset <= total + len) { return { node: nodes[i], offset: offset - total }; }
      total += len;
    }
    return null;
  }

  function documentText() {
    return textNodes().map(function (n) { return n.nodeValue; }).join('');
  }

  function rangeFromOffsets(start, end) {
    var from = locate(start), to = locate(end);
    if (!from || !to) { return null; }
    var range = document.createRange();
    try {
      range.setStart(from.node, from.offset);
      range.setEnd(to.node, to.offset);
    } catch (e) { return null; }
    return range;
  }

  /** Wraps every text node inside the range, so a selection may span elements. */
  function paint(range, id, color) {
    var nodes = [];
    var walker = document.createTreeWalker(range.commonAncestorContainer, NodeFilter.SHOW_TEXT, null);
    var node;
    while ((node = walker.nextNode())) {
      if (range.intersectsNode(node) && node.nodeValue.length) { nodes.push(node); }
    }
    if (!nodes.length && range.startContainer.nodeType === 3) { nodes.push(range.startContainer); }

    nodes.forEach(function (textNode) {
      var from = textNode === range.startContainer ? range.startOffset : 0;
      var to   = textNode === range.endContainer ? range.endOffset : textNode.nodeValue.length;
      if (to <= from) { return; }

      var piece = textNode;
      if (to < piece.nodeValue.length) { piece.splitText(to); }
      if (from > 0) { piece = piece.splitText(from); }

      var mark = document.createElement('mark');
      mark.className = 'hlx';
      mark.setAttribute('data-id', id);
      mark.setAttribute('data-color', color);
      piece.parentNode.insertBefore(mark, piece);
      mark.appendChild(piece);
    });
  }

  function paintArea(item) {
    var images = document.images;
    var image  = images[item.anchor.imageIndex];
    if (!image) { return false; }

    // The fingerprint stops a region from landing on a different picture if the
    // lesson was re-ordered since the highlight was made.
    if (item.anchor.srcKey && srcKeyOf(image) !== item.anchor.srcKey) { return false; }

    var wrap = image.parentNode;
    if (!wrap || !wrap.classList || !wrap.classList.contains('hlx-wrap')) {
      wrap = document.createElement('span');
      wrap.className = 'hlx-wrap';
      image.parentNode.insertBefore(wrap, image);
      wrap.appendChild(image);
    }

    var box = document.createElement('div');
    box.className = 'hlx-area';
    box.setAttribute('data-id', item.uuid);
    box.setAttribute('data-color', item.color);
    box.style.left   = item.anchor.x + '%';
    box.style.top    = item.anchor.y + '%';
    box.style.width  = item.anchor.w + '%';
    box.style.height = item.anchor.h + '%';
    wrap.appendChild(box);
    return true;
  }

  function srcKeyOf(image) {
    var src = image.getAttribute('src') || '';
    return String(src.length) + ':' + src.slice(-40);
  }

  function restore(items) {
    var missed = 0;

    (items || []).forEach(function (item) {
      try {
        if (item.kind === 'area') {
          if (!paintArea(item)) { missed++; }
          return;
        }

        var range = rangeFromOffsets(item.anchor.start, item.anchor.end);

        // The stored words are the check: if the offsets no longer land on the
        // same text, the file has changed and we search for the quote instead
        // of painting over something unrelated.
        if (range && item.quote && range.toString() !== item.quote) { range = null; }

        if (!range && item.quote) {
          var at = documentText().indexOf(item.quote);
          if (at >= 0) { range = rangeFromOffsets(at, at + item.quote.length); }
        }

        if (!range) { missed++; return; }
        paint(range, item.uuid, item.color);
      } catch (e) { missed++; }
    });

    if (missed > 0) { send('highlight-unanchored', { count: missed }); }
  }

  /* --------------------------------------------------------- the palette */

  var bar = null;

  function hideBar() {
    if (bar && bar.parentNode) { bar.parentNode.removeChild(bar); }
    bar = null;
  }

  function showBar(x, y, onColor, onDelete) {
    hideBar();
    bar = document.createElement('div');
    bar.className = 'hlx-bar';

    (HL.colors || []).forEach(function (color) {
      var button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('data-c', color);
      button.setAttribute('aria-label', color);
      button.addEventListener('mousedown', function (event) {
        event.preventDefault();
        event.stopPropagation();
        onColor(color);
        hideBar();
      });
      bar.appendChild(button);
    });

    if (onDelete) {
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'hlx-del';
      remove.setAttribute('aria-label', 'پاک کردن هایلایت');
      remove.setAttribute('title', 'پاک کردن هایلایت');
      remove.innerHTML = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
      remove.addEventListener('mousedown', function (event) {
        event.preventDefault();
        event.stopPropagation();
        onDelete();
        hideBar();
      });
      bar.appendChild(remove);
    }

    document.body.appendChild(bar);
    var width = bar.offsetWidth || 180;
    bar.style.left = Math.max(6, Math.min(x - width / 2, window.innerWidth - width - 6) + window.scrollX) + 'px';
    bar.style.top  = Math.max(6, y - bar.offsetHeight - 10 + window.scrollY) + 'px';
  }

  function uuidV4() {
    if (crypto && crypto.randomUUID) { return crypto.randomUUID(); }
    var bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    var hex = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    return [hex.slice(0,8), hex.slice(8,12), hex.slice(12,16), hex.slice(16,20), hex.slice(20)].join('-');
  }

  function send(type, payload) {
    try {
      parent.postMessage({ source: 'helexa-viewer', type: type, contentId: CFG.contentId, payload: payload }, CFG.origin);
    } catch (e) {}
  }

  function closestMark(node) {
    var el = node && node.nodeType === 3 ? node.parentElement : node;
    return el && el.closest ? el.closest('mark.hlx') : null;
  }

  /** Shared by both ways into editing an existing highlight: clicking it
      directly, or re-selecting the same span of text it covers. */
  function openEditBar(id, rect) {
    showBar(rect.left + rect.width / 2, rect.top, function (color) {
      document.querySelectorAll('[data-id="' + id + '"]').forEach(function (el) {
        el.setAttribute('data-color', color);
      });
      send('highlight-recolor', { uuid: id, color: color });
    }, function () {
      document.querySelectorAll('mark.hlx[data-id="' + id + '"]').forEach(function (mark) {
        var parent = mark.parentNode;
        while (mark.firstChild) { parent.insertBefore(mark.firstChild, mark); }
        parent.removeChild(mark);
        parent.normalize();
      });
      document.querySelectorAll('.hlx-area[data-id="' + id + '"]').forEach(function (box) {
        box.parentNode.removeChild(box);
      });
      var sel = window.getSelection();
      if (sel && sel.removeAllRanges) { sel.removeAllRanges(); }
      send('highlight-delete', { uuid: id });
    });
  }

  function captureSelection() {
    var selection = window.getSelection();
    if (!selection || selection.isCollapsed || selection.rangeCount === 0) { hideBar(); return; }

    var range = selection.getRangeAt(0);
    var text  = range.toString();
    if (!text.trim() || text.length > 20000) { hideBar(); return; }

    // Selecting text that already sits inside one existing highlight opens
    // that highlight (recolor / remove) instead of stacking a second one on
    // top of it. On a touch screen, re-selecting the highlighted words is
    // often the more natural gesture than tapping the mark precisely.
    var startMark = closestMark(range.startContainer);
    var endMark   = closestMark(range.endContainer);
    if (startMark && startMark === endMark) {
      openEditBar(startMark.getAttribute('data-id'), range.getBoundingClientRect());
      return;
    }

    var start = offsetOf(range.startContainer, range.startOffset);
    var end   = offsetOf(range.endContainer, range.endOffset);
    if (start < 0 || end <= start) { hideBar(); return; }

    var whole  = documentText();
    var rect   = range.getBoundingClientRect();

    showBar(rect.left + rect.width / 2, rect.top, function (color) {
      var id = uuidV4();
      var payload = {
        uuid: id,
        kind: 'text',
        color: color,
        quote: text.slice(0, 500),
        anchor: {
          start: start,
          end: end,
          prefix: whole.slice(Math.max(0, start - 40), start),
          suffix: whole.slice(end, end + 40)
        }
      };

      paint(range, id, color);
      selection.removeAllRanges();
      send('highlight-create', payload);
    }, null);
  }

  /* ------------------------------------------------------- area drawing */

  var drag = null;

  function beginDrag(event) {
    if (!areaMode || event.target.nodeName !== 'IMG') { return; }
    event.preventDefault();

    var image = event.target;
    var rect  = image.getBoundingClientRect();

    drag = {
      image: image,
      rect: rect,
      x: event.clientX,
      y: event.clientY,
      box: document.createElement('div')
    };
    drag.box.className = 'hlx-drag';
    document.body.appendChild(drag.box);
  }

  function moveDrag(event) {
    if (!drag) { return; }
    var left = Math.min(drag.x, event.clientX);
    var top  = Math.min(drag.y, event.clientY);
    drag.box.style.left   = (left + window.scrollX) + 'px';
    drag.box.style.top    = (top + window.scrollY) + 'px';
    drag.box.style.width  = Math.abs(event.clientX - drag.x) + 'px';
    drag.box.style.height = Math.abs(event.clientY - drag.y) + 'px';
  }

  function endDrag(event) {
    if (!drag) { return; }

    var rect = drag.rect;
    var x1 = Math.min(drag.x, event.clientX), x2 = Math.max(drag.x, event.clientX);
    var y1 = Math.min(drag.y, event.clientY), y2 = Math.max(drag.y, event.clientY);

    // Percentages of the image box, so the region survives any resize.
    var payload = {
      uuid: uuidV4(),
      kind: 'area',
      color: HL.colors[0] || 'yellow',
      quote: null,
      anchor: {
        imageIndex: Array.prototype.indexOf.call(document.images, drag.image),
        srcKey: srcKeyOf(drag.image),
        x: ((x1 - rect.left) / rect.width) * 100,
        y: ((y1 - rect.top) / rect.height) * 100,
        w: ((x2 - x1) / rect.width) * 100,
        h: ((y2 - y1) / rect.height) * 100
      }
    };

    if (drag.box.parentNode) { drag.box.parentNode.removeChild(drag.box); }
    var image = drag.image;
    drag = null;

    if (payload.anchor.w < 1 || payload.anchor.h < 1) { return; }

    payload.anchor.x = Math.max(0, Math.min(100, payload.anchor.x));
    payload.anchor.y = Math.max(0, Math.min(100, payload.anchor.y));

    if (paintArea({ uuid: payload.uuid, color: payload.color, anchor: payload.anchor, kind: 'area' })) {
      send('highlight-create', payload);
    }
  }

  function wireHighlights() {
    if (!HL.enabled) { return; }

    restore(HL.items);

    document.addEventListener('mouseup', function (event) {
      if (areaMode || drag) { return; }
      if (event.target && event.target.closest && event.target.closest('.hlx-bar')) { return; }
      window.setTimeout(captureSelection, 10);
    });

    document.addEventListener('click', function (event) {
      var hit = event.target.closest ? event.target.closest('mark.hlx, .hlx-area') : null;
      if (!hit) { hideBar(); return; }
      event.preventDefault();
      openEditBar(hit.getAttribute('data-id'), hit.getBoundingClientRect());
    });

    document.addEventListener('scroll', hideBar, true);
    document.addEventListener('mousedown', beginDrag);
    document.addEventListener('mousemove', moveDrag);
    document.addEventListener('mouseup', endDrag);
  }

  // The parent turns image-region mode on and off.
  window.addEventListener('message', function (event) {
    var data = event.data || {};
    if (data.source !== 'helexa-shell') { return; }
    if (data.type === 'area-mode') {
      areaMode = !!data.on;
      document.body.classList.toggle('hlx-area-mode', areaMode);
      hideBar();
    }
    if (data.type === 'apply-highlights') {
      // Used by the offline reader: the stored copy of the lesson carries the
      // highlights that existed when it was downloaded, so anything made since
      // is painted here instead of being invisible until the next download.
      restore(data.items || []);
    }
    if (data.type === 'scroll-to') {
      var target = document.querySelector('[data-id="' + data.uuid + '"]');
      if (target && target.scrollIntoView) { target.scrollIntoView({ block: 'center' }); }
    }
  });


  /* ------------------------------- lifecycle to the parent ----------- */
  function ping(kind) {
    try {
      parent.postMessage({
        source: 'helexa-viewer', type: kind, contentId: CFG.contentId,
        visible: document.visibilityState === 'visible',
        scroll: Math.round((window.scrollY || 0) / Math.max(1, document.body.scrollHeight - window.innerHeight) * 100)
      }, CFG.origin);
    } catch (e) {}
  }

  function boot() {
    paintWatermark();
    wireHighlights();
    ping('ready');
    // The watermark is re-attached if the page or a user script removes it.
    if (CFG.watermark && window.MutationObserver) {
      new MutationObserver(function () { paintWatermark(); })
        .observe(document.body, { childList: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  document.addEventListener('visibilitychange', function () { ping('visibility'); });
  window.addEventListener('beforeunload', function () { ping('closing'); });
})();
</script>
HTML;
    }

    /**
     * Content-Security-Policy for the stream response.
     * The frame has an opaque origin, so 'self' would match nothing: our own
     * origin has to be listed explicitly for the local font stylesheet.
     */
    public static function contentSecurityPolicy(string $ownOrigin = ''): string
    {
        $allowExternal = Settings::bool('viewer_allow_external_fonts', true);
        $remote        = $allowExternal ? ' https:' : '';
        $own           = $ownOrigin !== '' ? ' ' . $ownOrigin : '';
        $remote       .= $own;

        return implode('; ', [
            "default-src 'none'",
            "script-src 'unsafe-inline' 'unsafe-eval'" . $remote,
            "style-src 'unsafe-inline'" . $remote,
            'img-src data: blob:' . $remote,
            'media-src data: blob:' . $remote,
            'font-src data:' . $remote,
            // No fetch, XHR, WebSocket or beacon: nothing leaves the frame.
            "connect-src 'none'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
            // The lesson may only be framed by our own viewer page.
            "frame-ancestors 'self'",
        ]);
    }
}
