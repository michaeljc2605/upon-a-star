import { Html5QrcodeScanner, Html5QrcodeScanType } from 'html5-qrcode';

const button = document.getElementById('staff-scan-camera');
const reader = document.getElementById('staff-qr-reader');
const status = document.getElementById('staff-scan-status');
const scanForm = document.getElementById('staff-scan-form');

if (button && reader && status && scanForm) {
  let scanner = null;
  button.addEventListener('click', async () => {
    if (scanner) {
      const current = scanner;
      scanner = null;
      await current.clear().catch(() => {});
      reader.hidden = true;
      status.textContent = 'Camera scan stopped.';
      button.textContent = 'Scan QR';
      return;
    }

    reader.hidden = false;
    status.textContent = 'Choose a QR image, or switch to camera and allow access.';
    button.textContent = 'Close scanner';
    scanner = new Html5QrcodeScanner('staff-qr-reader', {
      fps: 10,
      qrbox: { width: 240, height: 240 },
      supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_FILE, Html5QrcodeScanType.SCAN_TYPE_CAMERA],
      rememberLastUsedCamera: false,
    }, false);
    scanner.render(async (code) => {
      const current = scanner;
      scanner = null;
      scanForm.querySelector('[name="code"]').value = code;
      status.textContent = code.startsWith('SVT-') ? 'Paid ticket scanned. Checking entry…' : 'Reservation scanned. Looking it up…';
      await current.clear().catch(() => {});
      reader.hidden = true;
      scanForm.requestSubmit();
    }, () => {});
  });
}
