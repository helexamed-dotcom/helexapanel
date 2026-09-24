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
        array $highlights = [],
        array $extras = []
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
            // Handwriting on the lesson and note pins. Both are optional and
            // both are drawn by this frame; the parent does the saving.
            'ink'        => [
                'enabled' => !empty($extras['ink']['enabled']),
                'strokes' => $extras['ink']['strokes'] ?? [],
            ],
            'notes'      => [
                'enabled' => !empty($extras['notes']['enabled']),
                'items'   => $extras['notes']['items'] ?? [],
            ],
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

        // The pen engine is embedded rather than linked: the frame is on an
        // opaque origin, and an offline copy of the lesson must still draw.
        $inkScript = '';
        if (!empty($extras['ink']['enabled']) || !empty($extras['notes']['enabled'])) {
            static $inkSource = null;
            if ($inkSource === null) {
                $file = PUBLIC_PATH . '/assets/js/ink.js';
                $inkSource = is_file($file) ? str_ireplace('</script', '<\/script', (string) file_get_contents($file)) : '';
            }
            $inkScript = $inkSource !== '' ? "<script>\n" . $inkSource . "\n</script>" : '';
        }

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
/* Two colours, not one. The navy tag is invisible on a lesson with a dark
   background, and plenty of the uploaded notes have one — which meant the
   watermark silently did nothing on exactly the pages it was there for.
   Alternating navy and white means at least one of the two always reads,
   whatever the page underneath is, without needing to know anything about
   that page. The white tag carries a faint dark shadow and vice versa, so
   neither disappears on a mid-grey background where both are low contrast. */
#helexa-watermark span{
  transform:rotate(-30deg); font:600 15px/1.4 system-ui,-apple-system,'Segoe UI',Tahoma,sans-serif;
  white-space:nowrap; letter-spacing:.4px;
}
#helexa-watermark span.hlx-wm-dark{
  color:rgba(23,59,103,.075);
  text-shadow:0 0 1px rgba(255,255,255,.05);
}
#helexa-watermark span.hlx-wm-light{
  color:rgba(255,255,255,.085);
  text-shadow:0 0 1px rgba(0,0,0,.05);
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

/* Handwriting layer and note pins. Above the lesson, below the watermark,
   and never in the way of a click unless a tool asks for it. */
