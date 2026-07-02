/* ACM AIR CHARTER - kontakt page scripts */

const desktopMenuBtn = document.getElementById('desktop-menu-btn');
    const desktopMenuClose = document.getElementById('desktop-menu-close');
    const desktopMenu = document.getElementById('desktop-menu');
    const desktopMenuBackdrop = document.getElementById('desktop-menu-backdrop');

    function openDesktopMenu() {
      if (!desktopMenu || !desktopMenuBackdrop) return;
      desktopMenu.classList.remove('hidden');
      desktopMenuBackdrop.classList.remove('hidden');
      requestAnimationFrame(() => { requestAnimationFrame(() => { desktopMenu.classList.add('show'); desktopMenuBackdrop.classList.add('show'); }); });
      desktopMenuBtn.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
    }
    function closeDesktopMenu() {
      if (!desktopMenu || !desktopMenuBackdrop) return;
      desktopMenu.classList.remove('show'); desktopMenuBackdrop.classList.remove('show');
      desktopMenuBtn.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
      setTimeout(() => { desktopMenu.classList.add('hidden'); desktopMenuBackdrop.classList.add('hidden'); }, 350);
    }
    if (desktopMenuBtn) desktopMenuBtn.addEventListener('click', openDesktopMenu);
    if (desktopMenuClose) desktopMenuClose.addEventListener('click', closeDesktopMenu);
    if (desktopMenuBackdrop) desktopMenuBackdrop.addEventListener('click', closeDesktopMenu);
    if (desktopMenu) { desktopMenu.querySelectorAll('a').forEach(link => { link.addEventListener('click', () => closeDesktopMenu()); }); }
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && desktopMenu && desktopMenu.classList.contains('show')) closeDesktopMenu(); });

    const header = document.getElementById('header');
    function updateHeader() {
      if (window.pageYOffset > 20) { header.classList.add('header-scrolled'); } else { header.classList.remove('header-scrolled'); if (desktopMenu && desktopMenu.classList.contains('show')) closeDesktopMenu(); }
    }
    window.addEventListener('scroll', updateHeader);
    updateHeader();
    // Language switcher state follows the rendered locale.
    // Language switcher state follows the rendered locale.
    // Language switcher state follows the rendered locale.
    // Language switcher state follows the rendered locale.
    // Language switcher state follows the rendered locale.
    // Language switcher state follows the rendered locale.
    (function setActiveLang() {
      const lang = (document.documentElement.lang || 'de').toLowerCase().startsWith('en') ? 'en' : 'de';
      document.querySelectorAll('.header-lang-link').forEach(function (a) {
        if ((a.getAttribute('data-lang') || '').toLowerCase() === lang) {
          a.classList.add('header-lang-active');
        } else {
          a.classList.remove('header-lang-active');
        }
      });
    })();
const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    const mobileMenu = document.getElementById('mobile-menu');
    if (mobileMenuBtn && mobileMenu) {
      mobileMenuBtn.addEventListener('click', () => {
        const isExpanded = mobileMenuBtn.getAttribute('aria-expanded') === 'true';
        if (isExpanded) {
          mobileMenu.classList.remove('mobile-menu-open');
          setTimeout(() => { mobileMenu.classList.add('hidden'); mobileMenuBtn.setAttribute('aria-expanded', 'false'); }, 300);
        } else {
          mobileMenu.classList.remove('hidden'); mobileMenuBtn.setAttribute('aria-expanded', 'true');
          requestAnimationFrame(() => { requestAnimationFrame(() => { mobileMenu.classList.add('mobile-menu-open'); }); });
        }
      });
      mobileMenu.querySelectorAll('a').forEach(link => { link.addEventListener('click', () => { mobileMenu.classList.remove('mobile-menu-open'); setTimeout(() => { mobileMenu.classList.add('hidden'); mobileMenuBtn.setAttribute('aria-expanded', 'false'); }, 300); }); });
    }
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function(e) {
        const href = this.getAttribute('href'); if (href === '#') return;
        const target = document.querySelector(href);
        if (target) {
          e.preventDefault();
          const navEl = document.getElementById('anchor-nav');
          const navHeight = navEl && navEl.classList.contains('visible') ? navEl.offsetHeight : 0;
          const headerHeight = header.classList.contains('header-scrolled') ? header.offsetHeight : 0;
          const offset = headerHeight + navHeight + 20;
          window.scrollTo({ top: target.getBoundingClientRect().top + window.pageYOffset - offset, behavior: 'smooth' });
        }
      });
    });

    if ('IntersectionObserver' in window) {
      const revealObserver = new IntersectionObserver((entries) => { entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('revealed'); revealObserver.unobserve(entry.target); } }); }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
      document.querySelectorAll('.scroll-reveal').forEach(el => revealObserver.observe(el));
    }

    function initScrollToTop() {
      const scrollToTopBtn = document.getElementById('scroll-to-top'); if (!scrollToTopBtn) return;
      function toggleScrollToTop() { if (window.scrollY > 300) { scrollToTopBtn.classList.add('visible'); } else { scrollToTopBtn.classList.remove('visible'); } }
      scrollToTopBtn.addEventListener('click', () => { window.scrollTo({ top: 0, behavior: 'smooth' }); });
      window.addEventListener('scroll', toggleScrollToTop, { passive: true }); toggleScrollToTop();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initScrollToTop); } else { initScrollToTop(); }

    // Fixed anchor nav: show/hide, highlight active section, progress bar
    (function() {
      const anchorNav = document.getElementById('anchor-nav');
      const progressBar = document.getElementById('scroll-progress');
      const pills = document.querySelectorAll('.anchor-pill[data-section]');
      const sections = [];

      pills.forEach(pill => {
        const id = pill.getAttribute('data-section');
        const el = document.getElementById(id);
        if (el) sections.push({ id, el, pill });
      });

      if (!sections.length || !anchorNav) return;

      const firstSection = sections[0].el;
      const lastSection = sections[sections.length - 1].el;

      function updateAnchorNav() {
        const firstSectionTop = firstSection.getBoundingClientRect().top;
        const triggerPoint = 120;
        const showNav = firstSectionTop <= triggerPoint;

        if (showNav) {
          anchorNav.classList.add('visible', 'has-shadow');
        } else {
          anchorNav.classList.remove('visible', 'has-shadow');
        }

        const stickyOffset = 56 + anchorNav.offsetHeight + 40;
        let current = sections[0];
        for (const s of sections) {
          if (s.el.getBoundingClientRect().top <= stickyOffset) {
            current = s;
          }
        }

        pills.forEach(p => p.classList.remove('active'));
        if (current) current.pill.classList.add('active');

        if (progressBar && sections.length) {
          const contentStart = firstSection.offsetTop;
          const contentEnd = lastSection.offsetTop + lastSection.offsetHeight;
          const contentHeight = contentEnd - contentStart;
          const scrolled = window.scrollY - contentStart + window.innerHeight * 0.5;
          const progress = Math.max(0, Math.min(100, (scrolled / contentHeight) * 100));
          progressBar.style.width = progress + '%';
        }
      }

      window.addEventListener('scroll', updateAnchorNav, { passive: true });
      updateAnchorNav();
    })();


