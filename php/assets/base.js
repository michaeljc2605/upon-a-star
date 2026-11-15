/* Small progressive enhancement for the server-rendered featured-film carousel. */
(() => {
  const carousel = document.querySelector('[data-hero-carousel]');
  if (!carousel) return;
  const slides = Array.from(carousel.querySelectorAll('[data-hero-slide]'));
  if (slides.length < 2) return;
  let current = 0;
  let timer;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  function show(index) {
    current = (index + slides.length) % slides.length;
    slides.forEach((slide, slideIndex) => {
      slide.hidden = slideIndex !== current;
      slide.querySelectorAll('[data-slide-index]').forEach((dot) => {
        const active = Number(dot.dataset.slideIndex) === current;
        dot.classList.toggle('active', active);
        if (active) dot.setAttribute('aria-current', 'true');
        else dot.removeAttribute('aria-current');
      });
    });
  }

  function startTimer() {
    window.clearInterval(timer);
    if (!reducedMotion.matches) timer = window.setInterval(() => show(current + 1), 8000);
  }

  carousel.addEventListener('click', (event) => {
    const dot = event.target.closest('[data-slide-index]');
    const arrow = event.target.closest('[data-slide-direction]');
    if (!dot && !arrow) return;
    if (dot) show(Number(dot.dataset.slideIndex));
    else show(current + Number(arrow.dataset.slideDirection));
    startTimer();
  });
  carousel.addEventListener('mouseenter', () => window.clearInterval(timer));
  carousel.addEventListener('mouseleave', startTimer);
  carousel.addEventListener('focusin', () => window.clearInterval(timer));
  carousel.addEventListener('focusout', (event) => {
    if (!carousel.contains(event.relatedTarget)) startTimer();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) window.clearInterval(timer);
    else startTimer();
  });
  reducedMotion.addEventListener('change', startTimer);
  show(0);
  startTimer();
})();
