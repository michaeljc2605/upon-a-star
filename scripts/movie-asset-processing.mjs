import { execFile } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const run = promisify(execFile);
const cwebpBinary = process.env.CWEBP_BINARY || '/opt/homebrew/bin/cwebp';
const targets = {
  poster: { width: 600, height: 900 },
  backdrop: { width: 1600, height: 900 },
};

async function imageDimensions(inputUrl) {
  const { stdout } = await run('/usr/bin/sips', ['-g', 'pixelWidth', '-g', 'pixelHeight', fileURLToPath(inputUrl)]);
  const width = Number(stdout.match(/pixelWidth:\s*(\d+)/)?.[1]);
  const height = Number(stdout.match(/pixelHeight:\s*(\d+)/)?.[1]);
  if (!width || !height) throw new Error(`Could not read artwork dimensions for ${fileURLToPath(inputUrl)}.`);
  return { width, height };
}

const evenSize = (value) => Math.max(2, Math.floor(value / 2) * 2);
const evenOffset = (value) => Math.max(0, Math.floor(value / 2) * 2);

export async function normalizeArtwork(inputUrl, outputUrl, kind) {
  const target = targets[kind];
  if (!target) throw new Error(`Unknown artwork kind: ${kind}`);
  const source = await imageDimensions(inputUrl);
  const sourceRatio = source.width / source.height;
  const targetRatio = target.width / target.height;
  let cropWidth = source.width;
  let cropHeight = source.height;
  let cropX = 0;
  let cropY = 0;
  if (sourceRatio > targetRatio) {
    cropWidth = evenSize(source.height * targetRatio);
    cropX = evenOffset((source.width - cropWidth) / 2);
  } else if (sourceRatio < targetRatio) {
    cropHeight = evenSize(source.width / targetRatio);
    cropY = evenOffset((source.height - cropHeight) / 2);
  }
  await run(cwebpBinary, [
    '-quiet',
    '-q', '84',
    '-crop', String(cropX), String(cropY), String(cropWidth), String(cropHeight),
    '-resize', String(target.width), String(target.height),
    fileURLToPath(inputUrl),
    '-o', fileURLToPath(outputUrl),
  ]);
}

export const artworkTargets = targets;
