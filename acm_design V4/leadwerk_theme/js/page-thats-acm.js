/* ACM AIR CHARTER - thats-acm page scripts */

const desktopMenuBtn = document.getElementById('desktop-menu-btn');
    const desktopMenuClose = document.getElementById('desktop-menu-close');
    const desktopMenu = document.getElementById('desktop-menu');
    const desktopMenuBackdrop = document.getElementById('desktop-menu-backdrop');

    function openDesktopMenu() {
      if (!desktopMenu || !desktopMenuBackdrop) return;
      desktopMenu.classList.remove('hidden');
      desktopMenuBackdrop.classList.remove('hidden');
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          desktopMenu.classList.add('show');
          desktopMenuBackdrop.classList.add('show');
        });
      });
      desktopMenuBtn.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
    }

    function closeDesktopMenu() {
      if (!desktopMenu || !desktopMenuBackdrop) return;
      desktopMenu.classList.remove('show');
      desktopMenuBackdrop.classList.remove('show');
      desktopMenuBtn.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
      setTimeout(() => {
        desktopMenu.classList.add('hidden');
        desktopMenuBackdrop.classList.add('hidden');
      }, 350);
    }

    if (desktopMenuBtn) desktopMenuBtn.addEventListener('click', openDesktopMenu);
    if (desktopMenuClose) desktopMenuClose.addEventListener('click', closeDesktopMenu);
    if (desktopMenuBackdrop) desktopMenuBackdrop.addEventListener('click', closeDesktopMenu);
    if (desktopMenu) {
      desktopMenu.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => closeDesktopMenu());
      });
    }
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && desktopMenu && desktopMenu.classList.contains('show')) closeDesktopMenu();
    });

    const header = document.getElementById('header');
    function updateHeader() {
      if (window.pageYOffset > 20) {
        header.classList.add('header-scrolled');
      } else {
        header.classList.remove('header-scrolled');
        if (desktopMenu && desktopMenu.classList.contains('show')) closeDesktopMenu();
      }
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
          setTimeout(() => {
            mobileMenu.classList.add('hidden');
            mobileMenuBtn.setAttribute('aria-expanded', 'false');
          }, 300);
        } else {
          mobileMenu.classList.remove('hidden');
          mobileMenuBtn.setAttribute('aria-expanded', 'true');
          requestAnimationFrame(() => {
            requestAnimationFrame(() => {
              mobileMenu.classList.add('mobile-menu-open');
            });
          });
        }
      });
      mobileMenu.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
          mobileMenu.classList.remove('mobile-menu-open');
          setTimeout(() => {
            mobileMenu.classList.add('hidden');
            mobileMenuBtn.setAttribute('aria-expanded', 'false');
          }, 300);
        });
      });
    }
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function(e) {
        const href = this.getAttribute('href');
        if (href === '#') return;
        const target = document.querySelector(href);
        if (target) {
          e.preventDefault();
          const headerHeight = header.offsetHeight;
          window.scrollTo({ top: target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 20, behavior: 'smooth' });
        }
      });
    });

    if ('IntersectionObserver' in window) {
      const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('revealed');
            revealObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
      document.querySelectorAll('.scroll-reveal').forEach(el => revealObserver.observe(el));
    }

    function initScrollToTop() {
      const scrollToTopBtn = document.getElementById('scroll-to-top');
      if (!scrollToTopBtn) return;
      function toggleScrollToTop() {
        if (window.scrollY > 300) {
          scrollToTopBtn.classList.add('visible');
        } else {
          scrollToTopBtn.classList.remove('visible');
        }
      }
      scrollToTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
      window.addEventListener('scroll', toggleScrollToTop, { passive: true });
      toggleScrollToTop();
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initScrollToTop);
    } else {
      initScrollToTop();
    }

