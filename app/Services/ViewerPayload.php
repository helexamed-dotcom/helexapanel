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
/* Lessons are authored for a light page. One that declares no background of
   its own would otherwise follow the device's dark preference and render its
   own colours against a canvas they were never chosen for. Declared here, at
   the top of the head, so a lesson that does set its own still wins. */
html { color-scheme: light; }

/* Selecting text is the whole highlight gesture, so it is forced on over
   anything the lesson's own stylesheet may declare. Copying is stopped by
   the copy/cut listeners instead, which is the part that actually matters
   and which works on touch screens too. */
html, body {
  -webkit-user-select: text !important; user-select: text !important;
}
#helexa-watermark{
  position:fixed; inset:0; z-index:2147483000; pointer-events:none;
  display:flex; flex-wrap:wrap; align-content:center; justify-content:center;
  gap:120px 60px; overflow:hidden;
  -webkit-user-select:none !important; user-select:none !important;
}
#helexa-watermark span{
  transform:rotate(-30deg); font:600 15px/1.4 system-ui,-apple-system,'Segoe UI',Tahoma,sans-serif;
  color:rgba(23,59,103,.075); white-space:nowrap; letter-spacing:.4px;
}
#helexa-print-shield{
  position:fixed; inset:0; background:#fff; z-index:2147483600; display:none;
}
mark.hlx{
  background:var(--hlx-c,rgba(255,214,0,.42)); color:inherit; padding:0; border-radius:3px;
  box-decoration-break:clone; -webkit-box-decoration-break:clone;
  transition:box-shadow .15s ease;
}
mark.hlx[data-color="yellow"]{--hlx-c:rgba(255,214,0,.45)}
mark.hlx[data-color="green"] {--hlx-c:rgba(52,211,153,.40)}
mark.hlx[data-color="blue"]  {--hlx-c:rgba(96,165,250,.40)}
mark.hlx[data-color="pink"]  {--hlx-c:rgba(244,114,182,.38)}
mark.hlx[data-color="purple"]{--hlx-c:rgba(167,139,250,.40)}

/* Tool feedback. The eraser makes every mark look tappable, which is what
   turns "remove a highlight" into a single tap on a phone. */
body.hlx-pen    { cursor:text; }
body.hlx-eraser mark.hlx{
  cursor:pointer; box-shadow:0 0 0 2px rgba(239,68,68,.55);
}
body.hlx-eraser mark.hlx:active{ background:rgba(239,68,68,.22); }