.hlx-ink-svg{position:absolute;left:0;top:0;overflow:visible;pointer-events:none;z-index:2147482000}
.hlx-ink-svg .hlx-ink-marker{mix-blend-mode:multiply}
html body.hlx-draw, html body.hlx-draw *{
  -webkit-user-select:none !important; user-select:none !important; -webkit-touch-callout:none !important;
}
body.hlx-draw{cursor:crosshair}
body.hlx-draw.hlx-finger, body.hlx-draw.hlx-finger *{touch-action:none !important}
#helexa-notes{position:absolute;left:0;top:0;width:0;height:0;z-index:2147482400}
.hlx-pin{
  position:absolute;width:36px;height:36px;margin:-18px 0 0 -18px;padding:0;border:0;border-radius:12px;
  display:grid;place-items:center;cursor:grab;touch-action:none;-webkit-user-select:none;user-select:none;
  background:#facc15;color:#422006;box-shadow:0 6px 16px rgba(15,23,42,.28),inset 0 0 0 1.5px rgba(255,255,255,.55);
  transition:transform .15s ease,box-shadow .15s ease;
}
.hlx-pin svg{width:19px;height:19px;pointer-events:none}
.hlx-pin:hover{transform:scale(1.08)}
.hlx-pin:focus-visible{outline:3px solid #2563eb;outline-offset:2px}
.hlx-pin.is-drag{cursor:grabbing;transform:scale(1.18);box-shadow:0 12px 26px rgba(15,23,42,.35)}
.hlx-pin[data-color="green"] {background:#4ade80;color:#052e16}
.hlx-pin[data-color="blue"]  {background:#60a5fa;color:#082f49}
.hlx-pin[data-color="pink"]  {background:#f472b6;color:#500724}
.hlx-pin[data-color="purple"]{background:#a78bfa;color:#2e1065}
.hlx-pin[data-color="gray"]  {background:#cbd5e1;color:#0f172a}
.hlx-pin.is-new{animation:hlx-pop .45s cubic-bezier(.2,1.5,.4,1)}
.hlx-pin.is-flash{animation:hlx-ring 1.2s ease}
@keyframes hlx-pop{from{transform:scale(.2);opacity:0}to{transform:none;opacity:1}}
@keyframes hlx-ring{0%,100%{box-shadow:0 6px 16px rgba(15,23,42,.28)}40%{box-shadow:0 0 0 8px rgba(37,99,235,.35)}}
@media (prefers-reduced-motion: reduce){ .hlx-pin.is-new,.hlx-pin.is-flash{animation:none} }
@media print{ .hlx-ink-svg,#helexa-notes,.hlx-ink-live{display:none!important} }
</style>
{$inkScript}
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
    // Alternating rather than all-dark: see the stylesheet above. Odd tags
    // are light so the two colours interleave across the grid instead of
    // banding into a dark half and a light half.
    for (var i = 0; i < 28; i++) {
      var tag = document.createElement('span');
      tag.className = (i % 2 === 0) ? 'hlx-wm-dark' : 'hlx-wm-light';
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
      if (tag === 'SCRIPT' || tag === 'STYLE' || parent.id === 'helexa-watermark' || parent.id === 'helexa-notes') { return false; }
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
    var inkMode = tool === 'draw' && INK.board;
    send('highlight-state', {
      canUndo: inkMode ? INK.board.canUndo() : undoStack.length > 0,
      canRedo: inkMode ? INK.board.canRedo() : redoStack.length > 0,
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
    if ((tool !== 'pen' && tool !== 'eraser') || !HL.enabled) { return; }

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
    if (!HL.enabled || (which !== 'pen' && which !== 'eraser')) { return false; }

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
    tool = (next === 'pen' || next === 'eraser' || (next === 'draw' && INK.board)) ? next : 'off';
    document.body.classList.toggle('hlx-pen', tool === 'pen');
    document.body.classList.toggle('hlx-eraser', tool === 'eraser');
    document.body.classList.toggle('hlx-draw', tool === 'draw');
    document.body.classList.toggle('hlx-finger', tool === 'draw' && INK.finger);
    if (tool === 'draw') { clearSelection(); }
    cancelCapture();
    reportState();
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

    if (data.type === 'undo') { if (tool === 'draw' && INK.board) { INK.board.undo(); } else { undo(); } return; }
    if (data.type === 'redo') { if (tool === 'draw' && INK.board) { INK.board.redo(); } else { redo(); } return; }

    if (inkMessage(data)) { return; }

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


  /* ---------------------------------------------------------- handwriting
     The student writes straight on the lesson. Strokes live in a vector
     layer that scrolls with the page; the parent saves them. */
  var INK = { board: null, finger: true, saveTimer: null, layer: null };

  function docWidth() { return document.documentElement.clientWidth || window.innerWidth; }

  function sizeLayers() {
    var svg = INK.board ? INK.board.svg : null;
    if (svg) { svg.style.height = '0px'; }
    var h = Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0);
    var w = Math.max(document.documentElement.scrollWidth, docWidth());
    if (svg) {
      svg.style.width = w + 'px';
      svg.style.height = h + 'px';
      INK.board.refresh();
    }
    placePins();
  }

  function wireInk() {
    var cfg = CFG.ink || {};
    if (!cfg.enabled || !window.HlxInk) { return; }

    INK.board = new window.HlxInk.Board({
      input: document,
      host: document.body,
      refWidth: docWidth,
      active: function () { return tool === 'draw'; },
      accept: function (e) {
        return !(e.target && e.target.closest && e.target.closest('.hlx-pin'));
      },
      onChange: function (board) {
        if (INK.saveTimer) { clearTimeout(INK.saveTimer); }
        INK.saveTimer = setTimeout(function () {
          send('ink-save', { strokes: board.data() });
        }, 700);
      },
      onHistory: function () { if (tool === 'draw') { reportState(); } },
      onPen: function () { send('pen-detected', {}); }
    });
    INK.board.load(cfg.strokes || []);
    sizeLayers();

    if (window.ResizeObserver) {
      var ro = new ResizeObserver(function () { sizeLayers(); });
      ro.observe(document.documentElement);
      if (document.body) { ro.observe(document.body); }
    } else {
      window.addEventListener('resize', sizeLayers);
    }
    window.addEventListener('load', sizeLayers);

    // While writing, a tap must not follow a link or press a quiz button.
    document.addEventListener('click', function (e) {
      if (tool !== 'draw') { return; }
      if (e.target && e.target.closest && e.target.closest('.hlx-pin')) { return; }
      e.preventDefault();
      e.stopPropagation();
    }, true);

    window.addEventListener('beforeunload', function () {
      if (INK.saveTimer) { clearTimeout(INK.saveTimer); send('ink-save', { strokes: INK.board.data() }); }
    });
  }

  /* ---------------------------------------------------------- note pins */
  var NOTES = { items: {}, layer: null };
  var PIN_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<path d="M6 3.5h9l4.5 4.5v11a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 19V5A1.5 1.5 0 0 1 6 3.5z"/>' +
    '<path d="M14.5 3.5V8h4.5M8 12.5h8M8 16h5"/></svg>';
  var NOTE_COLORS = ['yellow', 'green', 'blue', 'pink', 'purple', 'gray'];

  function wireNotes() {
    var cfg = CFG.notes || {};
    if (!cfg.enabled) { return; }
    var layer = document.createElement('div');
    layer.id = 'helexa-notes';
    document.body.appendChild(layer);
    NOTES.layer = layer;
    (cfg.items || []).forEach(function (item) { addPin(item, false); });
    if (!INK.board) {
      window.addEventListener('resize', placePins);
      window.addEventListener('load', placePins);
    }
  }

  function pinScale(item) { return docWidth() / (item.w || docWidth()); }

  function placePin(item) {
    if (!item.el) { return; }
    var k = pinScale(item);
    var maxX = docWidth() - 20;
    item.el.style.left = Math.max(20, Math.min(maxX, item.x * k)) + 'px';
    item.el.style.top = Math.max(20, item.y * k) + 'px';
  }

  function placePins() {
    Object.keys(NOTES.items).forEach(function (id) { placePin(NOTES.items[id]); });
  }

  function labelFor(item) {
    return item.title ? ('یادداشت: ' + item.title) : 'یادداشت';
  }

  function addPin(raw, isNew) {
    if (!NOTES.layer || !raw || !isUuid(raw.uuid) || NOTES.items[raw.uuid]) { return null; }
    var item = {
      uuid: raw.uuid,
      x: +raw.x || 40, y: +raw.y || 40, w: +raw.w || docWidth(),
      color: NOTE_COLORS.indexOf(raw.color) !== -1 ? raw.color : 'yellow',
      title: String(raw.title || '').slice(0, 120)
    };
    var pin = document.createElement('button');
    pin.type = 'button';
    pin.className = 'hlx-pin' + (isNew ? ' is-new' : '');
    pin.setAttribute('data-color', item.color);
    pin.setAttribute('aria-label', labelFor(item));
    pin.title = labelFor(item);
    pin.innerHTML = PIN_ICON;
    item.el = pin;
    NOTES.items[item.uuid] = item;
    NOTES.layer.appendChild(pin);
    placePin(item);

    var drag = null, suppress = false;
    pin.addEventListener('pointerdown', function (e) {
      if (e.pointerType === 'mouse' && e.button !== 0) { return; }
      e.preventDefault();
      e.stopPropagation();
      var k = pinScale(item);
      drag = { id: e.pointerId, sx: e.clientX, sy: e.clientY, ox: item.x * k, oy: item.y * k, moved: false };
      try { pin.setPointerCapture(e.pointerId); } catch (x) {}
    });
    pin.addEventListener('pointermove', function (e) {
      if (!drag || e.pointerId !== drag.id) { return; }
      var dx = e.clientX - drag.sx, dy = e.clientY - drag.sy;
      if (!drag.moved && dx * dx + dy * dy < 36) { return; }
      drag.moved = true;
      pin.classList.add('is-drag');
      pin.style.left = Math.max(20, Math.min(docWidth() - 20, drag.ox + dx)) + 'px';
      pin.style.top = Math.max(20, drag.oy + dy) + 'px';
      // Scroll along when the pin is carried to an edge of the screen.
      if (e.clientY > window.innerHeight - 40) { window.scrollBy(0, 12); drag.oy += 12; }
      else if (e.clientY < 40) { window.scrollBy(0, -12); drag.oy -= 12; }
    });
    function finish(e) {
      if (!drag || e.pointerId !== drag.id) { return; }
      var moved = drag.moved;
      drag = null;
      pin.classList.remove('is-drag');
      if (!moved) { return; }
      suppress = true;
      setTimeout(function () { suppress = false; }, 350);
      item.w = docWidth();
      item.x = parseFloat(pin.style.left) || item.x;
      item.y = parseFloat(pin.style.top) || item.y;
      send('note-move', { uuid: item.uuid, x: Math.round(item.x), y: Math.round(item.y), w: Math.round(item.w) });
    }
    pin.addEventListener('pointerup', finish);
    pin.addEventListener('pointercancel', finish);
    pin.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (suppress) { return; }
      send('note-open', { uuid: item.uuid });
    });
    return item;
  }

  function newPin(data) {
    if (!NOTES.layer || !isUuid(data.uuid)) { return; }
    var origin = NOTES.layer.getBoundingClientRect();
    var item = addPin({
      uuid: data.uuid,
      x: window.innerWidth / 2 - origin.left,
      y: window.innerHeight / 2 - origin.top,
      w: docWidth(),
      color: data.color
    }, true);
    if (item) {
      send('note-created', { uuid: item.uuid, x: Math.round(item.x), y: Math.round(item.y), w: Math.round(item.w), color: item.color });
    }
  }

  function inkMessage(data) {
    switch (data.type) {
      case 'ink-options':
        if (INK.board) {
          INK.board.setOptions(data.options || {});
          INK.finger = !!(data.options && data.options.finger);
          document.body.classList.toggle('hlx-finger', tool === 'draw' && INK.finger);
        }
        return true;
      case 'ink-clear':
        if (INK.board) { INK.board.clear(); }
        return true;
      case 'ink-load':
        if (INK.board) { INK.board.load(data.strokes || []); reportState(); }
        return true;
      case 'note-add':
        newPin(data);
        return true;
      case 'note-update':
        var item = NOTES.items[data.uuid];
        if (item && item.el) {
          if (NOTE_COLORS.indexOf(data.color) !== -1) { item.color = data.color; item.el.setAttribute('data-color', data.color); }
          if (typeof data.title === 'string') {
            item.title = data.title.slice(0, 120);
            item.el.title = labelFor(item);
            item.el.setAttribute('aria-label', labelFor(item));
          }
        }
        return true;
      case 'note-remove':
        var gone = NOTES.items[data.uuid];
        if (gone && gone.el && gone.el.parentNode) { gone.el.parentNode.removeChild(gone.el); }
        delete NOTES.items[data.uuid];
        return true;
      case 'note-focus':
        var target = NOTES.items[data.uuid];
        if (target && target.el) {
          target.el.scrollIntoView({ block: 'center' });
          target.el.classList.remove('is-flash');
          void target.el.offsetWidth;
          target.el.classList.add('is-flash');
        }
        return true;
    }
    return false;
  }
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
    wireInk();
    wireNotes();
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
