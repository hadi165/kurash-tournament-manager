// Copy the country flags out of node_modules into public/flags/.
//
// Deliberately not imported through Vite. Vite would fingerprint the
// filenames, which is right for assets the bundler owns — but these are looked
// up by country code at runtime, from Blade, from the venue screens, and from
// Dompdf, which reads them off disk. A stable path is worth more here than a
// cache-busting hash, and the files never change between releases anyway.
//
// Only the 4x3 set is copied; the 1x1 set would double the size for a shape
// nothing in this application uses.
//
// The print/ subdirectory beside them is the raster set Dompdf uses, made by
// `php artisan flags:rasterise` from these same files. It is left alone here:
// the vectors are replaced, and whether the rasters need remaking is that
// command's business.

import { cp, mkdir, readdir, unlink } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const source = resolve(root, 'node_modules/flag-icons/flags/4x3');
const target = resolve(root, 'public/flags');

try {
    await readdir(source);
} catch {
    console.error('flag-icons is not installed — run: npm install');
    process.exit(1);
}

await mkdir(target, { recursive: true });

// The vectors only, so a flag withdrawn upstream still goes — but the print
// set survives, and so does anything else deliberately put here.
for (const file of await readdir(target)) {
    if (file.endsWith('.svg')) {
        await unlink(join(target, file));
    }
}

await cp(source, target, { recursive: true });

const flags = (await readdir(target)).filter((file) => file.endsWith('.svg'));
console.log(`flags: copied ${flags.length} SVGs to public/flags`);
