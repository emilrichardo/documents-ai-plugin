/* AI Documents — documentation landing behaviour.
   No dependencies, no build step: the page is a folder you can drop on any
   static host. */
(function () {
  'use strict';

  var doc = document;

  /* ── Theme: explicit choice wins, otherwise follow the system ─────────── */
  var stored = null;
  try { stored = localStorage.getItem('aidoc-theme'); } catch (e) {}
  if (stored) doc.documentElement.setAttribute('data-theme', stored);
  else if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) {
    doc.documentElement.setAttribute('data-theme', 'dark');
  }

  var themeBtn = doc.querySelector('.aidoc-theme');
  if (themeBtn) themeBtn.addEventListener('click', function () {
    var next = doc.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    doc.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('aidoc-theme', next); } catch (e) {}
  });

  /* ── Copy buttons on every code block ─────────────────────────────────── */
  doc.querySelectorAll('.aidoc-copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var code = btn.parentNode.querySelector('code');
      if (!code) return;
      var done = function () {
        var was = btn.textContent;
        btn.textContent = 'Copied';
        btn.classList.add('is-done');
        setTimeout(function () { btn.textContent = was; btn.classList.remove('is-done'); }, 1600);
      };
      if (navigator.clipboard) navigator.clipboard.writeText(code.textContent).then(done, function () {});
      else {
        var ta = doc.createElement('textarea');
        ta.value = code.textContent;
        doc.body.appendChild(ta); ta.select();
        try { doc.execCommand('copy'); done(); } catch (e) {}
        doc.body.removeChild(ta);
      }
    });
  });

  /* ── Sidebar: highlight the section actually on screen ────────────────── */
  var links    = [].slice.call(doc.querySelectorAll('.aidoc-nav a'));
  var byHash   = {};
  links.forEach(function (a) { byHash[a.getAttribute('href')] = a; });
  var sections = [].slice.call(doc.querySelectorAll('.aidoc-section, .aidoc-section h3[id]'));

  if (sections.length && 'IntersectionObserver' in window) {
    var visible = new Set();
    var paint = function () {
      var first = sections.filter(function (s) { return visible.has(s.id); })[0];
      links.forEach(function (a) { a.classList.remove('is-current'); });
      if (first && byHash['#' + first.id]) byHash['#' + first.id].classList.add('is-current');
    };
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) visible.add(e.target.id); else visible.delete(e.target.id);
      });
      paint();
    }, { rootMargin: '-10% 0px -70% 0px', threshold: 0 });
    sections.forEach(function (s) { if (s.id) io.observe(s); });
  }

  /* ── Sidebar filter ───────────────────────────────────────────────────── */
  var filter = doc.querySelector('.aidoc-filter');
  if (filter) filter.addEventListener('input', function () {
    var q = filter.value.trim().toLowerCase();
    doc.querySelectorAll('.aidoc-nav > li').forEach(function (li) {
      li.classList.toggle('is-hidden', q !== '' && li.textContent.toLowerCase().indexOf(q) === -1);
    });
  });

  /* ── Screenshots: click to enlarge; flag the ones not captured yet ────── */
  var box   = doc.querySelector('.aidoc-lightbox');
  var boxImg = box && box.querySelector('img');
  var close = function () { if (box) { box.hidden = true; boxImg.src = ''; } };

  doc.querySelectorAll('.aidoc-shot img').forEach(function (img) {
    img.addEventListener('error', function () { img.closest('.aidoc-shot').classList.add('is-missing'); });
    img.addEventListener('click', function () {
      if (!box) return;
      boxImg.src = img.currentSrc || img.src;
      boxImg.alt = img.alt;
      box.hidden = false;
    });
  });
  if (box) {
    box.addEventListener('click', function (e) { if (e.target !== boxImg) close(); });
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  }

  /* ── Back to top ──────────────────────────────────────────────────────── */
  var top = doc.querySelector('.aidoc-top');
  if (top) {
    top.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    var toggle = function () { top.hidden = window.scrollY < 600; };
    window.addEventListener('scroll', toggle, { passive: true });
    toggle();
  }
})();
