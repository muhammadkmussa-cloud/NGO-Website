// Build script: assembles dist/ and deploys into the Laravel public directory
// so a single VPS domain serves both the SPA and the API (no CORS needed).
//
// Output layout:
//   dist/index.html            (SPA shell, rewritten asset paths)
//   dist/assets/tailwind.css   (compiled by Tailwind CLI before this runs)
//   dist/js/...                (copied ES modules)
//   ../laravel-backend/public/ (deployment copy: index.html + assets/ + js/)
import { cpSync, mkdirSync, readFileSync, rmSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const dist = join(root, 'dist');
const laravelPublic = join(root, '..', 'laravel-backend', 'public');

mkdirSync(join(dist, 'assets'), { recursive: true });
// Prune first: cpSync merges, so a source file deleted in an old commit
// (e.g. the orphaned adminCheckIn.js) would otherwise survive forever.
rmSync(join(dist, 'js'), { recursive: true, force: true });
cpSync(join(root, 'src/js'), join(dist, 'js'), { recursive: true });

// The source shell points at /src/js/main.js; the build serves /js/main.js.
// Absolute paths are used (not "./js") so the shell also works when served at
// a sub-path like /checking (the open gate station), not just at the root.
let html = readFileSync(join(root, 'index.html'), 'utf-8');
html = html.replace('/src/js/main.js', '/js/main.js');
html = html.replace('./assets/tailwind.css', '/assets/tailwind.css');
writeFileSync(join(dist, 'index.html'), html);

// Deploy into Laravel's public directory (static files win over index.php
// for `/` on nginx/artisan serve, while /api/* still routes through Laravel).
if (existsSync(laravelPublic)) {
  rmSync(join(laravelPublic, 'index.html'), { force: true });
  rmSync(join(laravelPublic, 'assets'), { recursive: true, force: true });
  rmSync(join(laravelPublic, 'js'), { recursive: true, force: true });
  cpSync(join(dist, 'index.html'), join(laravelPublic, 'index.html'));
  cpSync(join(dist, 'assets'), join(laravelPublic, 'assets'), { recursive: true });
  cpSync(join(dist, 'js'), join(laravelPublic, 'js'), { recursive: true });
  console.log('✓ deployed to laravel-backend/public/ (single-origin SPA + API)');
}

console.log('✓ dist/ assembled: index.html + js/ modules + assets/tailwind.css');
