const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

const reveal = new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (!entry.isIntersecting) return;
    entry.target.classList.add('visible');
    reveal.unobserve(entry.target);
  });
}, { threshold: 0.13 });

document.querySelectorAll('.reveal').forEach((el, index) => {
  if (!reduceMotion) el.style.transitionDelay = `${Math.min(index % 3, 2) * 70}ms`;
  reveal.observe(el);
});

const process = document.querySelector('.process');
if (process) new IntersectionObserver(([entry]) => {
  if (entry.isIntersecting) process.classList.add('in-view');
}, { threshold: 0.3 }).observe(process);

const header = document.querySelector('[data-header]');
addEventListener('scroll', () => header.classList.toggle('scrolled', scrollY > 40), { passive: true });

if (!reduceMotion && matchMedia('(pointer: fine)').matches) {
  document.querySelectorAll('.magnetic').forEach((button) => {
    let x = 0, y = 0, vx = 0, vy = 0, targetX = 0, targetY = 0, frame;
    const tick = () => {
      vx += (targetX - x) * .14; vy += (targetY - y) * .14;
      vx *= .68; vy *= .68; x += vx; y += vy;
      button.style.setProperty('--tx', `${x}px`); button.style.setProperty('--ty', `${y}px`);
      if (Math.abs(targetX - x) + Math.abs(targetY - y) > .08) frame = requestAnimationFrame(tick); else frame = null;
    };
    const move = (event) => {
      const rect = button.getBoundingClientRect();
      targetX = (event.clientX - rect.left - rect.width / 2) * .12;
      targetY = (event.clientY - rect.top - rect.height / 2) * .12;
      if (!frame) frame = requestAnimationFrame(tick);
    };
    const reset = () => { targetX = 0; targetY = 0; if (!frame) frame = requestAnimationFrame(tick); };
    button.addEventListener('pointermove', move);
    button.addEventListener('pointerleave', reset);
    button.addEventListener('pointercancel', reset);
  });
}
