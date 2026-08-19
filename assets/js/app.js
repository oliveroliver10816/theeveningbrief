/* =========================================================================
 * The only client-side JavaScript on this site. Everything here is an
 * ENHANCEMENT: the server already rendered a correct page — right clock,
 * absolute timestamps, a CSS-driven ticker, system colour scheme. Delete
 * this file and nothing breaks.
 *
 *   1. tick the masthead clock in the site's own timezone
 *   2. pause the ticker while the tab is hidden (hover/focus is CSS)
 *   3. rewrite recent timestamps to "12m ago", refreshed each minute
 *   4. light / dark / system toggle, remembered in localStorage
 *
 * No dependencies, no build step, safe with defer.
 * ====================================================================== */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;
  var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  var reduced = !!(mq && mq.matches);

  /* ---------------------------------------------------------------- theme
   * 'system' removes the attribute and lets the prefers-color-scheme block
   * decide. An explicit choice writes data-theme, which site.css defines at
   * higher specificity so it wins in BOTH directions — light on a dark OS
   * included. The <head> already applied any saved choice before first
   * paint, so this only wires the button up.                              */
  var KEY = 'theme';
  var MODES = ['system', 'light', 'dark'];
  var FACE = {
    system: ['◐', 'System', 'Colour theme: following your system setting'],
    light:  ['☀', 'Light',  'Colour theme: light'],
    dark:   ['☾', 'Dark',   'Colour theme: dark']
  };

  function savedTheme() {
    try {
      var v = localStorage.getItem(KEY);
      return v === 'light' || v === 'dark' ? v : 'system';
    } catch (e) {
      return 'system';
    }
  }

  function paintMeta() {
    var meta = doc.querySelector('meta[name="theme-color"]');
    if (!meta || !doc.body || !window.getComputedStyle) { return; }
    // Read the colour the page is actually painted with rather than repeating
    // a palette value here — the stylesheet stays the single source of truth.
    var bg = window.getComputedStyle(doc.body).backgroundColor;
    if (bg && bg !== 'transparent' && bg.indexOf('rgba(0, 0, 0, 0)') !== 0) {
      meta.setAttribute('content', bg);
    }
  }

  function applyTheme(mode, persist) {
    if (mode === 'system') {
      root.removeAttribute('data-theme');
    } else {
      root.setAttribute('data-theme', mode);
    }
    if (persist) {
      try {
        if (mode === 'system') { localStorage.removeItem(KEY); } else { localStorage.setItem(KEY, mode); }
      } catch (e) { /* private mode: the choice simply does not survive the tab */ }
    }
    var face = FACE[mode] || FACE.system;
    for (var i = 0; i < toggles.length; i++) {
      var b = toggles[i];
      var icon = b.querySelector('.tt-icon');
      var text = b.querySelector('.tt-text');
      if (icon) { icon.textContent = face[0]; }
      if (text) { text.textContent = face[1]; }
      b.setAttribute('aria-label', face[2]);
      b.setAttribute('title', face[2]);
    }
    paintMeta();
  }

  var toggles = doc.querySelectorAll('[data-theme-toggle]');
  if (toggles.length) {
    var current = savedTheme();
    applyTheme(current, false);
    for (var t = 0; t < toggles.length; t++) {
      var li = toggles[t].parentNode;
      if (li && li.classList) { li.classList.add('tt-ready'); }
      toggles[t].addEventListener('click', function () {
        current = MODES[(MODES.indexOf(current) + 1) % MODES.length];
        applyTheme(current, true);
      });
    }
  }

  /* ---------------------------------------------------------------- clock
   * The server printed the time; this keeps it honest. Aligned to the second
   * boundary so it never drifts a beat behind, and never scheduled while the
   * tab is hidden.                                                         */
  var clock = doc.getElementById('clock');
  var clockFmt = null;
  var clockTimer = null;

  if (clock && window.Intl && Intl.DateTimeFormat) {
    var opts = { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true };
    var tz = clock.getAttribute('data-tz');
    var loc = clock.getAttribute('data-locale') || undefined;
    if (tz) { opts.timeZone = tz; }
    try {
      clockFmt = new Intl.DateTimeFormat(loc, opts);
    } catch (e) {
      // An unknown timezone or locale must not stop the clock — drop the
      // offending option and fall back to the browser's own.
      delete opts.timeZone;
      try { clockFmt = new Intl.DateTimeFormat(undefined, opts); } catch (e2) { clockFmt = null; }
    }
  }

  function tickClock() {
    if (!clock || !clockFmt) { return; }
    clock.textContent = clockFmt.format(new Date());
  }

  function startClock() {
    if (!clockFmt || clockTimer) { return; }
    tickClock();
    clockTimer = setTimeout(function loop() {
      tickClock();
      clockTimer = setTimeout(loop, 1000 - (Date.now() % 1000));
    }, 1000 - (Date.now() % 1000));
  }

  function stopClock() {
    if (clockTimer) { clearTimeout(clockTimer); clockTimer = null; }
  }

  /* ------------------------------------------------------- relative times
   * <time datetime> already holds a machine-readable instant and the element
   * text is the absolute local time. That absolute text is stashed on first
   * pass so anything older than a day — or a clock that disagrees with ours —
   * can be handed straight back untouched.                                 */
  function ago(seconds) {
    if (seconds < 0) { return null; }
    if (seconds < 60) { return 'just now'; }
    var m = Math.floor(seconds / 60);
    if (m < 60) { return m + 'm ago'; }
    var h = Math.floor(m / 60);
    if (h < 24) { return h + 'h ago'; }
    return null;
  }

  function refreshTimes() {
    var nodes = doc.querySelectorAll('time[datetime]');
    var now = Date.now();
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var abs = el.getAttribute('data-abs');
      if (abs === null) {
        abs = el.textContent;
        el.setAttribute('data-abs', abs);
      }
      var when = Date.parse(el.getAttribute('datetime'));
      if (isNaN(when)) { continue; }
      var label = ago(Math.round((now - when) / 1000));
      el.textContent = label === null ? abs : label;
      if (!el.getAttribute('title')) { el.setAttribute('title', abs); }
    }
  }

  var timesTimer = null;

  function startTimes() {
    if (timesTimer) { return; }
    refreshTimes();
    timesTimer = setInterval(refreshTimes, 60000);
  }

  function stopTimes() {
    if (timesTimer) { clearInterval(timesTimer); timesTimer = null; }
  }

  /* --------------------------------------------------------------- ticker
   * Hover and focus-within already pause it in CSS, and prefers-reduced-
   * motion disables the animation outright — in that case this never touches
   * it. All that is left is the hidden tab, which CSS cannot see.          */
  var track = doc.querySelector('.ticker-track');

  function pauseTicker(paused) {
    if (!track || reduced) { return; }
    track.style.animationPlayState = paused ? 'paused' : '';
  }

  /* ------------------------------------------------------------ lifecycle */
  function onVisible() {
    if (doc.hidden) {
      stopClock();
      stopTimes();
      pauseTicker(true);
    } else {
      startClock();
      startTimes();
      pauseTicker(false);
    }
  }

  doc.addEventListener('visibilitychange', onVisible);
  if (mq) {
    var onMotion = function (e) {
      reduced = e.matches;
      if (reduced && track) { track.style.animationPlayState = ''; }
    };
    if (mq.addEventListener) { mq.addEventListener('change', onMotion); }
    else if (mq.addListener) { mq.addListener(onMotion); }
  }

  onVisible();
}());
