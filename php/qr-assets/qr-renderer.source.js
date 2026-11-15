/*
 * Enhanced PHP pages only. Build the committed browser bundle after changing
 * this source:
 *
 *   ./node_modules/.bin/esbuild php/qr-assets/qr-renderer.source.js \
 *     --bundle --format=iife --platform=browser --target=es2018 --minify \
 *     --outfile=php/qr-assets/qr-renderer.js
 *
 * `qrcode` is already a client workspace dependency. This bundle has no
 * runtime network request and is kept out of the traditional /base/ site.
 */
import QRCode from 'qrcode';

async function renderQr(element) {
  if (element.querySelector('img[data-generated-qr]')) return;

  const value = element.getAttribute('data-qr-value')?.trim();
  if (!value) return;

  try {
    const svg = await QRCode.toString(value, {
      type: 'svg',
      errorCorrectionLevel: 'M',
      margin: 4,
      color: { dark: '#101827', light: '#FFFFFF' },
    });
    const image = document.createElement('img');
    const label = element.getAttribute('data-qr-label')?.trim() || 'code';
    image.alt = `QR code for ${label}: ${value}`;
    image.width = 208;
    image.height = 208;
    image.decoding = 'async';
    image.setAttribute('data-generated-qr', '');
    image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    element.prepend(image);
    element.classList.add('qr-rendered');
  } catch (_error) {
    // Keep the server-rendered reference or ticket code readable if QR fails.
  }
}

function renderPageQrs() {
  document.querySelectorAll('[data-qr-value]').forEach((element) => {
    void renderQr(element);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', renderPageQrs, { once: true });
} else {
  renderPageQrs();
}
