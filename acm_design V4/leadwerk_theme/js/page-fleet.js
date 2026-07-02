/* ACM AIR CHARTER - global-7500 page scripts */

// Desktop menu
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

    // Header scroll
    const header = document.getElementById('header');
    function updateHeader() {
      if (window.pageYOffset > 20) { header.classList.add('header-scrolled'); } else { header.classList.remove('header-scrolled'); if (desktopMenu && desktopMenu.classList.contains('show')) closeDesktopMenu(); }
    }
    window.addEventListener('scroll', updateHeader);
    updateHeader();

    // Language
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
// Mobile menu
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

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function(e) {
        const href = this.getAttribute('href'); if (href === '#') return;
        const target = document.querySelector(href);
        if (target) { e.preventDefault(); const headerHeight = header.offsetHeight; window.scrollTo({ top: target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 20, behavior: 'smooth' }); }
      });
    });

    // Scroll reveal
    if ('IntersectionObserver' in window) {
      const revealObserver = new IntersectionObserver((entries) => { entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('revealed'); revealObserver.unobserve(entry.target); } }); }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
      document.querySelectorAll('.scroll-reveal').forEach(el => revealObserver.observe(el));
    }

    // Scroll to top
    function initScrollToTop() {
      const scrollToTopBtn = document.getElementById('scroll-to-top'); if (!scrollToTopBtn) return;
      function toggleScrollToTop() { if (window.scrollY > 300) { scrollToTopBtn.classList.add('visible'); } else { scrollToTopBtn.classList.remove('visible'); } }
      scrollToTopBtn.addEventListener('click', () => { window.scrollTo({ top: 0, behavior: 'smooth' }); });
      window.addEventListener('scroll', toggleScrollToTop, { passive: true }); toggleScrollToTop();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initScrollToTop); } else { initScrollToTop(); }

    // Mobile sticky CTA visibility
    (function() {
      const stickyCta = document.getElementById('mobile-sticky-cta');
      if (!stickyCta) return;
      const heroSection = document.getElementById('hero');
      const contactSection = document.getElementById('contact');
      function updateStickyVisibility() {
        if (!heroSection || !contactSection) return;
        const heroBottom = heroSection.getBoundingClientRect().bottom;
        const contactTop = contactSection.getBoundingClientRect().top;
        const windowHeight = window.innerHeight;
        if (heroBottom < 0 && contactTop > windowHeight) { stickyCta.classList.add('visible'); } else { stickyCta.classList.remove('visible'); }
      }
      window.addEventListener('scroll', updateStickyVisibility, { passive: true });
      updateStickyVisibility();
    })();

    // Video fallback to poster image
    (function() {
      const video = document.getElementById('video-banner');
      const fallback = document.getElementById('video-fallback');
      if (!video || !fallback) return;
      video.addEventListener('error', function() {
        video.style.display = 'none';
        fallback.style.display = 'block';
      });
      const src = video.querySelector('source');
      if (src) {
        src.addEventListener('error', function() {
          video.style.display = 'none';
          fallback.style.display = 'block';
        });
      }
    })();

    // Image Carousel
    (function() {
      const slides = document.querySelectorAll('.carousel-slide');
      const dotsContainer = document.getElementById('carousel-dots');
      const prevBtn = document.getElementById('carousel-prev');
      const nextBtn = document.getElementById('carousel-next');
      if (!slides.length || !dotsContainer) return;

      const total = slides.length;
      let current = 0;
      let autoplayTimer;

      slides.forEach((_, i) => {
        const dot = document.createElement('span');
        dot.className = 'carousel-dot' + (i === 0 ? ' active' : '');
        dot.addEventListener('click', () => goTo(i));
        dotsContainer.appendChild(dot);
      });

      function getPosition(offset) {
        if (offset === 0) return 'active';
        if (offset === 1 || offset === -(total - 1)) return 'next';
        if (offset === -1 || offset === (total - 1)) return 'prev';
        if (offset === 2 || offset === -(total - 2)) return 'hidden-right';
        return 'hidden-left';
      }

      function update() {
        slides.forEach((slide, i) => {
          slide.classList.remove('active', 'prev', 'next', 'hidden-left', 'hidden-right');
          let offset = i - current;
          if (offset > Math.floor(total / 2)) offset -= total;
          if (offset < -Math.floor(total / 2)) offset += total;
          slide.classList.add(getPosition(offset));
        });
        dotsContainer.querySelectorAll('.carousel-dot').forEach((dot, i) => {
          dot.classList.toggle('active', i === current);
        });
      }

      function goTo(idx) {
        current = ((idx % total) + total) % total;
        update();
        resetAutoplay();
      }

      function resetAutoplay() {
        clearInterval(autoplayTimer);
        autoplayTimer = setInterval(() => goTo(current + 1), 5000);
      }

      if (prevBtn) prevBtn.addEventListener('click', () => goTo(current - 1));
      if (nextBtn) nextBtn.addEventListener('click', () => goTo(current + 1));

      let touchStartX = 0;
      const track = document.getElementById('carousel-track');
      if (track) {
        track.addEventListener('touchstart', (e) => { touchStartX = e.touches[0].clientX; }, { passive: true });
        track.addEventListener('touchend', (e) => {
          const diff = touchStartX - e.changedTouches[0].clientX;
          if (Math.abs(diff) > 50) goTo(current + (diff > 0 ? 1 : -1));
        });
      }

      update();
      resetAutoplay();

      // Lightbox on carousel image click
      slides.forEach((slide) => {
        slide.addEventListener('click', () => {
          const img = slide.querySelector('img');
          if (!img) return;
          const lightbox = document.getElementById('lightbox');
          const lightboxImg = document.getElementById('lightbox-img');
          if (!lightbox || !lightboxImg) return;
          lightboxImg.src = img.src;
          lightboxImg.alt = img.alt || 'Bild in voller GrÃ¶ÃŸe';
          lightbox.classList.add('open');
          document.body.style.overflow = 'hidden';
        });
      });
    })();

    // Lightbox close
    (function() {
      const lightbox = document.getElementById('lightbox');
      const lightboxClose = document.getElementById('lightbox-close');
      if (!lightbox) return;

      function closeLightbox() {
        lightbox.classList.remove('open');
        document.body.style.overflow = '';
      }

      if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
      lightbox.addEventListener('click', (e) => {
        if (e.target === lightbox) closeLightbox();
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && lightbox.classList.contains('open')) closeLightbox();
      });
    })();

    // Seatplan day/night comparison slider
    (function() {
      const sliders = document.querySelectorAll('.seatplan-slider-container');
      if (!sliders.length) return;

      sliders.forEach((container) => {
        const overlay = container.querySelector('.seatplan-overlay-night');
        const handle = container.querySelector('.seatplan-handle');
        if (!overlay || !handle) return;

        let isDragging = false;

        function updatePosition(clientX) {
          const rect = container.getBoundingClientRect();
          if (!rect.width) return;

          let x = clientX - rect.left;
          x = Math.max(0, Math.min(x, rect.width));

          const pct = (x / rect.width) * 100;
          handle.style.left = pct + '%';
          overlay.style.clipPath = 'inset(0 0 0 ' + pct + '%)';
        }

        function stopDragging() {
          isDragging = false;
        }

        handle.addEventListener('mousedown', (e) => {
          isDragging = true;
          e.preventDefault();
        });

        handle.addEventListener('touchstart', () => {
          isDragging = true;
        }, { passive: true });

        document.addEventListener('mousemove', (e) => {
          if (isDragging) updatePosition(e.clientX);
        });

        document.addEventListener('touchmove', (e) => {
          if (isDragging && e.touches[0]) updatePosition(e.touches[0].clientX);
        }, { passive: true });

        document.addEventListener('mouseup', stopDragging);
        document.addEventListener('touchend', stopDragging);

        container.addEventListener('click', (e) => {
          updatePosition(e.clientX);
        });
      });
    })();


