const QRCode = require('qrcode');

let value = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { value += chunk; });
process.stdin.on('end', async () => {
  if (!/^SV-[A-F0-9]{24}$/.test(value)) {
    process.exitCode = 1;
    return;
  }
  try {
    const png = await QRCode.toDataURL(value, {
      errorCorrectionLevel: 'Q',
      type: 'image/png',
      width: 320,
      margin: 4,
      color: { dark: '#101827', light: '#FFFFFF' },
    });
    process.stdout.write(png);
  } catch {
    process.exitCode = 1;
  }
});
