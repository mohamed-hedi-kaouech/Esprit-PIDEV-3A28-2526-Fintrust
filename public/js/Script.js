/* =============================================
   FINTRUST — script.js
   ============================================= */
document.addEventListener('DOMContentLoaded', () => {

  /* ──────────────────────────────────────────
     MODAL SYSTEM
  ────────────────────────────────────────── */
  const loginModal  = document.getElementById('loginModal');
  const signupModal = document.getElementById('signupModal');

  function openModal(modal) {
    if (!modal) return;
    // Close any other open modal first
    document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('open');
    document.body.style.overflow = '';
  }

  function closeAllModals() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
    document.body.style.overflow = '';
  }

  // Login triggers
  // Close buttons
  document.getElementById('closeLogin')?.addEventListener('click',  () => closeModal(loginModal));
  document.getElementById('closeSignup')?.addEventListener('click', () => closeModal(signupModal));

  // Switch between modals
  document.getElementById('switchToSignup')?.addEventListener('click', (e) => {
    e.preventDefault();
    closeModal(loginModal);
    setTimeout(() => openModal(signupModal), 150);
  });
  document.getElementById('switchToLogin')?.addEventListener('click', (e) => {
    e.preventDefault();
    closeModal(signupModal);
    setTimeout(() => openModal(loginModal), 150);
  });

  // Close on overlay click
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeAllModals();
    });
  });

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeAllModals();
  });

  /* ──────────────────────────────────────────
     NAVBAR: SCROLL BEHAVIOR & MOBILE MENU
  ────────────────────────────────────────── */
  const navbar    = document.getElementById('navbar');
  const hamburger = document.getElementById('hamburger');
  const mobileMenu = document.getElementById('mobileMenu');

  // Scrolled class for shadow
  window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 40);
  }, { passive: true });

  // Hamburger toggle
  hamburger?.addEventListener('click', () => {
    const isOpen = mobileMenu.classList.toggle('open');
    hamburger.setAttribute('aria-expanded', isOpen);
    // Animate hamburger lines
    const spans = hamburger.querySelectorAll('span');
    if (isOpen) {
      spans[0].style.cssText = 'transform: translateY(7px) rotate(45deg)';
      spans[1].style.cssText = 'opacity: 0; transform: scaleX(0)';
      spans[2].style.cssText = 'transform: translateY(-7px) rotate(-45deg)';
    } else {
      spans.forEach(s => s.style.cssText = '');
    }
  });

  // Close mobile menu on link click
  mobileMenu?.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
      mobileMenu.classList.remove('open');
      hamburger.querySelectorAll('span').forEach(s => s.style.cssText = '');
    });
  });


  /* ──────────────────────────────────────────
     SMOOTH SCROLL FOR NAV LINKS
  ────────────────────────────────────────── */
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      const target = document.querySelector(this.getAttribute('href'));
      if (target) {
        e.preventDefault();
        const offset = 80; // navbar height
        const top = target.getBoundingClientRect().top + window.scrollY - offset;
        window.scrollTo({ top, behavior: 'smooth' });
      }
    });
  });


  /* ──────────────────────────────────────────
     SCROLL REVEAL ANIMATIONS
  ────────────────────────────────────────── */
  const revealElements = document.querySelectorAll(
    '.feature-card, .step, .testimonial-card, .pricing-card, .smart-card, .security-card, .impact-card, .dashboard-window, .dashboard-point'
  );

  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry, idx) => {
      if (entry.isIntersecting) {
        // Stagger delay based on sibling index
        const siblings = Array.from(entry.target.parentElement?.children || []);
        const delay = siblings.indexOf(entry.target) * 80;
        setTimeout(() => {
          entry.target.style.opacity = '1';
          entry.target.style.transform = entry.target.style.transform.replace('translateY(30px)', 'translateY(0)');
          entry.target.classList.add('revealed');
        }, delay);
        revealObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12 });

  revealElements.forEach(el => {
    el.style.opacity   = '0';
    el.style.transform = (el.style.transform || '') + ' translateY(30px)';
    el.style.transition = 'opacity 0.55s ease, transform 0.55s ease, box-shadow 0.25s ease';
    revealObserver.observe(el);
  });


  /* ──────────────────────────────────────────
     COUNTER ANIMATION (hero stats)
  ────────────────────────────────────────── */
  function animateCounter(el, target, suffix = '', prefix = '') {
    const duration = 1800;
    const start    = performance.now();
    const isFloat  = target % 1 !== 0;

    function step(now) {
      const elapsed  = Math.min(now - start, duration);
      const progress = easeOutCubic(elapsed / duration);
      const current  = progress * target;
      el.textContent = prefix + (isFloat ? current.toFixed(1) : Math.floor(current)) + suffix;
      if (elapsed < duration) requestAnimationFrame(step);
      else el.textContent = prefix + target + suffix;
    }
    requestAnimationFrame(step);
  }

  function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }

  const statsSection = document.querySelector('.hero-stats');
  let statsAnimated  = false;
  const statsObserver = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && !statsAnimated) {
      statsAnimated = true;
      const statNums = document.querySelectorAll('.stat-num');
      // [0] = $0, [1] = 4.9*, [2] = 2M+
      // We skip $0 (it's already 0), animate the others
      if (statNums[1]) animateCounter(statNums[1], 4.9, '*');
      if (statNums[2]) animateCounter(statNums[2], 2, 'M+');
      statsObserver.disconnect();
    }
  }, { threshold: 0.5 });
  if (statsSection) statsObserver.observe(statsSection);

  const impactSection = document.querySelector('.impact-stats');
  let impactAnimated = false;
  const impactObserver = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && !impactAnimated) {
      impactAnimated = true;
      document.querySelectorAll('.impact-number').forEach((el) => {
        const target = Number(el.dataset.target || '0');
        const suffix = el.dataset.suffix || '';
        const prefix = el.dataset.prefix || '';
        animateCounter(el, target, suffix, prefix);
      });
      impactObserver.disconnect();
    }
  }, { threshold: 0.35 });
  if (impactSection) impactObserver.observe(impactSection);

  const heroBadge = document.querySelector('[data-hero-badge]');
  if (heroBadge) {
    const badgeMessages = [
      'AI Powered Banking',
      'Fraud Detection In Real Time',
      'Secure KYC And Smart Insights',
      'Built For Trust And Conversion'
    ];
    let badgeIndex = Math.floor(Math.random() * badgeMessages.length);

    const setBadgeMessage = (message) => {
      heroBadge.innerHTML = '<span class="badge-dot"></span> ' + message;
    };

    setBadgeMessage(badgeMessages[badgeIndex]);

    setInterval(() => {
      badgeIndex = (badgeIndex + 1) % badgeMessages.length;
      heroBadge.style.opacity = '0';
      heroBadge.style.transform = 'translateY(-6px)';

      setTimeout(() => {
        setBadgeMessage(badgeMessages[badgeIndex]);
        heroBadge.style.opacity = '1';
        heroBadge.style.transform = 'translateY(0)';
      }, 220);
    }, 4500);
  }


  /* ──────────────────────────────────────────
     TOAST NOTIFICATION SYSTEM
  ────────────────────────────────────────── */
  function showToast(message, type = 'info') {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;

    const colors = {
      success: '#22c55e',
      error:   '#ef4444',
      info:    '#1560BD',
    };

    Object.assign(toast.style, {
      position:     'fixed',
      bottom:       '24px',
      right:        '24px',
      background:   colors[type] || colors.info,
      color:        '#fff',
      padding:      '12px 20px',
      borderRadius: '12px',
      fontFamily:   'DM Sans, sans-serif',
      fontSize:     '0.9rem',
      fontWeight:   '500',
      boxShadow:    '0 8px 24px rgba(0,0,0,0.18)',
      zIndex:       '9999',
      opacity:      '0',
      transform:    'translateY(12px)',
      transition:   'all 0.3s cubic-bezier(0.4,0,0.2,1)',
      maxWidth:     '320px',
      lineHeight:   '1.4',
    });

    document.body.appendChild(toast);
    requestAnimationFrame(() => {
      toast.style.opacity   = '1';
      toast.style.transform = 'translateY(0)';
    });

    setTimeout(() => {
      toast.style.opacity   = '0';
      toast.style.transform = 'translateY(12px)';
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

  // Expose globally for optional use
  window.showToast = showToast;


  /* ──────────────────────────────────────────
     FORM SUBMISSION STUBS
  ────────────────────────────────────────── */
  function handleFormSubmit(modal, successMsg) {
    const btn = modal?.querySelector('.btn-modal-primary');
    if (!btn) return;

    btn.addEventListener('click', () => {
      const inputs = modal.querySelectorAll('.form-input');
      let valid = true;

      inputs.forEach(input => {
        input.style.borderColor = '';
        if (!input.value.trim()) {
          input.style.borderColor = '#ef4444';
          valid = false;
        }
      });

      // Email validation
      const emailInput = modal.querySelector('input[type="email"]');
      if (emailInput && emailInput.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value)) {
        emailInput.style.borderColor = '#ef4444';
        valid = false;
        showToast('Please enter a valid email address.', 'error');
        return;
      }

      if (!valid) {
        showToast('Please fill in all required fields.', 'error');
        return;
      }

      // Simulate loading state
      const originalText = btn.textContent;
      btn.textContent = 'Processing…';
      btn.disabled    = true;
      btn.style.opacity = '0.7';

      setTimeout(() => {
        btn.textContent  = originalText;
        btn.disabled     = false;
        btn.style.opacity = '';
        closeAllModals();
        showToast(successMsg, 'success');
        // Reset form
        inputs.forEach(input => input.value = '');
      }, 1400);
    });
  }

  handleFormSubmit(loginModal,  '✅ Welcome back! Logging you in…');
  handleFormSubmit(signupModal, '🎉 Account created! Welcome to FinTrust!');


  /* ──────────────────────────────────────────
     ACTIVE NAV LINK (scroll spy)
  ────────────────────────────────────────── */
  const sections = document.querySelectorAll('section[id]');
  const navLinks = document.querySelectorAll('.nav-links a');

  const spyObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const id = entry.target.getAttribute('id');
        navLinks.forEach(link => {
          link.classList.toggle('active', link.getAttribute('href') === `#${id}`);
        });
      }
    });
  }, { rootMargin: '-40% 0px -55% 0px' });

  sections.forEach(s => spyObserver.observe(s));

  // Add active link styling dynamically
  const style = document.createElement('style');
  style.textContent = `.nav-links a.active { color: var(--blue-600) !important; font-weight: 600; }`;
  document.head.appendChild(style);


  /* ──────────────────────────────────────────
     CARD TILT EFFECT (hero card mockup)
  ────────────────────────────────────────── */
  const heroVisual = document.querySelector('.hero-visual');
  if (heroVisual) {
    heroVisual.addEventListener('mousemove', (e) => {
      const rect = heroVisual.getBoundingClientRect();
      const x = ((e.clientX - rect.left) / rect.width  - 0.5) * 14;
      const y = ((e.clientY - rect.top)  / rect.height - 0.5) * -14;
      heroVisual.style.transform = `translateY(-50%) rotateY(${x}deg) rotateX(${y}deg)`;
      heroVisual.style.transition = 'transform 0.1s ease';
    });
    heroVisual.addEventListener('mouseleave', () => {
      heroVisual.style.transform = 'translateY(-50%)';
      heroVisual.style.transition = 'transform 0.5s ease';
    });
  }


  /* ──────────────────────────────────────────
     INIT COMPLETE
  ────────────────────────────────────────── */
  console.log('%c FinTrust 🏦 ', 'background:#1560BD;color:#fff;padding:4px 10px;border-radius:6px;font-weight:bold;', '— Ready');

}); // end DOMContentLoaded
