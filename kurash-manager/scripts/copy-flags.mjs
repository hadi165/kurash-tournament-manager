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

import { cp, mkdir, readdir, rm } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
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

await rm(target, { recursive: true, force: true });
await mkdir(target, { recursive: true });
await cp(source, target, { recursive: true });

const flags = await readdir(target);
console.log(`flags: copied ${flags.length} SVGs to public/flags`);
