/**
 * Markiert die zur aktuellen Seite passenden Navigationslinks (Desktop, Flyout, Mobil, Charter-Dropdown).
 */
(function () {
  function currentPage() {
    var p = window.location.pathname || '';
    try {
      p = decodeURIComponent(p);
    } catch (e) {}
    var f = p.replace(/\/$/, '').split('/').pop();
    if (!f || f === '') return 'index.html';
    return f.toLowerCase();
  }

  var cur = currentPage();
  var isFleetPage = /^global-(7500|6000|xrs)\.html$/.test(cur);

  var css = document.createElement('style');
  css.setAttribute('data-nav-active', '');
  css.textContent =
    '.header-nav-link.nav-link-active{color:#fff!important;font-weight:600}' +
    '.header-nav-link.nav-link-active::after{width:100%!important;height:1px;background:#fff!important;opacity:1}' +
    '.header-scrolled .header-nav-link.nav-link-active{color:var(--color-olive,#001441)!important}' +
    '.header-scrolled .header-nav-link.nav-link-active::after{background:var(--color-olive,#001441)!important;height:1px}' +
    '.header-nav-dropdown a.nav-link-active{color:var(--color-olive,#001441)!important;font-weight:600;background:rgba(0,20,65,0.08)}' +
    '.desktop-menu-link.nav-link-active{color:var(--color-olive,#001441)!important;font-weight:600}' +
    '.mobile-menu-link.nav-link-active{color:var(--color-olive,#001441)!important;font-weight:600}';
  document.head.appendChild(css);

  function mark(a) {
    a.classList.add('nav-link-active');
  }

  document.querySelectorAll('header a[href]').forEach(function (a) {
    var href = (a.getAttribute('href') || '').trim();
    if (!href || /^(https?:|mailto:|tel:|#)/i.test(href)) return;
    if (!/\.html(\?.*)?$/i.test(href.split('#')[0])) return;

    var file = href.split('#')[0].split('?')[0].split('/').pop().toLowerCase();
    if (!file) return;

    if (file === cur) {
      if (a.classList.contains('header-nav-link') || a.classList.contains('desktop-menu-link') || a.classList.contains('mobile-menu-link')) {
        mark(a);
      }
      if (a.closest && a.closest('.header-nav-dropdown')) {
        mark(a);
      }
    }

    if (isFleetPage && file === 'charter.html') {
      if (a.classList.contains('header-nav-link') && a.closest && a.closest('.header-nav-charter-wrap')) {
        mark(a);
      }
      var label = (a.textContent || '').replace(/\s+/g, ' ').trim();
      if (
        (a.classList.contains('desktop-menu-link') || a.classList.contains('mobile-menu-link')) &&
        label === 'Charter'
      ) {
        mark(a);
      }
    }
  });
})();
