/**
 * Builds the art atlas: draws art/draw.js in headless Chrome, writes
 * assets/atlas.webp and js/src/forum/world/atlas.json (the frame list).
 *   node art/build.mjs [quality]
 */
import puppeteer from '../../convoro2/node_modules/puppeteer-core/lib/puppeteer/puppeteer-core.js';
import { readFileSync, writeFileSync, statSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const q = process.argv[2] || '82';
const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new' });
const page = await browser.newPage();
await page.setContent('<html><body></body></html>');
await page.addScriptTag({ content: readFileSync(new URL('./draw.js', import.meta.url), 'utf8') });
const { png, frames, scale, w, h } = await page.evaluate(() => {
  const { canvas, frames, scale } = window.__hangoutArt();
  return { png: canvas.toDataURL('image/png').split(',')[1], frames, scale, w: canvas.width, h: canvas.height };
});
await browser.close();
const tmp = new URL('../art/atlas.png', import.meta.url).pathname;
writeFileSync(tmp, Buffer.from(png, 'base64'));
const out = new URL('../assets/atlas.webp', import.meta.url).pathname;
execFileSync('cwebp', ['-quiet', '-q', q, '-alpha_q', '90', '-m', '6', '-sharp_yuv', tmp, '-o', out]);
const v = createHash('sha1').update(readFileSync(out)).digest('hex').slice(0, 10);
writeFileSync(new URL('../js/src/forum/world/atlas.json', import.meta.url), JSON.stringify({ v, scale, frames }) + '\n');
console.log(`atlas ${w}×${h}, ${Object.keys(frames).length} sprites, webp ${(statSync(out).size / 1024).toFixed(1)} KB (q${q}), v ${v}`);
