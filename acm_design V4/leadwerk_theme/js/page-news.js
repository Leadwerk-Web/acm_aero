/* ACM AIR CHARTER - news page scripts */

/* Header: scroll, menus, language */
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
        if (target) { e.preventDefault(); const headerHeight = header.offsetHeight; window.scrollTo({ top: target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 20, behavior: 'smooth' }); }
      });
    });

    /* Scroll-reveal */
    if ('IntersectionObserver' in window) {
      const revealObserver = new IntersectionObserver((entries) => { entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('revealed'); revealObserver.unobserve(entry.target); } }); }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
      document.querySelectorAll('.scroll-reveal').forEach(el => revealObserver.observe(el));
    }

    /* Scroll to top */
    function initScrollToTop() {
      const scrollToTopBtn = document.getElementById('scroll-to-top'); if (!scrollToTopBtn) return;
      function toggleScrollToTop() { if (window.scrollY > 300) { scrollToTopBtn.classList.add('visible'); } else { scrollToTopBtn.classList.remove('visible'); } }
      scrollToTopBtn.addEventListener('click', () => { window.scrollTo({ top: 0, behavior: 'smooth' }); });
      window.addEventListener('scroll', toggleScrollToTop, { passive: true }); toggleScrollToTop();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initScrollToTop); } else { initScrollToTop(); }

    /* Category filter + Load more */
    (function initNewsGrid() {
      const filterBtns = document.querySelectorAll('#news-filters .filter-btn');
      const articles = () => document.querySelectorAll('#news-articles article');
      const loadMoreBtn = document.getElementById('load-more-btn');
      const loadMoreWrap = document.getElementById('load-more-wrap');
      const LOAD_BATCH = 3;
      let revealObserver = null;
      if ('IntersectionObserver' in window) {
        revealObserver = new IntersectionObserver((entries) => {
          entries.forEach(entry => {
            if (entry.isIntersecting) {
              entry.target.classList.add('revealed');
              revealObserver.unobserve(entry.target);
            }
          });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
      }

      function applyNewsFilter() {
        const active = document.querySelector('#news-filters .filter-btn.active');
        const filter = active ? active.getAttribute('data-filter') : 'all';
        articles().forEach(article => {
          if (article.classList.contains('news-load-more-item') && article.classList.contains('hidden')) {
            article.style.display = 'none';
            return;
          }
          const match = filter === 'all' || article.getAttribute('data-category') === filter;
          article.style.display = match ? '' : 'none';
        });
      }

      filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
          filterBtns.forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          applyNewsFilter();
        });
      });

      function pendingLoadMore() {
        return Array.from(document.querySelectorAll('#news-articles article.news-load-more-item.hidden'));
      }

      if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function () {
          const batch = pendingLoadMore().slice(0, LOAD_BATCH);
          batch.forEach(article => {
            article.classList.remove('hidden');
            if (revealObserver) {
              article.classList.remove('revealed');
              revealObserver.observe(article);
            } else {
              article.classList.add('revealed');
            }
          });
          applyNewsFilter();
          if (pendingLoadMore().length === 0) {
            if (loadMoreWrap) loadMoreWrap.classList.add('hidden');
          } else {
            loadMoreBtn.setAttribute('aria-expanded', 'true');
          }
        });
      }
    })();


