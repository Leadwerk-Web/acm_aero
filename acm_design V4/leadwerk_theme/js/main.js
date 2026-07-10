/* ==========================================================================
   ACM AIR CHARTER - Main JavaScript
   Header scroll, mobile menu, scroll-reveal, fleet slider,
   modals, smooth scroll, cursor follower.
   ========================================================================== */

// _paq.push(['trackEvent', 'Forms', 'Submit', 'Flight Request']);

// Desktop Burger Menu Functions (mÃ¼ssen vor updateHeader definiert werden)
    const desktopMenu = document.getElementById('desktop-menu');
    const desktopMenuBackdrop = document.getElementById('desktop-menu-backdrop');
    const desktopMenuBtn = document.getElementById('desktop-menu-btn');
    const desktopMenuClose = document.getElementById('desktop-menu-close');

    function openDesktopMenu() {
      if (desktopMenu && desktopMenuBackdrop) {
        desktopMenu.classList.add('show');
        desktopMenuBackdrop.classList.add('show');
        if (desktopMenuBtn) {
          desktopMenuBtn.setAttribute('aria-expanded', 'true');
        }
        document.body.style.overflow = 'hidden';
      }
    }

    function closeDesktopMenu() {
      if (desktopMenu && desktopMenuBackdrop) {
        desktopMenu.classList.remove('show');
        desktopMenuBackdrop.classList.remove('show');
        if (desktopMenuBtn) {
          desktopMenuBtn.setAttribute('aria-expanded', 'false');
        }
        document.body.style.overflow = '';
      }
    }

    // Desktop Menu Event Listeners
    if (desktopMenuBtn) {
      desktopMenuBtn.addEventListener('click', openDesktopMenu);
    }

    if (desktopMenuClose) {
      desktopMenuClose.addEventListener('click', closeDesktopMenu);
    }

    if (desktopMenuBackdrop) {
      desktopMenuBackdrop.addEventListener('click', closeDesktopMenu);
    }

    // Close desktop menu when clicking a link
    if (desktopMenu) {
      desktopMenu.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
          closeDesktopMenu();
        });
      });
    }

    // Close desktop menu on Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && desktopMenu && desktopMenu.classList.contains('show')) {
        closeDesktopMenu();
      }
    });

    // Sticky Header Glassmorphism on Scroll + Desktop Menu Toggle
    const header = document.getElementById('header');
    const desktopNav = document.getElementById('desktop-nav');
    let lastScroll = 0;
    const forceScrolledHeader = header && (header.hasAttribute('data-force-scrolled-header') || document.body.classList.contains('header-scrolled'));

    function updateHeader() {
      if (!header) return;
      if (forceScrolledHeader) {
        header.classList.add('header-scrolled');
        return;
      }
      const currentScroll = window.pageYOffset;
      
      if (currentScroll > 20) {
        // Gescrollt: Navigation verstecken, Burger-MenÃ¼ anzeigen
        header.classList.add('header-scrolled');
      } else {
        // Nicht gescrollt: Navigation mittig anzeigen, Burger-MenÃ¼ verstecken
        header.classList.remove('header-scrolled');
        // Desktop Menu schlieÃŸen wenn zurÃ¼ck nach oben gescrollt wird
        if (desktopMenu && desktopMenu.classList.contains('show')) {
          closeDesktopMenu();
        }
      }
      
      lastScroll = currentScroll;
    }

    window.addEventListener('scroll', updateHeader);
    
    // Initial state check
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
// Logo Fallback prÃ¼fen nach kurzer VerzÃ¶gerung
    setTimeout(() => {
      const logoImg = document.getElementById('header-logo-img');
      const logoFallback = document.getElementById('header-logo-fallback');
      if (logoImg && logoFallback) {
        // PrÃ¼fe ob Bild geladen wurde
        if (!logoImg.complete || logoImg.naturalHeight === 0) {
          logoImg.style.display = 'none';
          logoFallback.style.display = 'flex';
        } else {
          logoImg.style.display = 'block';
          logoFallback.style.display = 'none';
        }
      }
    }, 500);

    // Mobile Menu Toggle
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

      // Close mobile menu when clicking a link
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

    // Modal Functions
    function openModal(modalId) {
      const modal = document.getElementById(modalId);
      if (!modal) {
        return;
      }
      const modalContent = modal.querySelector('.modal-content');
      
      modal.classList.remove('hidden');
      document.body.style.overflow = 'hidden';
      
      // Trigger animation
      setTimeout(() => {
        if (modalContent) {
          modalContent.classList.add('show');
        }
      }, 10);
      
      // Focus first input
      setTimeout(() => {
        const firstInput = modal.querySelector('input, select, textarea');
        if (firstInput) firstInput.focus();
      }, 100);
    }

    function closeModal(modalId) {
      const modal = document.getElementById(modalId);
      if (!modal) {
        return;
      }
      const modalContent = modal.querySelector('.modal-content');
      
      // Remove show class for animation
      if (modalContent) {
        modalContent.classList.remove('show');
      }
      
      // Wait for animation to complete before hiding
      setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
      }, 300);
    }

    // Close modal on Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        document.querySelectorAll('[id$="-modal"]').forEach(modal => {
          if (!modal.classList.contains('hidden')) {
            closeModal(modal.id);
          }
        });
      }
    });

    // Starlink Pop-up: nur beim ersten Laden der Seite, bei 70 % Scrolltiefe
    (function initStarlinkPopup() {
      const SCROLL_THRESHOLD = 0.7; // 70 %
      const STORAGE_KEY = 'starlink-popup-shown';
      if (sessionStorage.getItem(STORAGE_KEY)) return; // Bereits in dieser Session gezeigt
      let alreadyShownThisPage = false;

      function checkScrollForStarlinkPopup() {
        if (alreadyShownThisPage) return;
        const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
        if (maxScroll <= 0) return;
        if (window.scrollY >= maxScroll * SCROLL_THRESHOLD) {
          alreadyShownThisPage = true;
          sessionStorage.setItem(STORAGE_KEY, '1');
          openModal('starlink-modal');
          window.removeEventListener('scroll', checkScrollForStarlinkPopup);
        }
      }

      window.addEventListener('scroll', checkScrollForStarlinkPopup, { passive: true });
    })();

// Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function(e) {
        const href = this.getAttribute('href');
        if (href === '#') return;
        
        const target = document.querySelector(href);
        if (target) {
          e.preventDefault();
          const headerHeight = header.offsetHeight;
          const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 20;
          
          window.scrollTo({
            top: targetPosition,
            behavior: 'smooth'
          });
        }
      });
    });

    // Lazy load images with IntersectionObserver
    if ('IntersectionObserver' in window) {
      const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            const img = entry.target;
            if (img.dataset.src) {
              img.src = img.dataset.src;
              img.removeAttribute('data-src');
            }
            observer.unobserve(img);
          }
        });
      }, {
        rootMargin: '100px'
      });

      document.querySelectorAll('img[data-src]').forEach(img => {
        imageObserver.observe(img);
      });
    }

    // Scroll Reveal Animation
    if ('IntersectionObserver' in window) {
      const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('revealed');
            revealObserver.unobserve(entry.target);
          }
        });
      }, {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
      });

      document.querySelectorAll('.scroll-reveal').forEach(el => {
        revealObserver.observe(el);
      });
    }
    

    // Hero Video — poster sofort, Wiedergabe ab 0 s
    const heroVideo = document.getElementById('hero-video');

    if (heroVideo) {
      let heroBooted = false;

      function bootHeroVideo() {
        if (heroBooted) return;
        heroBooted = true;
        heroVideo.classList.add('loaded', 'is-playing');

        const playPromise = heroVideo.play();
        if (playPromise && typeof playPromise.catch === 'function') {
          playPromise.catch(function () {});
        }
      }

      heroVideo.addEventListener('loadeddata', bootHeroVideo, { once: true });
      heroVideo.addEventListener('playing', bootHeroVideo, { once: true });
      heroVideo.addEventListener('error', function () {
        heroVideo.style.display = 'none';
      });

      if (heroVideo.readyState >= 2) {
        bootHeroVideo();
      }
    }

    // Modern Card-Based Aircraft Slider Control
    let currentSlide = 0;
    let isDragging = false;
    let startX = 0;
    let scrollLeft = 0;
    const sliderTrack = document.getElementById('slider-track');
    const slides = document.querySelectorAll('#aircraft-gallery .slider-slide');
    const dots = document.querySelectorAll('#aircraft-gallery .slider-dot');
    const totalSlides = slides.length;
    let autoSlideInterval = null;
    const AUTO_SLIDE_DURATION = 3000; // 3 Sekunden pro Slide
    
    function updateSlider() {
      if (!sliderTrack || totalSlides === 0) return;

      const stepWidth = getSliderStepWidth();
      if (!stepWidth) return;
      const translateX = -currentSlide * stepWidth;
      
      sliderTrack.style.transform = `translateX(${translateX}px)`;
      
      // Active State aktualisieren
      slides.forEach((slide, index) => {
        if (index === currentSlide) {
          slide.classList.add('active');
        } else {
          slide.classList.remove('active');
        }
      });
      
      // Dots aktualisieren
      dots.forEach((dot, index) => {
        if (index === currentSlide) {
          dot.classList.add('active');
        } else {
          dot.classList.remove('active');
        }
      });
    }
    
    function showSlide(index) {
      // Auto-Loop: Wenn am Ende, zurÃ¼ck zum Anfang
      if (index < 0) {
        index = totalSlides - 1;
      } else if (index >= totalSlides) {
        index = 0; // Loop zum Anfang
      }
      
      currentSlide = index;
      updateSlider();
      resetAutoSlide();
    }
    
    function changeSlide(direction) {
      const newIndex = currentSlide + direction;
      showSlide(newIndex);
    }

    function getSliderStepWidth() {
      if (!sliderTrack || totalSlides === 0) return 0;
      const slideWidth = slides[0].getBoundingClientRect().width;
      const trackStyles = window.getComputedStyle(sliderTrack);
      const gap = parseFloat(trackStyles.columnGap || trackStyles.gap || '0');
      return slideWidth + gap;
    }
    
    function goToSlide(index) {
      showSlide(index);
    }
    
    // Touch/Mouse Drag Support
    if (sliderTrack) {
      // Mouse Events
      sliderTrack.addEventListener('mousedown', (e) => {
        isDragging = true;
        startX = e.pageX - sliderTrack.offsetLeft;
        scrollLeft = sliderTrack.scrollLeft;
        sliderTrack.style.cursor = 'grabbing';
        e.preventDefault();
      });
      
      sliderTrack.addEventListener('mouseleave', () => {
        isDragging = false;
        sliderTrack.style.cursor = 'grab';
      });
      
      sliderTrack.addEventListener('mouseup', () => {
        isDragging = false;
        sliderTrack.style.cursor = 'grab';
      });
      
      sliderTrack.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        e.preventDefault();
        const x = e.pageX - sliderTrack.offsetLeft;
        const walk = (x - startX) * 2;
        
        // Snap to nearest slide
        const slideWidth = getSliderStepWidth();
        const dragDistance = walk;
        
        if (Math.abs(dragDistance) > slideWidth / 3) {
          if (dragDistance > 0) {
            changeSlide(-1);
          } else {
            changeSlide(1);
          }
          isDragging = false;
        }
      });
      
      // Touch Events
      let touchStartX = 0;
      let touchEndX = 0;
      
      sliderTrack.addEventListener('touchstart', (e) => {
        touchStartX = e.changedTouches[0].screenX;
      });
      
      sliderTrack.addEventListener('touchend', (e) => {
        touchEndX = e.changedTouches[0].screenX;
        handleSwipe();
      });
      
      function handleSwipe() {
        const swipeThreshold = 50;
        const diff = touchStartX - touchEndX;
        
        if (Math.abs(diff) > swipeThreshold) {
          if (diff > 0) {
            changeSlide(1); // Swipe left = next
          } else {
            changeSlide(-1); // Swipe right = previous
          }
        }
      }
    }
    
    function startAutoSlide() {
      if (totalSlides > 0) {
        autoSlideInterval = setInterval(() => {
          changeSlide(1);
        }, AUTO_SLIDE_DURATION);
      }
    }
    
    function resetAutoSlide() {
      clearInterval(autoSlideInterval);
      startAutoSlide();
    }
    
    // Initialisierung
    if (totalSlides > 0) {
      updateSlider();
      startAutoSlide();
      
      // Pause bei Hover
      const slider = document.querySelector('#aircraft-gallery .aircraft-slider');
      if (slider) {
        slider.addEventListener('mouseenter', () => {
          clearInterval(autoSlideInterval);
        });
        
        slider.addEventListener('mouseleave', () => {
          startAutoSlide();
        });
      }
      
      // Keyboard Navigation
      document.addEventListener('keydown', (e) => {
        const sliderSection = document.getElementById('aircraft-gallery');
        if (sliderSection) {
          const rect = sliderSection.getBoundingClientRect();
          const isVisible = rect.top < window.innerHeight && rect.bottom > 0;
          
          if (isVisible && e.key === 'ArrowLeft') {
            e.preventDefault();
            changeSlide(-1);
          } else if (isVisible && e.key === 'ArrowRight') {
            e.preventDefault();
            changeSlide(1);
          }
        }
      });
      
      // Resize Handler
      window.addEventListener('resize', () => {
        updateSlider();
      });
    }

    // Scroll to Top Button
    function initScrollToTop() {
      const scrollToTopBtn = document.getElementById('scroll-to-top');
      
      if (!scrollToTopBtn) {
        console.warn('Scroll to Top Button nicht gefunden');
        return;
      }
      
      function toggleScrollToTop() {
        if (window.scrollY > 300) {
          scrollToTopBtn.classList.add('visible');
        } else {
          scrollToTopBtn.classList.remove('visible');
        }
      }
      
      scrollToTopBtn.addEventListener('click', () => {
        window.scrollTo({
          top: 0,
          behavior: 'smooth'
        });
      });
      
      window.addEventListener('scroll', toggleScrollToTop, { passive: true });
      toggleScrollToTop(); // Initial check
    }
    
    // Initialisierung wenn DOM bereit ist
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initScrollToTop);
    } else {
      initScrollToTop();
    }
    
    // KOMMENTAR: Cookie Consent Banner hier implementieren
    // KOMMENTAR: Matomo Analytics hier einbinden (ohne Cookies mÃ¶glich)
    /*
    var _paq = window._paq = window._paq || [];
    _paq.push(['trackPageView']);
    _paq.push(['enableLinkTracking']);
    (function() {
      var u="//analytics.example.com/";
      _paq.push(['setTrackerUrl', u+'matomo.php']);
      _paq.push(['setSiteId', '1']);
      var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
      g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
    })();
    */

