/* پنل دانشجویی – رفتار صفحه دسترسی و جستجو */
(function () {
  'use strict';

  var openBtn   = document.getElementById('accessOpen');
  var closeBtn  = document.getElementById('accessClose');
  var layer     = document.getElementById('accessLayer');
  var search    = document.getElementById('accessSearch');
  var tilesWrap = document.getElementById('tiles');
  var noResult  = document.getElementById('accessNoResult');
  var tiles     = tilesWrap ? Array.prototype.slice.call(tilesWrap.querySelectorAll('.tile')) : [];

  if (!layer || !openBtn) { return; }

  function openAccess() {
    layer.hidden = false;
    openBtn.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    if (search) {
      search.value = '';
      resetFilter();
      // فوکوس فقط روی دسکتاپ تا کیبورد موبایل ناخواسته باز نشود
      if (window.matchMedia && window.matchMedia('(min-width: 900px)').matches) {
        search.focus();
      }
    }
  }

  function closeAccess() {
    layer.hidden = true;
    openBtn.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }

  function resetFilter() {
    tiles.forEach(function (t) { t.hidden = false; });
    if (noResult) { noResult.hidden = true; }
  }

  function normalize(value) {
    return (value || '')
      .replace(/[يى]/g, 'ی')   // ي / ى  ->  ی
      .replace(/[ك]/g, 'ک')         // ك      ->  ک
      .replace(/[‌ً-ْ]/g, '')  // نیم‌فاصله و اعراب
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  function filterTiles() {
    var q = normalize(search.value);
    var visible = 0;

    tiles.forEach(function (tile) {
      var label = tile.getAttribute('title') || '';
      var span  = tile.querySelector('.tile__label');
      var text  = normalize(label + ' ' + (span ? span.textContent : ''));
      var match = q === '' || text.indexOf(q) !== -1;
      tile.hidden = !match;
      if (match) { visible++; }
    });

    if (noResult) { noResult.hidden = visible !== 0; }
  }

  openBtn.addEventListener('click', openAccess);
  if (closeBtn) { closeBtn.addEventListener('click', closeAccess); }
  if (search)   { search.addEventListener('input', filterTiles); }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !layer.hidden) { closeAccess(); }
  });

  // کلیک روی فضای تیره، صفحه دسترسی را می‌بندد
  layer.addEventListener('click', function (e) {
    if (e.target === layer || e.target.classList.contains('access__scroll')) {
      closeAccess();
    }
  });

  // لینک‌های نمایشی؛ از پرش صفحه جلوگیری می‌کند
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href="#"]') : null;
    if (a) { e.preventDefault(); }
  });
}());