(function() {
    const section   = document.getElementById('htl-section');
    const track     = document.getElementById('htl-track');
    const dotsWrap  = document.getElementById('htl-dots');
    const progress  = document.getElementById('htl-progress');
    const prevSectionBtn = document.getElementById('htl-scroll-prev-section');
    const nextSectionBtn = document.getElementById('htl-scroll-next-section');
    if (!section || !track || !dotsWrap) return;

    const cards     = Array.from(track.querySelectorAll('article.htl-card'));
    const dots      = Array.from(dotsWrap.querySelectorAll('.htl-dot'));
    const total       = cards.length;
    let active        = 0;
    let locked        = false;
    let scrollAccum   = 0;
    let transitioning = false;
    const THRESHOLD   = 120;
    const DURATION_MS = 1250;

    function getCardWidth() {
      return cards[0] ? cards[0].offsetWidth : 420;
    }

    function getInitialOffset() {
      const vw = document.getElementById('htl-root').offsetWidth;
      return Math.round((vw / 2) - (getCardWidth() / 2));
    }

    /**
     * Statische Shells: <main><section>…</section></main>.
     * WordPress (Exact-Render): nur die Section-Fragmente ohne <main> — dann Geschwister-Sections am gemeinsamen Elternknoten.
     */
    function getTimelinePageSections() {
      const mainEl = document.querySelector('main');
      if (mainEl) {
        return Array.from(mainEl.querySelectorAll(':scope > section'));
      }
      if (!section) {
        return [];
      }
      const p = section.parentElement;
      if (!p) {
        return [];
      }
      const direct = Array.from(p.children).filter(function(el) {
        return el.nodeType === 1 && el.tagName === 'SECTION';
      });
      if (direct.indexOf(section) !== -1) {
        return direct;
      }
      return Array.from(document.body.querySelectorAll(':scope > section'));
    }

    function updateSectionJumpState() {
      if (!section) return;
      const secs = getTimelinePageSections();
      if (secs.length === 0) return;
      const idx = secs.indexOf(section);
      if (prevSectionBtn) prevSectionBtn.disabled = idx <= 0;
      if (nextSectionBtn) nextSectionBtn.disabled = idx >= secs.length - 1;
    }

    function scrollToMainSection(delta) {
      if (!section) return;
      const secs = getTimelinePageSections();
      if (secs.length === 0) return;
      const idx = secs.indexOf(section);
      const nextIdx = idx + delta;
      if (nextIdx < 0 || nextIdx >= secs.length) return;
      const target = secs[nextIdx];
      const hdr = document.getElementById('header');
      const hh = hdr ? hdr.offsetHeight : 0;
      const top = target.getBoundingClientRect().top + window.pageYOffset - hh - 16;
      window.scrollTo({ top, behavior: 'smooth' });
    }

    function goTo(idx, instant) {
      idx = Math.max(0, Math.min(total - 1, idx));
      active = idx;
      const cw = getCardWidth();
      const offset = getInitialOffset();
      const tx = offset - (idx * cw);
      const dur = instant ? '0s' : '1.25s';
      const easing = 'cubic-bezier(0.2,0.5,0.35,1)';
      track.style.transition = 'transform ' + dur + ' ' + easing;
      dotsWrap.style.transition = 'transform ' + dur + ' ' + easing;
      track.style.transform   = 'translateX(' + tx + 'px)';
      dotsWrap.style.transform = 'translateX(' + tx + 'px)';

      cards.forEach(function(c, i) {
        c.classList.toggle('htl-active', i === idx);
      });
      dots.forEach(function(d, i) {
        d.classList.toggle('htl-dot-active', i === idx);
      });

      var lineLeft = offset + (idx * cw) + (cw / 2);
      progress.style.width = lineLeft + 'px';

      if (!instant) {
        transitioning = true;
        scrollAccum = 0;
        setTimeout(function() { transitioning = false; }, DURATION_MS);
      }
    }

    goTo(0, true);

    if (prevSectionBtn) {
      prevSectionBtn.addEventListener('click', function() {
        scrollToMainSection(-1);
      });
    }
    if (nextSectionBtn) {
      nextSectionBtn.addEventListener('click', function() {
        scrollToMainSection(1);
      });
    }
    updateSectionJumpState();
    // #region agent log
    (function htlSectionNavDbg() {
      try {
        var secs = getTimelinePageSections();
        fetch('http://127.0.0.1:7345/ingest/b1195c55-d6eb-488c-93f3-5ea6ddc2460c', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Debug-Session-Id': 'eeb156' }, body: JSON.stringify({ sessionId: 'eeb156', location: 'page-thats-acm.js:init', message: 'timeline section-nav context', data: { hasMain: !!document.querySelector('main'), sectionsCount: secs.length, timelineIndex: secs.indexOf(section) }, timestamp: Date.now(), hypothesisId: 'WP_NO_MAIN' }) }).catch(function() {});
      } catch (e) {}
    })();
    // #endregion
    window.addEventListener('scroll', function() { updateSectionJumpState(); }, { passive: true });

    var resizeTimer;
    window.addEventListener('resize', function() {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function() { goTo(active, true); }, 120);
    });

    /* â”€â”€ Scroll hijack (desktop) â”€â”€ */
    var io = new IntersectionObserver(function(entries) {
      entries.forEach(function(e) {
        if (e.isIntersecting && e.intersectionRatio > 0.3) {
          locked = true;
        } else {
          locked = false;
        }
      });
    }, { threshold: [0, 0.3, 0.6, 1] });
    io.observe(section);

    window.addEventListener('wheel', function(e) {
      var sRect = section.getBoundingClientRect();
      var mouseInSection = e.clientX >= sRect.left && e.clientX <= sRect.right && e.clientY >= sRect.top && e.clientY <= sRect.bottom;
      if (!mouseInSection) return;

      if (!locked) return;
      if (transitioning) {
        e.preventDefault();
        return;
      }

      var inView = sRect.top < window.innerHeight * 0.5 && sRect.bottom > window.innerHeight * 0.5;
      if (!inView) { locked = false; return; }

      var atStart = active === 0 && e.deltaY < 0;
      var atEnd   = active === total - 1 && e.deltaY > 0;
      if (atStart || atEnd) {
        locked = false;
        return;
      }

      e.preventDefault();
      scrollAccum += e.deltaY;

      if (Math.abs(scrollAccum) >= THRESHOLD) {
        goTo(active + (scrollAccum > 0 ? 1 : -1));
      }
    }, { passive: false });

    /* â”€â”€ Mouse drag: Timeline folgt der Maus in Echtzeit, beim Loslassen Snap â”€â”€ */
    var dragStartX = 0;
    var dragBaseTranslate = 0;
    var isDragging = false;

    function applyDragTranslate(tx) {
      var cw = getCardWidth();
      var offset = getInitialOffset();
      var minTx = offset - (total - 1) * cw;
      var maxTx = offset;
      tx = Math.max(minTx, Math.min(maxTx, tx));
      track.style.transition = 'none';
      dotsWrap.style.transition = 'none';
      track.style.transform = 'translateX(' + tx + 'px)';
      dotsWrap.style.transform = 'translateX(' + tx + 'px)';
      var activeIndex = Math.round((offset - tx) / cw);
      activeIndex = Math.max(0, Math.min(total - 1, activeIndex));
      active = activeIndex;
      cards.forEach(function(c, i) {
        c.classList.toggle('htl-active', i === activeIndex);
      });
      dots.forEach(function(d, i) {
        d.classList.toggle('htl-dot-active', i === activeIndex);
      });
      progress.style.transition = 'none';
      progress.style.width = (offset + activeIndex * cw + cw / 2) + 'px';
    }

    function onMouseMove(e) {
      if (!isDragging) return;
      var deltaX = e.clientX - dragStartX;
      applyDragTranslate(dragBaseTranslate + deltaX);
    }

    function onMouseUp(e) {
      if (!isDragging) return;
      isDragging = false;
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', onMouseUp);
      document.body.style.userSelect = '';
      document.body.style.cursor = '';
      goTo(active);
    }

    section.addEventListener('mousedown', function(e) {
      if (e.button !== 0) return;
      var cw = getCardWidth();
      var offset = getInitialOffset();
      isDragging = true;
      dragStartX = e.clientX;
      dragBaseTranslate = offset - (active * cw);
      document.addEventListener('mousemove', onMouseMove);
      document.addEventListener('mouseup', onMouseUp);
      document.body.style.userSelect = 'none';
      document.body.style.cursor = 'grabbing';
    });

    /* â”€â”€ Touch (mobile) â”€â”€ */
    var touchStartX = 0;
    var touchStartY = 0;
    var touchMoved   = false;

    section.addEventListener('touchstart', function(e) {
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
      touchMoved = false;
    }, { passive: true });

    section.addEventListener('touchmove', function(e) {
      var dx = e.touches[0].clientX - touchStartX;
      var dy = e.touches[0].clientY - touchStartY;
      if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 20) {
        touchMoved = true;
      }
    }, { passive: true });

    section.addEventListener('touchend', function(e) {
      if (!touchMoved) return;
      var dx = e.changedTouches[0].clientX - touchStartX;
      if (Math.abs(dx) > 40) {
        goTo(active + (dx < 0 ? 1 : -1));
      }
    }, { passive: true });

    /* â”€â”€ Keyboard â”€â”€ */
    section.addEventListener('keydown', function(e) {
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); goTo(active + 1); }
      if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   { e.preventDefault(); goTo(active - 1); }
    });

    /* â”€â”€ Click on dot â”€â”€ */
    dots.forEach(function(d) {
      d.addEventListener('click', function() {
        goTo(parseInt(d.dataset.index, 10));
      });
      d.style.cursor = 'pointer';
    });
  })();