mark.hlx.is-flash{ animation:hlx-flash 1.1s ease; }
@keyframes hlx-flash{
  0%,100%{ box-shadow:0 0 0 0 rgba(37,99,235,0); }
  35%    { box-shadow:0 0 0 3px rgba(37,99,235,.55); }
}
@media (prefers-reduced-motion: reduce){ mark.hlx.is-flash{ animation:none; } }
@media print{ mark.hlx{background:transparent!important} }
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

  function coarsePointer() {
    return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
  }

  /* On a touch screen the long-press that opens the context menu is also the
     only way to start a text selection, and preventing it prevents selection
     with it — which is exactly why highlights could be made on a phone but
     never removed. Blocking it stays for mouse input, where right-click is
     not a selection gesture. */
  document.addEventListener('contextmenu', function (e) {
    if (coarsePointer()) { return; }
    e.preventDefault();
  });
  document.addEventListener('dragstart', function (e) { e.preventDefault(); });

  /* ------------------------------------------------------- copy deterrent
     The copy/cut events are what actually get blocked — they fire for every
     way of copying (Ctrl+C, the right-click menu, and mobile's "Copy" button
     in the selection toolbar) in one place, so this one listener covers
     desktop and mobile alike, without touching the selection gesture. */
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
     the parent cannot reach its DOM at all. Everything crosses by postMessage:
     the toolbar in the parent sets the tool, this side does the DOM work and
     reports back what changed.

     Anchoring is by character offset across the whole document rather than by
     a DOM path: wrapping a selection in <mark> changes the child counts of the
     elements around it, so a path recorded a moment earlier would already be
     wrong. Character offsets survive, because wrapping adds elements without
     adding a single character.
  ----------------------------------------------------------------------- */
  var HL       = CFG.highlight || { enabled: false, colors: [], items: [] };
  var COLORS   = HL.colors && HL.colors.length ? HL.colors : ['yellow'];
  var tool     = 'off';               // 'off' | 'pen' | 'eraser'
  var penColor = COLORS[0];
  var items    = {};                  // uuid -> stored highlight
  var undoStack = [];
  var redoStack = [];
  var HISTORY_LIMIT = 100;

  var UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  function isUuid(value) { return typeof value === 'string' && UUID_RE.test(value); }

  /* The text-node list is rebuilt on every structural change and cached in
     between, because every offset lookup walks it. */
  var nodeCache = null;

  /**
   * Whether a text node counts towards the document's character offsets.
   *
   * Every lookup has to agree on this, not just the walker: a selection
   * boundary reported on <body> is resolved by scanning children, and if that
   * scan could return a node the offset map does not contain — the watermark
   * is full of them — the lookup would come back as "not found" and the whole
   * selection would be discarded.
   */
  function isEligible(node) {
    if (!node || node.nodeType !== 3 || !node.nodeValue || !node.nodeValue.length) { return false; }

    var parent = node.parentNode;
    while (parent && parent !== document.body) {
      var tag = parent.nodeName;
      if (tag === 'SCRIPT' || tag === 'STYLE' || parent.id === 'helexa-watermark') { return false; }
      parent = parent.parentNode;
    }
    return parent === document.body;
  }

  function textNodes() {
    if (nodeCache) { return nodeCache; }
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        return isEligible(node) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      }
    });
    var nodes = [], node;
    while ((node = walker.nextNode())) { nodes.push(node); }
    nodeCache = nodes;
    return nodes;
  }

  function invalidate() { nodeCache = null; }

  function documentText() {
    return textNodes().map(function (n) { return n.nodeValue; }).join('');
  }

  /** Absolute character offset of a (text node, offset) pair. */
  function offsetOf(target, targetOffset) {
    var nodes = textNodes(), total = 0;
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i] === target) { return total + targetOffset; }
      total += nodes[i].nodeValue.length;
    }
    return -1;
  }

  function firstTextNode(root) {
    if (root.nodeType === 3) { return isEligible(root) ? root : null; }
    if (root.nodeType !== 1) { return null; }
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null), node;
    while ((node = walker.nextNode())) { if (isEligible(node)) { return node; } }
    return null;
  }

  function lastTextNode(root) {
    if (root.nodeType === 3) { return isEligible(root) ? root : null; }
    if (root.nodeType !== 1) { return null; }
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null), node, last = null;
    while ((node = walker.nextNode())) { if (isEligible(node)) { last = node; } }
    return last;
  }

  /**
   * A selection boundary is not always inside a text node: triple-clicking a
   * paragraph, or "select all", reports the boundary on the element and an
   * index into its children. Both forms are resolved to a real text position
   * here, otherwise those selections could never be anchored.
   */
  function resolvePoint(container, offset, preferEnd) {
    if (container.nodeType === 3) { return { node: container, offset: offset }; }
    if (container.nodeType !== 1) { return null; }

    var kids = container.childNodes, i, found;

    if (!preferEnd) {
      for (i = offset; i < kids.length; i++) {
        found = firstTextNode(kids[i]);
        if (found) { return { node: found, offset: 0 }; }
      }
    }
    for (i = Math.min(offset, kids.length) - 1; i >= 0; i--) {
      found = lastTextNode(kids[i]);
      if (found) { return { node: found, offset: found.nodeValue.length }; }
    }
    found = preferEnd ? lastTextNode(container) : firstTextNode(container);
    return found ? { node: found, offset: preferEnd ? found.nodeValue.length : 0 } : null;
  }

  function absoluteOffset(container, offset, preferEnd) {
    var point = resolvePoint(container, offset, preferEnd);
    return point ? offsetOf(point.node, point.offset) : -1;
  }

  /**
   * Wraps the document text between two absolute offsets in <mark> elements,
   * one per text node the span crosses.
   *
   * Working from offsets rather than the live Range is deliberate: what gets
   * painted is then exactly what gets stored, so a highlight always reappears
   * over the same words after a reload.
   *
   * @return {{matched:boolean, painted:boolean}}
   */
  function paintOffsets(start, end, id, color) {
    var nodes = textNodes(), total = 0, slices = [], i;

    for (i = 0; i < nodes.length && total < end; i++) {
      var node = nodes[i];
      var len  = node.nodeValue.length;
      var from = total;
      total += len;

      if (total <= start) { continue; }
      slices.push({
        node: node,
        from: Math.max(0, start - from),
        to:   Math.min(len, end - from)
      });
    }

    var painted = false;
    slices.forEach(function (slice) {
      if (slice.to <= slice.from) { return; }
      // Never nest one mark inside another: a re-painted range that overlaps
      // an existing highlight would otherwise double its tint.
      if (slice.node.parentNode && slice.node.parentNode.closest &&
          slice.node.parentNode.closest('mark.hlx')) { return; }

      var piece = slice.node;
      if (slice.to < piece.nodeValue.length) { piece.splitText(slice.to); }
      if (slice.from > 0) { piece = piece.splitText(slice.from); }

      var mark = document.createElement('mark');
      mark.className = 'hlx';
      mark.setAttribute('data-id', id);
      mark.setAttribute('data-color', color);
      piece.parentNode.insertBefore(mark, piece);
      mark.appendChild(piece);
      painted = true;
    });

    if (painted) { invalidate(); }
    return { matched: slices.length > 0, painted: painted };
  }

  function marksFor(id) {
    if (!isUuid(id)) { return []; }
    return Array.prototype.slice.call(document.querySelectorAll('mark.hlx[data-id="' + id + '"]'));
  }

  function unpaint(id) {
    var found = marksFor(id);
    found.forEach(function (mark) {
      var parent = mark.parentNode;
      if (!parent) { return; }
      while (mark.firstChild) { parent.insertBefore(mark.firstChild, mark); }
      parent.removeChild(mark);
      parent.normalize();
    });
    if (found.length) { invalidate(); }
    return found.length > 0;
  }

  function recolorMarks(id, color) {
    marksFor(id).forEach(function (mark) { mark.setAttribute('data-color', color); });
  }

  function restore(list) {
    var missed = 0;
    var whole  = documentText();

    (list || []).forEach(function (item) {
      // Image regions were removed from the product; any row left over from
      // that era is skipped rather than reported as a lost highlight.
      if (!item || item.kind === 'area' || !isUuid(item.uuid)) { return; }
      if (items[item.uuid]) { return; }

      try {
        var anchor = item.anchor || {};
        var start  = typeof anchor.start === 'number' ? anchor.start : -1;
        var end    = typeof anchor.end === 'number' ? anchor.end : -1;
        var quote  = item.quote || '';
        var result = { matched: false, painted: false };

        // The stored words are the check: if the offsets no longer land on the
        // same text, the file has changed and we search for the quote instead
        // of painting over something unrelated.
        var offsetsAgree = start >= 0 && end > start &&
          (quote === '' || whole.slice(start, end) === quote);

        if (offsetsAgree) { result = paintOffsets(start, end, item.uuid, item.color); }

        if (!result.matched && quote !== '') {
          var at = whole.indexOf(quote);
          if (at >= 0) {
            result = paintOffsets(at, at + quote.length, item.uuid, item.color);
            // The lesson moved under this highlight. Remember where it really
            // landed, so erasing and undo work on its actual position rather
            // than the offsets it was recorded at.
            if (result.matched) {
              start = at;
              end   = at + quote.length;
            }
          }
        }

        if (!result.matched) { missed++; return; }

        item.anchor = Object.assign({}, anchor, { start: start, end: end });
        items[item.uuid] = item;
      } catch (e) { missed++; }
    });

    if (missed > 0) { send('highlight-unanchored', { count: missed }); }
  }

  /* ------------------------------------------------------------- history */

  function reportState() {
    send('highlight-state', {
      canUndo: undoStack.length > 0,
      canRedo: redoStack.length > 0,
      total:   Object.keys(items).length
    });
  }

  function record(action) {
    undoStack.push(action);
    if (undoStack.length > HISTORY_LIMIT) { undoStack.shift(); }
    redoStack.length = 0;
    reportState();
  }

  function reAdd(item) {
    var anchor = item.anchor || {};
    var result = paintOffsets(anchor.start, anchor.end, item.uuid, item.color);
    if (!result.matched && item.quote) {
      var at = documentText().indexOf(item.quote);
      if (at >= 0) { result = paintOffsets(at, at + item.quote.length, item.uuid, item.color); }
    }
    if (!result.matched) { return false; }
    items[item.uuid] = item;
    send('highlight-create', item);
    return true;
  }

  function drop(uuid) {
    unpaint(uuid);
    delete items[uuid];
    send('highlight-delete', { uuid: uuid });
  }

  function applyColor(uuid, color) {
    recolorMarks(uuid, color);
    if (items[uuid]) { items[uuid].color = color; }
    send('highlight-recolor', { uuid: uuid, color: color });
  }

  function undo() {
    var action = undoStack.pop();
    if (!action) { return; }

    if (action.op === 'create')      { drop(action.item.uuid); }
    else if (action.op === 'delete') { reAdd(action.item); }
    else if (action.op === 'recolor'){ applyColor(action.uuid, action.from); }

    redoStack.push(action);
    reportState();
  }

  function redo() {
    var action = redoStack.pop();
    if (!action) { return; }

    if (action.op === 'create')      { reAdd(action.item); }
    else if (action.op === 'delete') { drop(action.item.uuid); }
    else if (action.op === 'recolor'){ applyColor(action.uuid, action.to); }

    undoStack.push(action);
    reportState();
  }

  /* --------------------------------------------------------- the actions */

  function uuidV4() {
    if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
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

  function createFromOffsets(start, end, color) {
    var whole = documentText();
    var quote = whole.slice(start, end);
    if (!quote.trim()) { return; }

    var item = {
      uuid:  uuidV4(),
      kind:  'text',
      color: color,
      quote: quote.slice(0, 500),
      anchor: {
        start:  start,
        end:    end,
        prefix: whole.slice(Math.max(0, start - 40), start),
        suffix: whole.slice(end, end + 40)
      }
    };

    var result = paintOffsets(start, end, item.uuid, item.color);
    if (!result.painted) { return; }

    items[item.uuid] = item;
    send('highlight-create', item);
    record({ op: 'create', item: item });
  }

  function deleteHighlight(uuid) {
    var item = items[uuid];
    if (!item) { return; }
    drop(uuid);
    record({ op: 'delete', item: item });
  }

  /**
   * Every highlight the span really overlaps, read from the document rather
   * than from the stored offsets, so a swipe of the eraser clears exactly the
   * marks under it. One walk covers them all.
   */
  function marksBetween(start, end) {
    var nodes = textNodes(), total = 0, ids = [];

    for (var i = 0; i < nodes.length && total < end; i++) {
      var len  = nodes[i].nodeValue.length;
      var from = total;
      total += len;
      if (total <= start) { continue; }

      var parent = nodes[i].parentNode;
      var mark   = parent && parent.closest ? parent.closest('mark.hlx') : null;
      if (!mark) { continue; }

      var id = mark.getAttribute('data-id');
      if (id && ids.indexOf(id) === -1) { ids.push(id); }
    }

    return ids;
  }

  function eraseWithin(start, end) {
    var hit = marksBetween(start, end);
    hit.forEach(deleteHighlight);
    return hit.length;
  }

  /* ---------------------------------------------------- selection capture

     Two ways to mark a passage, because a mouse and a finger want different
     things.

     With a mouse, select-and-release is one fluid gesture, so releasing the
     button applies the tool straight away.

     With a finger it is not. A long-press selects a single word and the
     student then drags the handles to reach the end of the sentence — so
     applying on touch-end would mark that first word and clear the handles
     before they had started. Touch therefore never auto-applies. The
     selection is remembered as it is adjusted, and the toolbar button is
     what commits it, which is the order the gesture actually happens in.

     The remembered selection is kept as absolute offsets rather than a live
     Range, so it survives the selection being collapsed by tapping a button
     outside the frame. */

  var lastSelection = null;
  var pending       = null;

  function cancelCapture() {
    if (pending) { clearTimeout(pending); pending = null; }
  }

  function scheduleCapture(delay) {
    cancelCapture();
    pending = setTimeout(function () { pending = null; capture(); }, delay);
  }

  /** Reads the live selection into offsets, or null when there is none. */
  function readSelection() {
    var selection = window.getSelection();
    if (!selection || selection.isCollapsed || selection.rangeCount === 0) { return null; }

    var range = selection.getRangeAt(0);
    var text  = range.toString();
    if (!text.trim() || text.length > 20000) { return null; }

    var start = absoluteOffset(range.startContainer, range.startOffset, false);
    var end   = absoluteOffset(range.endContainer, range.endOffset, true);
    if (start < 0 || end <= start) { return null; }

    return { start: start, end: end, text: text };
  }

  function clearSelection() {
    var selection = window.getSelection();
    if (selection && selection.removeAllRanges) { selection.removeAllRanges(); }
    lastSelection = null;
    reportSelection();
  }

  /** Applies a tool to a range of offsets. */
  function applyTo(which, range) {
    if (!range) { return false; }

    if (which === 'eraser') {
      eraseWithin(range.start, range.end);
    } else {
      createFromOffsets(range.start, range.end, penColor);
    }
    return true;
  }

  /** The mouse path: the tool is already armed, so releasing applies it. */
  function capture() {
    if (tool === 'off' || !HL.enabled) { return; }

    if (applyTo(tool, readSelection())) {
      // Collapsing clears the handles, so the next gesture starts clean
      // instead of re-triggering on the same words.
      clearSelection();
    }
  }

  /**
     Commits whatever is selected now, or was selected a moment ago before
     the student reached for the toolbar. Returns whether anything was done,
     so the parent can fall back to simply arming the tool.
   */
  function applySelection(which) {
    if (!HL.enabled) { return false; }

    var range = readSelection() || lastSelection;
    if (!range) { return false; }

    cancelCapture();
    var done = applyTo(which, range);
    clearSelection();
    return done;
  }

  /* The toolbar needs to know whether there is something to commit, so the
     pen can offer itself as "highlight this" rather than a mode switch. */
  var selectionTimer = null;

  function reportSelection() {
    try {
      parent.postMessage({
        source: 'helexa-viewer',
        type: 'selection',
        has: !!lastSelection,
        length: lastSelection ? lastSelection.text.length : 0
      }, CFG.origin);
    } catch (e) {}
  }

  function rememberSelection() {
    var range = readSelection();
    // Only a real selection updates the memory. A collapse is usually the
    // student tapping a toolbar button, and forgetting at that moment would
    // throw away the very thing they are about to act on.
    if (range) {
      lastSelection = range;
      reportSelection();
    }
  }

  function wireHighlights() {
    if (!HL.enabled) { return; }

    restore(HL.items);
    reportState();

    // Mouse: select-and-release is one gesture, so release commits it.
    // Guarded to a fine pointer so a tablet with a stylus does not inherit
    // the behaviour that makes touch selection impossible.
    document.addEventListener('mouseup', function (event) {
      if (coarsePointer()) { return; }
      if (event.target && event.target.closest && event.target.closest('mark.hlx') && tool === 'eraser') { return; }
      scheduleCapture(10);
    });

    // Touch: nothing is committed by the gesture itself. The selection is
    // only remembered, so the handles can be dragged for as long as it takes.
    document.addEventListener('touchstart', cancelCapture, { passive: true });
    document.addEventListener('touchcancel', cancelCapture, { passive: true });

    document.addEventListener('selectionchange', function () {
      if (selectionTimer) { clearTimeout(selectionTimer); }
      selectionTimer = setTimeout(rememberSelection, 120);
    });

    // A single tap on a highlight erases it — the gesture that was impossible
    // before, because it needed the exact same text to be re-selected first.
    document.addEventListener('click', function (event) {
      if (tool !== 'eraser') { return; }
      var mark = event.target && event.target.closest ? event.target.closest('mark.hlx') : null;
      if (!mark) { return; }
      event.preventDefault();
      event.stopPropagation();
      cancelCapture();
      deleteHighlight(mark.getAttribute('data-id'));
    }, true);

    document.addEventListener('keydown', function (event) {
      var target = event.target;
      if (target && (target.nodeName === 'INPUT' || target.nodeName === 'TEXTAREA' || target.isContentEditable)) {
        return;
      }
      if (!(event.ctrlKey || event.metaKey)) { return; }

      var key = (event.key || '').toLowerCase();
      if (key === 'z') {
        event.preventDefault();
        if (event.shiftKey) { redo(); } else { undo(); }
      } else if (key === 'y') {
        event.preventDefault();
        redo();
      }
    });
  }

  function setTool(next) {
    tool = (next === 'pen' || next === 'eraser') ? next : 'off';
    document.body.classList.toggle('hlx-pen', tool === 'pen');
    document.body.classList.toggle('hlx-eraser', tool === 'eraser');
    cancelCapture();
  }

  /* The parent's toolbar drives the engine from here. */
  window.addEventListener('message', function (event) {
    var data = event.data || {};
    if (data.source !== 'helexa-shell') { return; }

    if (data.type === 'tool') { setTool(data.tool); return; }

    // "Apply to what is selected." Sent when the student selected a passage
    // first and then reached for the toolbar, which is the natural order on
    // a touch screen. Reports back so the parent knows whether to fall back
    // to arming the tool instead.
    if (data.type === 'apply') {
      var applied = applySelection(data.tool);
      try {
        parent.postMessage({
          source: 'helexa-viewer', type: 'applied',
          tool: data.tool, applied: applied
        }, CFG.origin);
      } catch (e) {}
      return;
    }

    if (data.type === 'color') {
      if (COLORS.indexOf(data.color) !== -1) { penColor = data.color; }
      return;
    }

    if (data.type === 'undo') { undo(); return; }
    if (data.type === 'redo') { redo(); return; }

    // Deleting from the list in the parent, for a highlight whose text has
    // scrolled away or can no longer be found in the page.
    if (data.type === 'erase') {
      if (isUuid(data.uuid)) { deleteHighlight(data.uuid); }
      return;
    }

    if (data.type === 'apply-highlights') {
      // Used by the offline reader: the stored copy of the lesson carries the
      // highlights that existed when it was downloaded, so anything made since
      // is painted here instead of being invisible until the next download.
      restore(data.items || []);
      reportState();
      return;
    }

    if (data.type === 'scroll-to') {
      var target = marksFor(data.uuid)[0];
      if (!target) { return; }
      if (target.scrollIntoView) { target.scrollIntoView({ block: 'center' }); }
      marksFor(data.uuid).forEach(function (mark) {
        mark.classList.remove('is-flash');
        void mark.offsetWidth;
        mark.classList.add('is-flash');
      });
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
