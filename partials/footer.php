<?php
if (!function_exists('push_vapid') && file_exists(__DIR__ . '/../push.php')) {
    require_once __DIR__ . '/../push.php';
}
require_once __DIR__ . '/onboarding.php';
?>
<script>
// (Dark mode + user menu handled inside the icon-strip in nav.php)

// (mobile hamburger removed — using corner icon-strip instead)

// ── Scroll-fade for sections (IntersectionObserver) ──────
(function() {
  if (!('IntersectionObserver' in window)) return;

  // Auto-tag major sections after the first viewport for fade-in on scroll
  const candidates = document.querySelectorAll(
    'main > .dash-grid, main > .stats-grid, main > .quick-grid, ' +
    'main > section, main .card, main > div[style*="border-top"]'
  );

  const io = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.classList.add('visible');
        io.unobserve(e.target);
      }
    });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });

  candidates.forEach((el, i) => {
    const rect = el.getBoundingClientRect();
    // Only fade-in elements that start out below the fold
    if (rect.top > window.innerHeight * 0.9) {
      el.classList.add('io-fade-in');
      io.observe(el);
    }
  });
})();

// ── iOS standalone: keep nav in-app ──────────────────────
if (window.navigator.standalone) {
  document.body.addEventListener('click', function(e) {
    let t = e.target;
    while (t && t.nodeName !== 'A') t = t.parentNode;
    if (t && t.nodeName === 'A') {
      const href = t.getAttribute('href');
      if (href && !/^(http|#|mailto)/.test(href)) {
        e.preventDefault();
        window.location = href;
      }
    }
  }, false);
}
</script>
</body>
</html>
