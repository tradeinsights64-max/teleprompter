/* Trade Insight — risk warning bar fixed at the top of every page (2026-11-20).
 * Lives outside the React root; pushes fixed/sticky headers and sidebars down
 * by its own height so nothing is covered. Follows the EN/አማ switch. */
(function () {
  'use strict';
  var T = {
    en: '⚠️ <b>Trading carries high risk.</b> You can lose some or all of your money. Trade Insight is education only — not financial advice. <a href="/risk-disclosure/">Read the risk disclosure →</a>',
    am: '⚠️ <b>Trading ከፍተኛ risk አለው።</b> ገንዘብዎን በከፊል ወይም ሙሉ በሙሉ ሊያጡ ይችላሉ። Trade Insight ትምህርት ብቻ ነው — የፋይናንስ ምክር አይደለም። <a href="/risk-disclosure/#am">የrisk ማሳሰቢያውን ያንብቡ →</a>'
  };
  function lang() { try { return localStorage.getItem('ti-ui-lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  var bar, cur = '', H = 0, busy = false;
  function render() { var l = lang(); if (l !== cur) { cur = l; bar.innerHTML = T[l]; } }
  function offset() {
    if (busy) return; busy = true;
    requestAnimationFrame(function () {
      busy = false;
      var h = bar.offsetHeight;
      if (h !== H) { H = h; document.documentElement.style.setProperty('--ti-risk-h', H + 'px'); document.body.style.paddingTop = H + 'px'; }
      var els = document.querySelectorAll('#root header, #root aside, #root nav, #root [class*="fixed"], #root [class*="sticky"]');
      for (var i = 0; i < els.length; i++) {
        var el = els[i], cs = getComputedStyle(el);
        if (cs.position !== 'fixed' && cs.position !== 'sticky') continue;
        if (el.dataset.tiTop === undefined) {
          if (parseFloat(cs.top) !== 0 || cs.top === 'auto') continue;   // only elements pinned to the top
          el.dataset.tiTop = '1';
          if (cs.position === 'fixed' && parseFloat(cs.bottom) === 0) el.dataset.tiFull = '1';
        }
        el.style.top = H + 'px';
        if (el.dataset.tiFull) el.style.height = 'calc(100% - ' + H + 'px)';
      }
    });
  }
  function build() {
    if (document.getElementById('ti-risk-bar')) return;
    bar = document.createElement('div');
    bar.id = 'ti-risk-bar';
    bar.setAttribute('role', 'note');
    bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483000;background:#fff4e0;color:#3d2a00;border-bottom:1px solid #f3c77a;font:500 13px/1.45 Outfit,"Noto Sans Ethiopic",system-ui,sans-serif;padding:7px 16px;text-align:center';
    var css = document.createElement('style');
    css.textContent = '#ti-risk-bar a{color:#0b0f3b;font-weight:700;text-decoration:underline;white-space:nowrap}#ti-risk-bar b{font-weight:700}';
    document.head.appendChild(css);
    render();
    document.body.insertBefore(bar, document.body.firstChild);
    offset();
    new MutationObserver(offset).observe(document.getElementById('root') || document.body, { childList: true, subtree: true });
    window.addEventListener('resize', offset);
    window.addEventListener('storage', function () { render(); offset(); });
    document.addEventListener('click', function () { setTimeout(function () { render(); offset(); }, 60); }, true);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build); else build();
})();
