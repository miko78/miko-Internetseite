/* ============================================
   miko® GmbH – Gemeinsames JavaScript
   Navigation, Scroll, Animationen, Formulare
   ============================================ */

(function() {
  'use strict';

  // ── Hero Slider ──
  var heroSlider = document.getElementById('heroSlider');
  if (heroSlider) {
    var slides = heroSlider.querySelectorAll('.hero-slide');
    var dots = heroSlider.querySelectorAll('.hero-dot');
    var currentSlide = 0;
    var slideInterval = 5000; // 5 Sekunden pro Slide
    var autoSlideTimer;

    function goToSlide(index) {
      slides[currentSlide].classList.remove('hero-slide--active');
      dots[currentSlide].classList.remove('hero-dot--active');
      currentSlide = index;
      slides[currentSlide].classList.add('hero-slide--active');
      dots[currentSlide].classList.add('hero-dot--active');
    }

    function nextSlide() {
      goToSlide((currentSlide + 1) % slides.length);
    }

    function startAutoSlide() {
      autoSlideTimer = setInterval(nextSlide, slideInterval);
    }

    // Dots klickbar
    dots.forEach(function(dot, i) {
      dot.addEventListener('click', function() {
        clearInterval(autoSlideTimer);
        goToSlide(i);
        startAutoSlide();
      });
    });

    startAutoSlide();
  }

  // ── Dropdown-Menü ──
  document.querySelectorAll('.nav-dropdown-toggle').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      btn.parentElement.classList.toggle('open');
    });
  });
  document.addEventListener('click', function() {
    document.querySelectorAll('.nav-dropdown.open').forEach(function(d) {
      d.classList.remove('open');
    });
  });

  // ── Mobile Navigation (Hamburger) ──
  var navToggle = document.getElementById('navToggle');
  var navLinks = document.getElementById('navLinks');

  function setMenuIcon(isOpen) {
    var s = navToggle.querySelectorAll('span');
    if (isOpen) {
      s[0].style.transform = 'rotate(45deg) translate(5px, 5px)';
      s[1].style.opacity = '0';
      s[2].style.transform = 'rotate(-45deg) translate(5px, -5px)';
    } else {
      s[0].style.transform = '';
      s[1].style.opacity = '';
      s[2].style.transform = '';
    }
  }

  function toggleMenu(open) {
    var isOpen = typeof open === 'boolean' ? open : !navLinks.classList.contains('active');
    navLinks.classList.toggle('active', isOpen);
    document.body.style.overflow = isOpen ? 'hidden' : '';
    setMenuIcon(isOpen);
  }

  if (navToggle && navLinks) {
    navToggle.addEventListener('click', function(e) {
      e.stopPropagation();
      toggleMenu();
    });

    // Menü schließen bei Klick auf Link
    document.querySelectorAll('.nav-links a').forEach(function(l) {
      l.addEventListener('click', function() {
        toggleMenu(false);
      });
    });
  }

  // ── Header Scroll-Effekt ──
  var header = document.getElementById('header');
  if (header) {
    window.addEventListener('scroll', function() {
      header.classList.toggle('scrolled', window.scrollY > 50);
    }, { passive: true });
  }

  // ── Smooth Scroll (Anker-Links) ──
  document.querySelectorAll('a[href^="#"]').forEach(function(a) {
    a.addEventListener('click', function(e) {
      e.preventDefault();
      var target = document.querySelector(this.getAttribute('href'));
      if (target) {
        var headerHeight = header ? header.offsetHeight : 0;
        window.scrollTo({
          top: target.getBoundingClientRect().top + window.pageYOffset - headerHeight,
          behavior: 'smooth'
        });
      }
    });
  });

  // ── Scroll-Animationen (Fade-In) ──
  var animSelectors = [
    '.service-card', '.product-card', '.step', '.contact-detail',
    '.section-header', '.about-text', '.about-logo-showcase',
    '.intrastore-text', '.intrastore-logo-showcase',
    '.cta-section .container',
    '.ak-feature', '.ak-area', '.ak-gallery-item', '.wa-gallery-item',
    '.katalog-card', '.ak-hero-content', '.ak-brands',
    '.intra-feature-card'
  ].join(', ');

  var animElements = document.querySelectorAll(animSelectors);
  if (animElements.length > 0) {
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
        }
      });
    }, { threshold: 0.05, rootMargin: '0px 0px 50px 0px' });

    animElements.forEach(function(el) {
      el.style.opacity = '0';
      el.style.transform = 'translateY(15px)';
      el.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
      observer.observe(el);
    });
  }

  // ── Timeline Scroll-Effekt (Veredelung) ──
  var timeline = document.getElementById('timeline');
  if (timeline) {
    var fill = timeline.querySelector('.timeline-track-fill');
    var items = timeline.querySelectorAll('.timeline-item');

    function updateTimeline() {
      var rect = timeline.getBoundingClientRect();
      var trigger = window.innerHeight * 0.55;
      var progress = Math.min(Math.max((trigger - rect.top) / rect.height, 0), 1);
      fill.style.height = (progress * 100) + '%';

      items.forEach(function(item) {
        if (item.getBoundingClientRect().top < trigger) {
          item.classList.add('timeline-active');
        }
      });
    }

    window.addEventListener('scroll', updateTimeline, { passive: true });
    window.addEventListener('resize', updateTimeline);
    updateTimeline();
  }

  // ── Kontaktformular AJAX ──
  var form = document.getElementById('contactForm');
  if (form) {
    var submitBtn = document.getElementById('submitBtn');
    var feedback = document.getElementById('formFeedback');

    // Signiertes Token beim Laden der Seite holen. Der darin enthaltene
    // Zeitstempel ist die Basis f\u00fcr die serverseitige Zeitfenster-Pr\u00fcfung
    // (fr\u00fchestens nach X Sek., sp\u00e4testens vor Ablauf).
    var formToken = null;
    function fetchFormToken() {
      return fetch('contact.php?action=token', { method: 'GET', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d && d.token) { formToken = d.token; } })
        .catch(function() { /* beim Absenden wird erneut versucht */ });
    }
    fetchFormToken();

    function showError(msg) {
      feedback.style.display = 'block';
      feedback.style.color = 'var(--red)';
      feedback.textContent = msg;
    }

    form.addEventListener('submit', function(e) {
      e.preventDefault();

      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      // Token fehlt (z. B. Abruf beim Laden fehlgeschlagen) -> einmal nachholen.
      if (!formToken) {
        showError('Sicherheitspr\u00fcfung wird vorbereitet \u2026 bitte senden Sie in einem Moment erneut.');
        fetchFormToken();
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Wird gesendet\u2026';
      feedback.style.display = 'none';

      var fd = new FormData(form);
      fd.append('form_token', formToken);

      fetch('contact.php', {
        method: 'POST',
        body: fd
      })
      .then(function(r) { return r.json(); })
      .then(function(result) {
        feedback.style.display = 'block';
        if (result.success) {
          feedback.style.color = '#16a34a';
          feedback.textContent = result.message;
          form.reset();
          // Verbrauchtes Token entwerten und ein frisches holen
          // (Einmal-Token / Replay-Schutz).
          formToken = null;
          fetchFormToken();
        } else {
          feedback.style.color = 'var(--red)';
          feedback.textContent = result.message;
          formToken = null;
          fetchFormToken();
        }
      })
      .catch(function() {
        showError('Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut oder schreiben Sie uns direkt an info@miko-online.com.');
        formToken = null;
        fetchFormToken();
      })
      .finally(function() {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Nachricht senden';
      });
    });
  }

  // ── Container Scroll 3D Effect (Arbeitskleidung) ──
  var scrollSection = document.getElementById('einsatzbereiche-scroll');
  if (scrollSection) {
    var card = scrollSection.querySelector('.scroll-container-card');
    if (card) {
      function updateScroll() {
        var rect = scrollSection.getBoundingClientRect();
        var vh = window.innerHeight;
        var progress = Math.min(Math.max((vh - rect.top) / (vh + rect.height * 0.5), 0), 1);
        card.style.setProperty('--scroll-rotate', (15 * (1 - progress)) + 'deg');
        card.style.setProperty('--scroll-scale', 0.92 + 0.08 * progress);
      }
      window.addEventListener('scroll', updateScroll, { passive: true });
      window.addEventListener('resize', updateScroll);
      updateScroll();
    }
  }

  // ── Lightbox (Doppelklick auf Galerie-Bilder) ──
  var galleryItems = document.querySelectorAll('.wa-gallery-item, .ak-gallery-item');
  if (galleryItems.length > 0) {
    // Lightbox-DOM erstellen
    var overlay = document.createElement('div');
    overlay.className = 'lightbox-overlay';
    overlay.innerHTML =
      '<button class="lightbox-close" aria-label="Schließen">&times;</button>' +
      '<button class="lightbox-nav lightbox-prev" aria-label="Vorheriges Bild">&#8249;</button>' +
      '<img src="" alt="">' +
      '<button class="lightbox-nav lightbox-next" aria-label="Nächstes Bild">&#8250;</button>';
    document.body.appendChild(overlay);

    var lbImg = overlay.querySelector('img');
    var lbClose = overlay.querySelector('.lightbox-close');
    var lbPrev = overlay.querySelector('.lightbox-prev');
    var lbNext = overlay.querySelector('.lightbox-next');
    var allImages = [];
    var currentIdx = 0;

    // Alle Galerie-Bilder sammeln
    galleryItems.forEach(function(item) {
      var img = item.querySelector('img');
      if (img) allImages.push(img);
    });

    function openLightbox(idx) {
      if (idx < 0 || idx >= allImages.length) return;
      currentIdx = idx;
      lbImg.src = allImages[idx].src;
      lbImg.alt = allImages[idx].alt;
      overlay.classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
      overlay.classList.remove('active');
      document.body.style.overflow = '';
    }

    function showNext() {
      openLightbox((currentIdx + 1) % allImages.length);
    }

    function showPrev() {
      openLightbox((currentIdx - 1 + allImages.length) % allImages.length);
    }

    // Doppelklick öffnet Lightbox
    galleryItems.forEach(function(item, i) {
      item.addEventListener('dblclick', function(e) {
        e.preventDefault();
        // Index im allImages-Array finden
        var img = item.querySelector('img');
        var idx = allImages.indexOf(img);
        if (idx !== -1) openLightbox(idx);
      });
    });

    // Schließen
    lbClose.addEventListener('click', function(e) {
      e.stopPropagation();
      closeLightbox();
    });
    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) closeLightbox();
    });

    // Navigation
    lbPrev.addEventListener('click', function(e) { e.stopPropagation(); showPrev(); });
    lbNext.addEventListener('click', function(e) { e.stopPropagation(); showNext(); });

    // Tastatur-Steuerung
    document.addEventListener('keydown', function(e) {
      if (!overlay.classList.contains('active')) return;
      if (e.key === 'Escape') closeLightbox();
      if (e.key === 'ArrowRight') showNext();
      if (e.key === 'ArrowLeft') showPrev();
    });
  }

  // ── Scroll-to-top Button ──
  var scrollTopBtn = document.getElementById('scrollTopBtn');
  if (scrollTopBtn) {
    var toggleScrollTop = function() {
      if (window.pageYOffset > 400) scrollTopBtn.classList.add('visible');
      else scrollTopBtn.classList.remove('visible');
    };
    window.addEventListener('scroll', toggleScrollTop, { passive: true });
    toggleScrollTop();
    scrollTopBtn.addEventListener('click', function() {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

})();
