import { isIP } from 'node:net';

function normalizeAddress(value) {
  if (typeof value !== 'string') return '';
  const address = value.trim().toLowerCase();
  const mapped = address.match(/^::ffff:(\d+\.\d+\.\d+\.\d+)$/);
  if (mapped && isIP(mapped[1]) === 4) return mapped[1];
  if (isIP(address) === 6) return new URL(`http://[${address}]/`).hostname.slice(1, -1);
  return isIP(address) === 4 ? address : '';
}

/** Trust the private Apache connection, then only a configured upstream proxy. */
export function buildProxyTrust(env = process.env) {
  const upstream = new Set();
  for (const value of String(env.TRUSTED_PROXY_IPS || '').split(',').map((item) => item.trim()).filter(Boolean)) {
    const address = normalizeAddress(value);
    if (!address) throw new Error('TRUSTED_PROXY_IPS must contain only explicit IP addresses, not hostnames, ranges or hop counts.');
    upstream.add(address);
  }
  return (value, hop) => {
    const address = normalizeAddress(value);
    if (hop === 0) return address === '127.0.0.1' || address === '::1';
    if (hop === 1) return upstream.has(address);
    return false;
  };
}
