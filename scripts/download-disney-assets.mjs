import { copyFile, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { movies } from './disney-programme-data.mjs';
import { normalizeArtwork } from './movie-asset-processing.mjs';

const assetRoot = new URL('../client/public/assets/movies/', import.meta.url);
const baseAssetRoot = new URL('../base/assets/movies/', import.meta.url);
const expandedMovies = JSON.parse(await readFile(new URL('./expanded-movie-data.json', import.meta.url), 'utf8'));
const decodeHtml = (value) => value.replaceAll('&amp;', '&');

function officialSources(html) {
  const poster = decodeHtml(html.match(/<meta[^>]+property=["']og:image["'][^>]+content=["']([^"']+)/i)?.[1] || '');
  const urls = [...html.matchAll(/https:\/\/lumiere-a\.akamaihd\.net\/v1\/images\/[^"'<>\\\s]+/g)].map((match) => decodeHtml(match[0]));
  const wide = urls.filter((url) => {
    const region = decodeURIComponent(url).match(/region=\d+,\d+,(\d+),(\d+)/);
    return region && Number(region[1]) >= 1000 && Number(region[1]) / Number(region[2]) >= 1.65;
  });
  const backdrop = wide.find((url) => /hero|banner/i.test(url)) || wide[0] || '';
  if (!poster || !backdrop) throw new Error('The official page did not expose both poster and backdrop artwork.');
  return { poster, backdrop };
}

async function download(url, outputPath, temporaryPath, kind) {
  const response = await fetch(url, { headers: { 'user-agent': 'Mozilla/5.0 SilverVillageAssetSetup/1.0' } });
  if (!response.ok) throw new Error(`Artwork request failed with ${response.status}: ${url}`);
  await writeFile(temporaryPath, Buffer.from(await response.arrayBuffer()));
  try {
    await normalizeArtwork(temporaryPath, outputPath, kind);
  } finally {
    await rm(temporaryPath, { force: true });
  }
}

const manifest = [];
for (const item of [...movies, ...expandedMovies]) {
  const directory = new URL(`./${item.slug}/`, assetRoot);
  await mkdir(directory, { recursive: true });
  let posterSource = item.posterSource;
  let backdropSource = item.backdropSource;
  if (!posterSource || !backdropSource) {
    const response = await fetch(item.sourceUrl, { headers: { 'user-agent': 'Mozilla/5.0 SilverVillageAssetSetup/1.0' } });
    if (!response.ok) throw new Error(`${item.title} page failed with ${response.status}.`);
    ({ poster: posterSource, backdrop: backdropSource } = officialSources(await response.text()));
  }
  await download(posterSource, new URL('./poster.webp', directory), new URL('./poster-source', directory), 'poster');
  await download(backdropSource, new URL('./backdrop.webp', directory), new URL('./backdrop-source', directory), 'backdrop');
  const baseDirectory = new URL(`./${item.slug}/`, baseAssetRoot);
  await mkdir(baseDirectory, { recursive: true });
  for (const kind of ['poster', 'backdrop']) {
    await copyFile(new URL(`./${kind}.webp`, directory), new URL(`./${kind}.webp`, baseDirectory));
  }
  manifest.push({ title: item.title, page: item.sourceUrl, poster: posterSource, backdrop: backdropSource });
  console.log(`Saved ${item.title}`);
}
await writeFile(new URL('./sources.json', assetRoot), `${JSON.stringify(manifest, null, 2)}\n`);
