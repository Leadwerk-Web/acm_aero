/**
 * Marks the active navigation link and enhances the desktop burger menu.
 */
(function () {
  function currentPage() {
    var path = window.location.pathname || '';
    try {
      path = decodeURIComponent(path);
    } catch (e) {}

    var file = path.replace(/\/$/, '').split('/').pop();
    if (!file) return 'index.html';
    return file.toLowerCase();
  }

  function hrefFile(href) {
    var value = (href || '').trim();
    if (!value || /^(https?:|mailto:|tel:|#)/i.test(value)) return '';
    return value.split('#')[0].split('?')[0].split('/').pop().toLowerCase();
  }

  function currentDirParts() {
    var path = window.location.pathname || '';
    try {
      path = decodeURIComponent(path);
    } catch (e) {}

    var parts = path.split('/').filter(Boolean);
    if (parts.length && /\.[a-z0-9]+$/i.test(parts[parts.length - 1])) {
      parts.pop();
    }
    return parts;
  }

  function relativePathTo(targetPath) {
    var from = currentDirParts();
    var to = String(targetPath || '').split('/').filter(Boolean);
    var shared = 0;

    while (shared < from.length && shared < to.length && from[shared] === to[shared]) {
      shared += 1;
    }

    var up = new Array(from.length - shared).fill('..');
    var down = to.slice(shared);
    var relative = up.concat(down).join('/');
    return relative || '.';
  }

  function normalizeText(value) {
    return (value || '').replace(/\s+/g, ' ').trim();
  }

  function mark(link) {
    link.classList.add('nav-link-active');
  }

  function closeDesktopMenu() {
    var menu = document.getElementById('desktop-menu');
    var backdrop = document.getElementById('desktop-menu-backdrop');
    var button = document.getElementById('desktop-menu-btn');

    if (!menu || !backdrop) return;

    menu.classList.remove('show');
    backdrop.classList.remove('show');
    if (button) {
      button.setAttribute('aria-expanded', 'false');
    }
    document.body.style.overflow = '';
  }

  function closeMobileMenu() {
    var menu = document.getElementById('mobile-menu');
    var button = document.getElementById('mobile-menu-btn');

    if (!menu) return;

    menu.classList.remove('mobile-menu-open');
    window.setTimeout(function () {
      menu.classList.add('hidden');
      if (button) {
        button.setAttribute('aria-expanded', 'false');
      }
    }, 300);
  }

  function bindMenuCloseDelegation() {
    var desktopMenu = document.getElementById('desktop-menu');
    var mobileMenu = document.getElementById('mobile-menu');

    if (desktopMenu && !desktopMenu.dataset.codexCloseBound) {
      desktopMenu.dataset.codexCloseBound = 'true';
      desktopMenu.addEventListener('click', function (event) {
        if (event.target.closest('a[href]')) {
          closeDesktopMenu();
        }
      });
    }

    if (mobileMenu && !mobileMenu.dataset.codexCloseBound) {
      mobileMenu.dataset.codexCloseBound = 'true';
      mobileMenu.addEventListener('click', function (event) {
        if (event.target.closest('a[href]')) {
          closeMobileMenu();
        }
      });
    }
  }

  function ensureIndexNewsLinks() {
    var isEnglish = (document.documentElement.lang || '').toLowerCase().indexOf('en') === 0;
    var target = relativePathTo(isEnglish ? 'en/news.html' : 'news.html');

    [
      { selector: '#desktop-menu .desktop-menu-nav', className: 'desktop-menu-link block py-4 text-stone-900 hover:text-accent transition-colors' },
      { selector: '#mobile-menu .mobile-menu-nav', className: 'mobile-menu-link block py-3' }
    ].forEach(function (config) {
      var nav = document.querySelector(config.selector);
      if (!nav) return;

      var links = Array.from(nav.querySelectorAll('a[href]'));
      var alreadyHasNews = links.some(function (link) {
        return hrefFile(link.getAttribute('href')) === 'news.html';
      });
      if (alreadyHasNews) return;

      var reference = links.find(function (link) {
        var label = normalizeText(link.textContent).toLowerCase();
        return label === 'kontakt' || label === 'contact';
      });

      var newsLink = document.createElement('a');
      newsLink.className = reference ? reference.className : config.className;
      if (reference && reference.getAttribute('style')) {
        newsLink.setAttribute('style', reference.getAttribute('style'));
      }
      newsLink.href = target;
      newsLink.textContent = 'News';

      if (reference) {
        nav.insertBefore(newsLink, reference);
      } else {
        nav.appendChild(newsLink);
      }
    });
  }

  function buildAccordionToggle(label) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'desktop-menu-accordion-toggle';
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', label);
    button.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">' +
      '<path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"></path>' +
      '</svg>';
    return button;
  }

  function setAccordionState(wrapper, panel, button, open) {
    wrapper.classList.toggle('is-open', open);
    panel.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function enhanceDesktopBurgerMenu(currentFile, fleetPage, english) {
    var nav = document.querySelector('#desktop-menu .desktop-menu-nav');
    if (!nav || nav.dataset.accordionEnhanced === 'true') return;

    nav.dataset.accordionEnhanced = 'true';
    var links = Array.from(nav.children).filter(function (node) {
      return node.tagName === 'A' && node.classList.contains('desktop-menu-link');
    });

    var charterLink = links.find(function (link) {
      return hrefFile(link.getAttribute('href')) === 'charter.html';
    });
    if (!charterLink) return;

    var fleetLinks = [];
    var next = charterLink.nextElementSibling;
    while (next && next.tagName === 'A' && next.classList.contains('desktop-menu-link')) {
      var file = hrefFile(next.getAttribute('href'));
      if (!/^global-(7500|6000|xrs)\.html$/i.test(file)) break;
      fleetLinks.push(next);
      next = next.nextElementSibling;
    }

    if (!fleetLinks.length) return;

    var wrapper = document.createElement('div');
    wrapper.className = 'desktop-menu-accordion';

    var header = document.createElement('div');
    header.className = 'desktop-menu-accordion-header';

    var toggle = buildAccordionToggle(english ? 'Toggle Charter submenu' : 'Charter submenu umschalten');
    var panel = document.createElement('div');
    panel.className = 'desktop-menu-accordion-panel';
    panel.id = 'desktop-menu-charter-panel';
    toggle.setAttribute('aria-controls', panel.id);

    nav.insertBefore(wrapper, charterLink);
    header.appendChild(charterLink);
    header.appendChild(toggle);
    wrapper.appendChild(header);
    wrapper.appendChild(panel);
    fleetLinks.forEach(function (link) {
      panel.appendChild(link);
    });

    var shouldOpen = currentFile === 'charter.html' || fleetPage;
    setAccordionState(wrapper, panel, toggle, shouldOpen);

    toggle.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      setAccordionState(wrapper, panel, toggle, !wrapper.classList.contains('is-open'));
    });
  }

  function ensureStyles() {
    if (document.querySelector('style[data-nav-active]')) return;

    var css = document.createElement('style');
    css.setAttribute('data-nav-active', '');
    css.textContent =
      '.header-nav-link.nav-link-active{color:#fff!important;font-weight:600}' +
      '.header-nav-link.nav-link-active::after{width:100%!important;height:1px;background:#fff!important;opacity:1}' +
      '.header-scrolled .header-nav-link.nav-link-active{color:var(--color-olive,#001441)!important}' +
      '.header-scrolled .header-nav-link.nav-link-active::after{background:var(--color-olive,#001441)!important;height:1px}' +
      '.header-nav-dropdown a.nav-link-active{color:var(--color-olive,#001441)!important;font-weight:600;background:rgba(0,20,65,0.08)}' +
      '.desktop-menu-link.nav-link-active{color:var(--color-olive,#001441)!important;font-weight:600}' +
      '.mobile-menu-link.nav-link-active{color:var(--color-olive,#001441)!important;font-weight:600}' +
      '.desktop-menu-accordion{display:block}' +
      '.desktop-menu-accordion-header{display:flex;align-items:center;gap:.5rem}' +
      '.desktop-menu-accordion-header>.desktop-menu-link{flex:1 1 auto;min-width:0}' +
      '.desktop-menu-accordion-toggle{display:inline-flex;align-items:center;justify-content:center;width:2.5rem;height:2.5rem;border:1px solid rgba(0,20,65,0.12);background:transparent;color:rgba(28,25,23,0.72);transition:border-color .25s ease,color .25s ease,background .25s ease}' +
      '.desktop-menu-accordion-toggle:hover{border-color:var(--color-olive,#001441);color:var(--color-olive,#001441);background:rgba(0,20,65,0.04)}' +
      '.desktop-menu-accordion-toggle svg{width:1rem;height:1rem;transition:transform .25s ease}' +
      '.desktop-menu-accordion.is-open .desktop-menu-accordion-toggle svg{transform:rotate(180deg)}' +
      '.desktop-menu-accordion-panel{max-height:0;overflow:hidden;opacity:0;transition:max-height .3s ease,opacity .25s ease,padding-top .25s ease}' +
      '.desktop-menu-accordion-panel.is-open{max-height:14rem;opacity:1;padding-top:.25rem}';
    document.head.appendChild(css);
  }

  function applyActiveStates(cur, isFleetPage) {
    document.querySelectorAll('header a[href]').forEach(function (link) {
      var href = (link.getAttribute('href') || '').trim();
      if (!href || /^(https?:|mailto:|tel:|#)/i.test(href)) return;
      if (!/\.html(\?.*)?$/i.test(href.split('#')[0])) return;

      var file = hrefFile(href);
      if (!file) return;

      if (file === cur) {
        if (link.classList.contains('header-nav-link') || link.classList.contains('desktop-menu-link') || link.classList.contains('mobile-menu-link')) {
          mark(link);
        }
        if (link.closest && link.closest('.header-nav-dropdown')) {
          mark(link);
        }
      }

      if (isFleetPage && file === 'charter.html') {
        if (link.classList.contains('header-nav-link') && link.closest && link.closest('.header-nav-charter-wrap')) {
          mark(link);
        }
        if ((link.classList.contains('desktop-menu-link') || link.classList.contains('mobile-menu-link')) && normalizeText(link.textContent) === 'Charter') {
          mark(link);
        }
      }
    });
  }

  function initNavActive() {
    var desktopNav = document.querySelector('#desktop-menu .desktop-menu-nav');
    var mobileNav = document.querySelector('#mobile-menu .mobile-menu-nav');
    if (!document.head || (!desktopNav && !mobileNav)) return false;

    var cur = currentPage();
    var isFleetPage = /^global-(7500|6000|xrs)\.html$/.test(cur);
    var isEnglish = (document.documentElement.lang || '').toLowerCase().indexOf('en') === 0;

    ensureStyles();
    ensureIndexNewsLinks();
    enhanceDesktopBurgerMenu(cur, isFleetPage, isEnglish);
    bindMenuCloseDelegation();
    applyActiveStates(cur, isFleetPage);
    return true;
  }

  if (!initNavActive()) {
    var attempts = 0;
    var retryTimer = window.setInterval(function () {
      attempts += 1;
      if (initNavActive() || attempts >= 20) {
        window.clearInterval(retryTimer);
      }
    }, 100);

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initNavActive, { once: true });
    }
    window.addEventListener('load', initNavActive, { once: true });
  }
})();
