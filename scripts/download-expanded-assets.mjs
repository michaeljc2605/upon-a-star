import { copyFile, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { normalizeArtwork } from './movie-asset-processing.mjs';

const programme = JSON.parse(await readFile(new URL('./expanded-movie-data.json', import.meta.url), 'utf8'));
const clientRoot = new URL('../client/public/assets/movies/', import.meta.url);
const baseRoot = new URL('../base/assets/movies/', import.meta.url);
const phpRoot = new URL('../php/assets/movies/', import.meta.url);
const sourcesFile = new URL('./sources.json', clientRoot);
const sources = JSON.parse(await readFile(sourcesFile, 'utf8'));

function officialSources(html) {
  const poster = html.match(/<meta[^>]+property=["']og:image["'][^>]+content=["']([^"']+)/i)?.[1]?.replaceAll('&amp;', '&') || '';
  const urls = [...html.matchAll(/https:\/\/lumiere-a\.akamaihd\.net\/v1\/images\/[^"'<>\\\s]+/g)]
    .map((match) => match[0].replaceAll('&amp;', '&'));
  const wide = urls.filter((url) => {
    const dimensions = decodeURIComponent(url).match(/region=\d+,\d+,(\d+),(\d+)/);
    return dimensions && Number(dimensions[1]) >= 1000 && Number(dimensions[1]) / Number(dimensions[2]) >= 1.65;
  });
  const backdrop = wide.find((url) => /hero|banner/i.test(url)) || wide[0] || '';
  if (!poster || !backdrop) throw new Error('Official film page has no poster or wide artwork.');
  return { poster, backdrop };
}

async function saveArtwork(source, destination, kind) {
  const temporary = new URL(`./${kind}-source`, destination);
  const response = await fetch(source, { headers: { 'user-agent': 'Mozilla/5.0 SilverVillageAssetSetup/1.0' } });
  if (!response.ok) throw new Error(`Artwork request failed (${response.status}): ${source}`);
  await writeFile(temporary, Buffer.from(await response.arrayBuffer()));
  try {
    await normalizeArtwork(temporary, destination, kind);
  } finally {
    await rm(temporary, { force: true });
  }
}

for (const film of programme) {
  const response = await fetch(film.sourceUrl, { headers: { 'user-agent': 'Mozilla/5.0 SilverVillageAssetSetup/1.0' } });
  if (!response.ok) throw new Error(`${film.title} official page failed (${response.status}).`);
  const artwork = officialSources(await response.text());
  const clientDir = new URL(`./${film.slug}/`, clientRoot);
  await mkdir(clientDir, { recursive: true });
  await saveArtwork(artwork.poster, new URL('./poster.webp', clientDir), 'poster');
  await saveArtwork(artwork.backdrop, new URL('./backdrop.webp', clientDir), 'backdrop');
  for (const targetRoot of [baseRoot, phpRoot]) {
    const targetDir = new URL(`./${film.slug}/`, targetRoot);
    await mkdir(targetDir, { recursive: true });
    for (const kind of ['poster', 'backdrop']) {
      await copyFile(new URL(`./${kind}.webp`, clientDir), new URL(`./${kind}.webp`, targetDir));
    }
  }
  const entry = { title: film.title, page: film.sourceUrl, poster: artwork.poster, backdrop: artwork.backdrop };
  const index = sources.findIndex((existing) => existing.title === film.title);
  if (index < 0) sources.push(entry);
  else sources[index] = entry;
  console.log(`Saved ${film.title} artwork in client, base and php: ${fileURLToPath(clientDir)}`);
}

await writeFile(sourcesFile, `${JSON.stringify(sources, null, 2)}\n`);
