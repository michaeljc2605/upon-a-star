import { copyFile, mkdir, readFile, rename, rm } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { movies } from './disney-programme-data.mjs';
import { normalizeArtwork } from './movie-asset-processing.mjs';

const assetRoot = new URL('../client/public/assets/movies/', import.meta.url);
const baseAssetRoot = new URL('../base/assets/movies/', import.meta.url);
const expandedMovies = JSON.parse(await readFile(new URL('./expanded-movie-data.json', import.meta.url), 'utf8'));

for (const movie of [...movies, ...expandedMovies]) {
  const directory = new URL(`./${movie.slug}/`, assetRoot);
  const baseDirectory = new URL(`./${movie.slug}/`, baseAssetRoot);
  await mkdir(baseDirectory, { recursive: true });
  for (const kind of ['poster', 'backdrop']) {
    const artwork = new URL(`./${kind}.webp`, directory);
    const normalized = new URL(`./.${kind}-normalized.webp`, directory);
    try {
      await normalizeArtwork(artwork, normalized, kind);
      await rename(fileURLToPath(normalized), fileURLToPath(artwork));
      await copyFile(artwork, new URL(`./${kind}.webp`, baseDirectory));
    } finally {
      await rm(normalized, { force: true });
    }
  }
  console.log(`Normalized ${movie.title}`);
}
