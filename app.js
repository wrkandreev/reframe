(() => {
  const lightbox = document.getElementById('lightbox');
  const lightboxImage = document.getElementById('lightboxImage');
  const lightboxTitle = document.getElementById('lightboxTitle');

  if (!lightbox || !lightboxImage || !lightboxTitle) {
    return;
  }

  document.addEventListener('contextmenu', (e) => {
    e.preventDefault();
  });

  document.addEventListener('dragstart', (e) => {
    e.preventDefault();
  });

  document.addEventListener('keydown', (e) => {
    const key = e.key.toLowerCase();
    if ((e.ctrlKey || e.metaKey) && (key === 's' || key === 'u' || key === 'p')) {
      e.preventDefault();
    }

    if (key === 'f12') {
      e.preventDefault();
    }

    if (e.key === 'Escape' && !lightbox.hidden) {
      closeLightbox();
    }
  });

  document.querySelectorAll('.js-thumb').forEach((button) => {
    button.addEventListener('click', () => {
      const full = button.dataset.full;
      const title = button.dataset.title || 'Фото';
      if (!full) return;

      lightboxImage.src = full;
      lightboxImage.alt = title;
      lightboxTitle.textContent = title;
      lightbox.hidden = false;
      document.body.style.overflow = 'hidden';
    });
  });

  lightbox.querySelectorAll('.js-close').forEach((el) => {
    el.addEventListener('click', closeLightbox);
  });

  function closeLightbox() {
    lightbox.hidden = true;
    lightboxImage.src = '';
    lightboxTitle.textContent = '';
    document.body.style.overflow = '';
  }
})();
