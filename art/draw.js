/*
 * Hangout's art, drawn by code: every floor, wall, door and piece of furniture
 * in the atlas. Original work for Hangout (GPL-2.0-or-later, like the rest).
 *
 * art/build.mjs runs this in headless Chrome, packs the sprites into one
 * image and writes assets/atlas.webp plus the frame list the world reads.
 *
 * Units: S px per tile (2× the world's 48 px tile, for sharp phones). A
 * furniture sprite is its footprint (w × h tiles) plus `up` px of height
 * above it, anchored at the footprint's bottom edge. The view is 3/4
 * top-down: tops are lighter, fronts darker, light comes from the top left.
 */
const S = 96;

const sprites = [];
const add = (name, w, h, draw, opts = {}) => sprites.push({ name, w, h, draw, ...opts });

// ── helpers ──────────────────────────────────────────────────
const shade = (hex, f) => {
  const n = parseInt(hex.slice(1), 16);
  let r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
  const t = f < 0 ? 0 : 255, p = Math.abs(f);
  r = Math.round((t - r) * p + r); g = Math.round((t - g) * p + g); b = Math.round((t - b) * p + b);
  return `rgb(${r},${g},${b})`;
};
const OUT = 'rgba(24,18,34,0.55)';
function rr(g, x, y, w, h, r) {
  r = Math.min(r, w / 2, h / 2);
  g.beginPath();
  g.moveTo(x + r, y);
  g.arcTo(x + w, y, x + w, y + h, r);
  g.arcTo(x + w, y + h, x, y + h, r);
  g.arcTo(x, y + h, x, y, r);
  g.arcTo(x, y, x + w, y, r);
  g.closePath();
}
function fillRR(g, x, y, w, h, r, fill, stroke = OUT, lw = 3) {
  rr(g, x, y, w, h, r);
  g.fillStyle = fill;
  g.fill();
  if (stroke) { g.lineWidth = lw; g.strokeStyle = stroke; g.stroke(); }
}
function vgrad(g, y0, y1, a, b) {
  const gr = g.createLinearGradient(0, y0, 0, y1);
  gr.addColorStop(0, a); gr.addColorStop(1, b);
  return gr;
}
/** A 3/4 box: top face (depth d) over a front face (height hgt). */
function box(g, x, y, w, d, hgt, color, r = 10) {
  fillRR(g, x, y + d - 4, w, hgt + 4, r, vgrad(g, y + d, y + d + hgt, shade(color, -0.12), shade(color, -0.32)));
  fillRR(g, x, y, w, d, r, vgrad(g, y, y + d, shade(color, 0.18), color));
  g.fillStyle = 'rgba(255,255,255,0.18)';
  rr(g, x + 6, y + 4, w - 12, 5, 3); g.fill();
}
function contactShadow(g, cx, cy, rx, ry, a = 0.28) {
  const gr = g.createRadialGradient(cx, cy, 1, cx, cy, rx);
  gr.addColorStop(0, `rgba(0,0,0,${a})`); gr.addColorStop(1, 'rgba(0,0,0,0)');
  g.save(); g.translate(cx, cy); g.scale(1, ry / rx); g.translate(-cx, -cy);
  g.fillStyle = gr; g.beginPath(); g.arc(cx, cy, rx, 0, Math.PI * 2); g.fill(); g.restore();
}
function rand(seed) { let s = seed >>> 0; return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296); }
function glow(g, cx, cy, r, color, a = 0.6) {
  const gr = g.createRadialGradient(cx, cy, 0, cx, cy, r);
  gr.addColorStop(0, color); gr.addColorStop(1, 'rgba(0,0,0,0)');
  g.save(); g.globalAlpha = a; g.globalCompositeOperation = 'lighter'; g.fillStyle = gr; g.beginPath(); g.arc(cx, cy, r, 0, Math.PI * 2); g.fill(); g.restore();
}

// ── floors (2×2-tile seamless textures) ──────────────────────
function planks(g, W, H, base, seed) {
  // Drawn three times side by side so the texture wraps without a seam.
  g.fillStyle = base; g.fillRect(0, 0, W, H);
  for (const ox of [-W, 0, W]) { g.save(); g.translate(ox, 0); plankRows(g, W, H, base, seed); g.restore(); }
}
function plankRows(g, W, H, base, seed) {
  const R = rand(seed);
  const rows = 8, rh = H / rows;
  for (let r = 0; r < rows; r++) {
    let x = -((r * 37) % 120);
    while (x < W) {
      const len = 110 + Math.floor(R() * 70);
      const f = (R() - 0.5) * 0.14;
      g.fillStyle = shade(base, f);
      g.fillRect(x, r * rh, len, rh);
      g.fillStyle = 'rgba(0,0,0,0.06)';
      for (let k = 0; k < 3; k++) g.fillRect(x + 8 + R() * (len - 20), r * rh + 4 + R() * (rh - 8), 18 + R() * 30, 1.5);
      g.fillStyle = 'rgba(0,0,0,0.22)'; g.fillRect(x, r * rh, 2, rh);
      x += len;
    }
    g.fillStyle = 'rgba(0,0,0,0.20)'; g.fillRect(0, r * rh + rh - 2, W, 2);
    g.fillStyle = 'rgba(255,255,255,0.07)'; g.fillRect(0, r * rh, W, 2);
  }
}
add('floor_oak', 2, 2, (g, W, H) => planks(g, W, H, '#c8955f', 11), { kind: 'floor' });
add('floor_walnut', 2, 2, (g, W, H) => planks(g, W, H, '#8a5a3c', 23), { kind: 'floor' });
add('floor_birch', 2, 2, (g, W, H) => planks(g, W, H, '#dcc196', 7), { kind: 'floor' });
add('floor_deck', 2, 2, (g, W, H) => {
  planks(g, W, H, '#9b7b5c', 5);
  g.fillStyle = 'rgba(0,0,0,0.25)';
  for (let r = 0; r < 8; r++) g.fillRect(0, (r * H) / 8 + H / 16 - 1, W, 1);
}, { kind: 'floor' });
add('floor_carpet', 2, 2, (g, W, H) => {
  g.fillStyle = '#2c2148'; g.fillRect(0, 0, W, H);
  const cols = ['#f472b6', '#22d3ee', '#facc15', '#a78bfa'];
  const R = rand(9);
  for (let i = 0; i < 26; i++) {
    const x = R() * W, y = R() * H, c = cols[i % 4];
    g.strokeStyle = c; g.globalAlpha = 0.55; g.lineWidth = 3;
    g.beginPath();
    if (i % 3 === 0) { g.arc(x, y, 7, 0, Math.PI * 2); } else if (i % 3 === 1) { g.moveTo(x - 8, y); g.lineTo(x, y - 8); g.lineTo(x + 8, y); } else { g.moveTo(x - 8, y - 4); g.quadraticCurveTo(x, y + 8, x + 8, y - 4); }
    for (const ox of [-W, 0, W]) for (const oy of [-H, 0, H]) { g.save(); g.translate(ox, oy); g.stroke(); g.restore(); }
  }
  g.globalAlpha = 1;
}, { kind: 'floor' });
add('floor_tile', 2, 2, (g, W, H) => {
  const t = W / 4;
  for (let y = 0; y < 4; y++) for (let x = 0; x < 4; x++) {
    g.fillStyle = (x + y) % 2 ? '#e8e2d6' : '#d3cabb'; g.fillRect(x * t, y * t, t, t);
    g.fillStyle = 'rgba(255,255,255,0.25)'; g.fillRect(x * t, y * t, t, 2);
    g.fillStyle = 'rgba(0,0,0,0.10)'; g.fillRect(x * t, y * t + t - 2, t, 2);
  }
}, { kind: 'floor' });
add('floor_concrete', 2, 2, (g, W, H) => {
  g.fillStyle = '#7b808b'; g.fillRect(0, 0, W, H);
  const R = rand(3);
  for (let i = 0; i < 900; i++) { g.fillStyle = `rgba(${R() > 0.5 ? '255,255,255' : '0,0,0'},${0.04 + R() * 0.05})`; g.fillRect(R() * W, R() * H, 2, 2); }
  g.fillStyle = 'rgba(0,0,0,0.18)'; g.fillRect(0, H / 2 - 1, W, 2); g.fillRect(W / 2 - 1, 0, 2, H);
}, { kind: 'floor' });
add('floor_grass', 2, 2, (g, W, H) => {
  g.fillStyle = '#5f9a4b'; g.fillRect(0, 0, W, H);
  const R = rand(4);
  for (let i = 0; i < 700; i++) { const x = R() * W, y = R() * H; g.strokeStyle = R() > 0.5 ? '#77b35d' : '#4e8540'; g.lineWidth = 2; g.beginPath(); g.moveTo(x, y); g.lineTo(x + (R() - 0.5) * 6, y - 6 - R() * 6); g.stroke(); }
}, { kind: 'floor' });

// ── walls (2 tiles wide, 3 tall) ─────────────────────────────
function wall(g, W, H, top, bottom, pattern) {
  g.fillStyle = vgrad(g, 0, H, top, bottom); g.fillRect(0, 0, W, H);
  if (pattern) pattern(g, W, H);
  // dado rail and skirting
  g.fillStyle = 'rgba(0,0,0,0.18)'; g.fillRect(0, H * 0.62, W, 4);
  g.fillStyle = 'rgba(255,255,255,0.10)'; g.fillRect(0, H * 0.62 - 3, W, 3);
  g.fillStyle = shade(bottom, -0.35); g.fillRect(0, H - 22, W, 22);
  g.fillStyle = 'rgba(255,255,255,0.12)'; g.fillRect(0, H - 22, W, 3);
}
const stripes = (c, a) => (g, W, H) => { g.fillStyle = c; g.globalAlpha = a; for (let x = 0; x < W; x += 24) g.fillRect(x, 0, 10, H * 0.62); g.globalAlpha = 1; };
const damask = (c) => (g, W, H) => {
  g.strokeStyle = c; g.lineWidth = 2; g.globalAlpha = 0.18;
  for (let y = 18; y < H * 0.6; y += 46) for (let x = (y / 46) % 2 ? 24 : 0; x < W + 48; x += 48) {
    g.beginPath(); g.moveTo(x, y - 12); g.quadraticCurveTo(x + 12, y, x, y + 12); g.quadraticCurveTo(x - 12, y, x, y - 12); g.stroke();
  }
  g.globalAlpha = 1;
};
add('wall_dusk', 2, 3, (g, W, H) => wall(g, W, H, '#4b4170', '#3a3354', damask('#e8dcff')), { kind: 'wall' });
add('wall_forest', 2, 3, (g, W, H) => wall(g, W, H, '#3c5e4e', '#2f4a3e', stripes('#cfe8da', 0.08)), { kind: 'wall' });
add('wall_navy', 2, 3, (g, W, H) => wall(g, W, H, '#38507a', '#2c3e5c', damask('#d6e4ff')), { kind: 'wall' });
add('wall_brick', 2, 3, (g, W, H) => {
  wall(g, W, H, '#8a4f42', '#6e3d33', (g, W, H) => {
    const R = rand(8);
    for (let y = 0; y < H - 22; y += 24) for (let x = (y / 24) % 2 ? -24 : 0; x < W; x += 48) {
      g.fillStyle = shade('#8a4f42', (R() - 0.5) * 0.18); g.fillRect(x + 2, y + 2, 44, 20);
    }
  });
}, { kind: 'wall' });
add('wall_stone', 2, 3, (g, W, H) => wall(g, W, H, '#6b6257', '#5b5146', stripes('#fff', 0.04)), { kind: 'wall' });
add('wall_cream', 2, 3, (g, W, H) => wall(g, W, H, '#efe3cf', '#ddcdb2', stripes('#c9a77a', 0.12)), { kind: 'wall' });
add('wall_arcade', 2, 3, (g, W, H) => {
  wall(g, W, H, '#1e1838', '#151129', (g, W, H) => {
    g.strokeStyle = '#7c3aed'; g.globalAlpha = 0.35; g.lineWidth = 2;
    for (let x = 0; x < W; x += 32) { g.beginPath(); g.moveTo(x, 0); g.lineTo(x + 32, H * 0.6); g.stroke(); }
    g.globalAlpha = 1;
    g.fillStyle = '#22d3ee'; g.fillRect(0, H * 0.3, W, 3);
    g.fillStyle = '#f472b6'; g.fillRect(0, H * 0.3 + 8, W, 3);
  });
}, { kind: 'wall' });

// ── doors ────────────────────────────────────────────────────
add('door_n', 1, 2.6, (g, W, H) => {
  const fw = W + 20;
  g.save(); g.translate(-10, 0);
  fillRR(g, 0, 18, fw, H - 18, 12, '#3b2c22');
  fillRR(g, 12, 30, fw - 24, H - 30, 8, vgrad(g, 30, H, '#1d1726', '#0f0c16'), null);
  g.fillStyle = 'rgba(255,214,140,0.22)'; g.fillRect(12, H - 26, fw - 24, 26);
  g.fillStyle = '#c9a26a'; g.fillRect(0, 10, fw, 14);
  g.restore();
}, { kind: 'door', pad: 10 });
add('door_open', 1, 2.6, (g, W, H) => {
  g.fillStyle = vgrad(g, 20, H, '#120f19', '#2a2133'); rr(g, 6, 26, W - 12, H - 26, 8); g.fill();
  g.fillStyle = '#c9a26a'; g.fillRect(-2, 14, W + 4, 14);
  g.strokeStyle = '#3b2c22'; g.lineWidth = 10; g.strokeRect(1, 24, W - 2, H - 20);
  // steps going up
  for (let i = 0; i < 5; i++) { g.fillStyle = shade('#7b6a5a', -0.08 * i); g.fillRect(12, H - 18 - i * 22, W - 24, 16); }
}, { kind: 'door' });
add('door_side', 0.5, 2.4, (g, W, H) => {
  fillRR(g, 2, 10, W - 4, H - 10, 8, vgrad(g, 0, H, '#5a4334', '#3b2c22'));
  g.fillStyle = 'rgba(255,214,140,0.35)'; g.fillRect(10, 30, W - 20, H - 40);
  g.fillStyle = '#c9a26a'; g.beginPath(); g.arc(W / 2, H * 0.58, 5, 0, Math.PI * 2); g.fill();
}, { kind: 'door' });
add('doormat', 1, 1, (g, W, H) => {
  fillRR(g, 8, 24, W - 16, H - 40, 10, '#6b4e3a');
  g.strokeStyle = 'rgba(255,230,190,0.45)'; g.lineWidth = 3; rr(g, 16, 32, W - 32, H - 56, 6); g.stroke();
}, { kind: 'floorprop' });
add('railing', 2, 1.2, (g, W, H) => {
  g.fillStyle = '#3d3a46'; g.fillRect(0, H - 20, W, 20);
  g.fillStyle = '#5a5666'; g.fillRect(0, 6, W, 10);
  for (let x = 8; x < W; x += 24) { g.fillStyle = '#4a4655'; g.fillRect(x, 14, 6, H - 34); }
}, { kind: 'wall' });

// ── furniture ────────────────────────────────────────────────
function cushionSofa(g, W, H, up, color, seats) {
  const y0 = up;
  contactShadow(g, W / 2, H - 10, W / 2 - 4, 14);
  fillRR(g, 6, y0 - 54, W - 12, 92, 22, vgrad(g, y0 - 54, y0 + 40, shade(color, 0.1), shade(color, -0.2))); // back
  const cw = (W - 44) / seats;
  for (let i = 0; i < seats; i++) fillRR(g, 22 + i * cw + 2, y0 + 8, cw - 4, 56, 14, vgrad(g, y0 + 8, y0 + 64, shade(color, 0.22), shade(color, -0.05)));
  fillRR(g, 4, y0 - 6, 26, H - y0 - 4, 12, vgrad(g, y0, H, shade(color, 0.05), shade(color, -0.3)));
  fillRR(g, W - 30, y0 - 6, 26, H - y0 - 4, 12, vgrad(g, y0, H, shade(color, 0.05), shade(color, -0.3)));
  fillRR(g, 22, H - 26, W - 44, 18, 6, shade(color, -0.35));
}
add('sofa', 3, 1, (g, W, H) => cushionSofa(g, W, H, 60, '#a35d8a', 3), { up: 60 });
add('armchair', 1, 1, (g, W, H) => cushionSofa(g, W, H, 60, '#4c6a8a', 1), { up: 60 });
add('beanbag', 1, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 12, 40, 12);
  const c = '#e8833a';
  g.fillStyle = vgrad(g, 20, H, shade(c, 0.2), shade(c, -0.25));
  g.beginPath(); g.ellipse(W / 2, H - 38, 42, 34, 0, 0, Math.PI * 2); g.fill(); g.strokeStyle = OUT; g.lineWidth = 3; g.stroke();
  g.fillStyle = 'rgba(255,255,255,0.25)'; g.beginPath(); g.ellipse(W / 2 - 12, H - 56, 14, 8, -0.4, 0, Math.PI * 2); g.fill();
}, { up: 10 });
function table(g, W, H, up, top, legs) {
  contactShadow(g, W / 2, H - 14, W / 2 - 8, 12);
  g.fillStyle = shade(legs, -0.2);
  g.fillRect(16, up + 20, 8, H - up - 30); g.fillRect(W - 24, up + 20, 8, H - up - 30);
  box(g, 6, up - 10, W - 12, 40, 14, top, 12);
}
add('coffee_table', 2, 1, (g, W, H) => { table(g, W, H, 34, '#8a5a3a', '#5f3d27'); g.fillStyle = '#e8e2d6'; rr(g, W * 0.3, 30, 36, 20, 4); g.fill(); g.fillStyle = '#f472b6'; g.beginPath(); g.arc(W * 0.66, 38, 10, 0, 7); g.fill(); }, { up: 34 });
add('table_round', 1, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 12, 36, 10);
  g.fillStyle = '#4a3a2c'; g.fillRect(W / 2 - 5, 50, 10, H - 62);
  g.fillStyle = '#3b2c22'; g.beginPath(); g.ellipse(W / 2, H - 14, 22, 7, 0, 0, 7); g.fill();
  g.fillStyle = shade('#9a6a44', -0.25); g.beginPath(); g.ellipse(W / 2, 46, 44, 18, 0, 0, 7); g.fill();
  g.fillStyle = vgrad(g, 20, 50, '#b98457', '#9a6a44'); g.beginPath(); g.ellipse(W / 2, 40, 44, 18, 0, 0, 7); g.fill(); g.strokeStyle = OUT; g.lineWidth = 3; g.stroke();
  g.fillStyle = '#fef3c7'; g.beginPath(); g.ellipse(W / 2 + 10, 36, 9, 5, 0, 0, 7); g.fill();
}, { up: 30 });
function pot(g, W, H, c = '#c2703d') {
  contactShadow(g, W / 2, H - 12, 34, 10);
  g.fillStyle = vgrad(g, H - 56, H, shade(c, 0.15), shade(c, -0.25));
  g.beginPath(); g.moveTo(W / 2 - 28, H - 56); g.lineTo(W / 2 + 28, H - 56); g.lineTo(W / 2 + 20, H - 12); g.lineTo(W / 2 - 20, H - 12); g.closePath(); g.fill(); g.strokeStyle = OUT; g.lineWidth = 3; g.stroke();
  g.fillStyle = shade(c, 0.25); g.fillRect(W / 2 - 30, H - 60, 60, 10);
}
function leaves(g, cx, cy, n, r, len, colA, colB, seed) {
  const R = rand(seed);
  for (let i = 0; i < n; i++) {
    const a = -Math.PI / 2 + (R() - 0.5) * 2.6, l = len * (0.7 + R() * 0.5);
    g.save(); g.translate(cx, cy); g.rotate(a + Math.PI / 2);
    g.fillStyle = i % 2 ? colA : colB;
    g.beginPath(); g.ellipse(0, -l / 2, r, l / 2, 0, 0, Math.PI * 2); g.fill();
    g.strokeStyle = 'rgba(10,40,20,0.4)'; g.lineWidth = 2; g.stroke();
    g.restore();
  }
}
add('plant', 1, 1, (g, W, H) => { pot(g, W, H); leaves(g, W / 2, H - 58, 11, 11, 60, '#3f8f4f', '#56b067', 2); }, { up: 50 });
add('tall_plant', 1, 1, (g, W, H) => {
  pot(g, W, H, '#e8e2d6');
  g.strokeStyle = '#5b4632'; g.lineWidth = 6; g.beginPath(); g.moveTo(W / 2, H - 60); g.lineTo(W / 2, 70); g.stroke();
  leaves(g, W / 2, 110, 9, 16, 70, '#2f7a43', '#4aa35d', 5);
  leaves(g, W / 2, 70, 9, 14, 60, '#3f8f4f', '#5ec072', 6);
}, { up: 130 });
add('lamp', 1, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 14, 26, 8);
  g.fillStyle = '#2d2a33'; g.beginPath(); g.ellipse(W / 2, H - 16, 18, 7, 0, 0, 7); g.fill();
  g.fillStyle = '#3a3640'; g.fillRect(W / 2 - 3, 60, 6, H - 76);
  glow(g, W / 2, 50, 60, '#ffcf7a', 0.55);
  g.fillStyle = vgrad(g, 18, 70, '#fff1c9', '#f5c76a');
  g.beginPath(); g.moveTo(W / 2 - 18, 18); g.lineTo(W / 2 + 18, 18); g.lineTo(W / 2 + 30, 70); g.lineTo(W / 2 - 30, 70); g.closePath(); g.fill(); g.strokeStyle = OUT; g.lineWidth = 3; g.stroke();
}, { up: 100 });
add('bookshelf', 2, 1, (g, W, H) => {
  const up = 150;
  contactShadow(g, W / 2, H - 8, W / 2, 10, 0.2);
  fillRR(g, 6, 4, W - 12, H - 8, 8, vgrad(g, 0, H, '#7a5236', '#5b3c27'));
  const R = rand(12), cols = ['#c4a7ff', '#7dd3fc', '#fbbf24', '#f472b6', '#a3e635', '#fb7185', '#e2e8f0'];
  for (let s = 0; s < 4; s++) {
    const y = 18 + s * 52;
    g.fillStyle = '#3d2818'; g.fillRect(16, y, W - 32, 44);
    let x = 20;
    while (x < W - 30) { const bw = 8 + R() * 10, bh = 26 + R() * 14; g.fillStyle = cols[Math.floor(R() * cols.length)]; g.fillRect(x, y + 44 - bh, bw, bh); g.fillStyle = 'rgba(0,0,0,0.2)'; g.fillRect(x + bw - 2, y + 44 - bh, 2, bh); x += bw + 2; }
    g.fillStyle = '#8a5d3d'; g.fillRect(12, y + 44, W - 24, 6);
  }
}, { up: 150 });
add('cabinet', 1, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 8, 44, 10);
  fillRR(g, 8, 20, W - 16, H - 26, 10, vgrad(g, 0, H, '#3b2f6b', '#221a44'));
  fillRR(g, 14, 2, W - 28, 30, 8, '#f472b6');
  g.fillStyle = '#fff'; g.font = 'bold 18px sans-serif'; g.textAlign = 'center'; g.fillText('PLAY', W / 2, 24);
  fillRR(g, 18, 44, W - 36, 64, 6, '#0b1220');
  glow(g, W / 2, 76, 40, '#22d3ee', 0.5);
  g.fillStyle = '#22d3ee'; g.fillRect(28, 60, 18, 8); g.fillStyle = '#facc15'; g.fillRect(52, 80, 10, 10); g.fillStyle = '#f472b6'; g.fillRect(36, 88, 8, 8);
  box(g, 12, 116, W - 24, 22, 14, '#4b3d85', 6);
  g.fillStyle = '#ef4444'; g.beginPath(); g.arc(34, 126, 6, 0, 7); g.fill(); g.fillStyle = '#22c55e'; g.beginPath(); g.arc(58, 124, 5, 0, 7); g.fill();
}, { up: 120 });
add('pinball', 1, 2, (g, W, H) => {
  contactShadow(g, W / 2, H - 8, 44, 10);
  g.fillStyle = '#1f1a33'; g.fillRect(18, 120, 8, H - 130); g.fillRect(W - 26, 120, 8, H - 130);
  fillRR(g, 6, 70, W - 12, 140, 10, vgrad(g, 70, 210, '#7c3aed', '#4c1d95'));
  fillRR(g, 14, 78, W - 28, 124, 6, '#0f172a');
  glow(g, W / 2, 140, 44, '#f472b6', 0.45);
  for (const [x, y, c] of [[30, 110, '#facc15'], [62, 104, '#22d3ee'], [46, 140, '#f472b6']]) { g.fillStyle = c; g.beginPath(); g.arc(x, y, 8, 0, 7); g.fill(); }
  fillRR(g, 10, 6, W - 20, 70, 8, vgrad(g, 6, 76, '#f472b6', '#be185d'));
  g.fillStyle = '#fff'; g.font = 'bold 16px sans-serif'; g.textAlign = 'center'; g.fillText('ZOOM', W / 2, 46);
}, { up: 110 });
add('claw', 1, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 8, 44, 10);
  fillRR(g, 8, 110, W - 16, H - 116, 8, vgrad(g, 110, H, '#facc15', '#ca8a04'));
  fillRR(g, 8, 14, W - 16, 100, 6, 'rgba(186,230,253,0.35)');
  glow(g, W / 2, 60, 50, '#fde047', 0.3);
  for (const [x, y, c] of [[30, 98, '#f472b6'], [52, 102, '#a3e635'], [68, 96, '#7dd3fc'], [42, 88, '#fb7185']]) { g.fillStyle = c; g.beginPath(); g.arc(x, y, 9, 0, 7); g.fill(); }
  g.strokeStyle = '#cbd5e1'; g.lineWidth = 3; g.beginPath(); g.moveTo(W / 2, 18); g.lineTo(W / 2, 50); g.moveTo(W / 2 - 10, 60); g.lineTo(W / 2, 50); g.lineTo(W / 2 + 10, 60); g.stroke();
  fillRR(g, 4, 2, W - 8, 18, 6, '#ca8a04');
}, { up: 110 });
add('jukebox', 1, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 8, 44, 10);
  g.fillStyle = vgrad(g, 10, H, '#a855f7', '#581c87');
  g.beginPath(); g.moveTo(10, H - 8); g.lineTo(10, 60); g.arc(W / 2, 60, W / 2 - 10, Math.PI, 0); g.lineTo(W - 10, H - 8); g.closePath(); g.fill(); g.strokeStyle = OUT; g.lineWidth = 3; g.stroke();
  glow(g, W / 2, 60, 46, '#e9d5ff', 0.5);
  g.fillStyle = '#fde68a'; g.beginPath(); g.arc(W / 2, 62, 26, Math.PI, 0); g.fill();
  for (let i = 0; i < 5; i++) { g.fillStyle = ['#f472b6', '#22d3ee', '#facc15', '#a3e635', '#fb7185'][i]; g.fillRect(22 + i * 11, 84, 8, 30); }
}, { up: 100 });
add('stool', 1, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 12, 22, 7);
  g.strokeStyle = '#4b5563'; g.lineWidth = 5; g.beginPath(); g.moveTo(W / 2 - 14, H - 12); g.lineTo(W / 2 - 6, 54); g.moveTo(W / 2 + 14, H - 12); g.lineTo(W / 2 + 6, 54); g.stroke();
  g.fillStyle = shade('#b91c1c', -0.2); g.beginPath(); g.ellipse(W / 2, 52, 24, 10, 0, 0, 7); g.fill();
  g.fillStyle = '#dc2626'; g.beginPath(); g.ellipse(W / 2, 46, 24, 10, 0, 0, 7); g.fill(); g.strokeStyle = OUT; g.lineWidth = 3; g.stroke();
}, { up: 20 });
add('bar', 5, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 8, W / 2, 10, 0.25);
  box(g, 4, 10, W - 8, 34, H - 52, '#7a4a2c', 10);
  g.fillStyle = 'rgba(255,255,255,0.08)'; for (let x = 40; x < W - 20; x += 60) g.fillRect(x, 50, 4, H - 70);
  const R = rand(31);
  for (let x = 30; x < W - 30; x += 46) { const c = ['#a3e635', '#fbbf24', '#7dd3fc', '#f472b6'][Math.floor(R() * 4)]; g.fillStyle = c; g.globalAlpha = 0.85; rr(g, x, 2, 12, 26, 4); g.fill(); g.globalAlpha = 1; g.fillStyle = '#e2e8f0'; g.fillRect(x + 3, -4, 6, 8); }
}, { up: 40 });
add('bench', 2, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 10, W / 2 - 6, 10);
  g.fillStyle = '#374151'; g.fillRect(16, 40, 8, H - 50); g.fillRect(W - 24, 40, 8, H - 50);
  for (let i = 0; i < 3; i++) fillRR(g, 6, 26 + i * 14, W - 12, 12, 4, shade('#a0703f', -0.06 * i));
  fillRR(g, 6, -6, W - 12, 12, 4, '#a0703f'); fillRR(g, 6, 10, W - 12, 12, 4, '#8f6236');
}, { up: 30 });
add('planter', 2, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 10, W / 2 - 6, 10);
  box(g, 6, 40, W - 12, 24, H - 72, '#6b7280', 8);
  leaves(g, W * 0.3, 52, 9, 10, 50, '#3f8f4f', '#56b067', 7);
  leaves(g, W * 0.7, 52, 9, 10, 46, '#2f7a43', '#5ec072', 8);
  for (const [x, c] of [[W * 0.45, '#f472b6'], [W * 0.58, '#facc15'], [W * 0.2, '#fb7185']]) { g.fillStyle = c; g.beginPath(); g.arc(x, 28, 7, 0, 7); g.fill(); }
}, { up: 50 });
add('telescope', 1, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 12, 30, 8);
  g.strokeStyle = '#334155'; g.lineWidth = 5; g.beginPath(); g.moveTo(W / 2, 70); g.lineTo(W / 2 - 24, H - 12); g.moveTo(W / 2, 70); g.lineTo(W / 2 + 24, H - 12); g.moveTo(W / 2, 70); g.lineTo(W / 2, H - 10); g.stroke();
  g.save(); g.translate(W / 2, 62); g.rotate(-0.6);
  fillRR(g, -40, -10, 80, 20, 8, vgrad(g, -10, 10, '#e2e8f0', '#94a3b8')); fillRR(g, 30, -13, 16, 26, 5, '#475569');
  g.restore();
}, { up: 40 });
add('hammock', 3, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 8, W / 2 - 10, 10);
  g.fillStyle = '#5b4632'; g.fillRect(8, 0, 10, H - 8); g.fillRect(W - 18, 0, 10, H - 8);
  g.fillStyle = vgrad(g, 10, 90, '#fbbf24', '#d97706');
  g.beginPath(); g.moveTo(18, 14); g.quadraticCurveTo(W / 2, 110, W - 18, 14); g.quadraticCurveTo(W / 2, 70, 18, 14); g.fill(); g.strokeStyle = OUT; g.lineWidth = 3; g.stroke();
  g.strokeStyle = 'rgba(255,255,255,0.35)'; g.lineWidth = 3; for (let i = 1; i < 6; i++) { const x = 18 + ((W - 36) * i) / 6; g.beginPath(); g.moveTo(x, 30); g.lineTo(x, 70); g.stroke(); }
}, { up: 20 });
add('deckchair', 1, 1, (g, W, H) => {
  contactShadow(g, W / 2, H - 10, 34, 9);
  g.strokeStyle = '#7c5a3a'; g.lineWidth = 6; g.beginPath(); g.moveTo(16, H - 10); g.lineTo(W - 20, 20); g.moveTo(W - 16, H - 10); g.lineTo(24, 40); g.stroke();
  g.fillStyle = '#0ea5e9'; g.beginPath(); g.moveTo(20, 30); g.lineTo(W - 22, 22); g.lineTo(W - 18, H - 30); g.lineTo(24, H - 22); g.closePath(); g.fill();
  g.fillStyle = '#f8fafc'; for (let i = 0; i < 3; i++) { g.beginPath(); g.moveTo(26 + i * 18, 30); g.lineTo(34 + i * 18, 28); g.lineTo(38 + i * 18, H - 26); g.lineTo(30 + i * 18, H - 24); g.closePath(); g.fill(); }
}, { up: 10 });
add('fountain', 2, 2, (g, W, H) => {
  contactShadow(g, W / 2, H - 14, W / 2 - 4, 22);
  g.fillStyle = '#9ca3af'; g.beginPath(); g.ellipse(W / 2, H - 46, W / 2 - 10, 46, 0, 0, 7); g.fill();
  g.fillStyle = '#d1d5db'; g.beginPath(); g.ellipse(W / 2, H - 58, W / 2 - 10, 46, 0, 0, 7); g.fill(); g.strokeStyle = OUT; g.lineWidth = 3; g.stroke();
  g.fillStyle = vgrad(g, H - 100, H - 20, '#7dd3fc', '#0284c7'); g.beginPath(); g.ellipse(W / 2, H - 58, W / 2 - 24, 34, 0, 0, 7); g.fill();
  g.fillStyle = 'rgba(255,255,255,0.35)'; g.beginPath(); g.ellipse(W / 2 - 30, H - 68, 26, 6, 0, 0, 7); g.fill();
  g.fillStyle = '#e5e7eb'; g.fillRect(W / 2 - 10, 50, 20, H - 110);
  g.fillStyle = '#d1d5db'; g.beginPath(); g.ellipse(W / 2, 54, 34, 12, 0, 0, 7); g.fill(); g.strokeStyle = OUT; g.stroke();
  g.strokeStyle = 'rgba(186,230,253,0.9)'; g.lineWidth = 4;
  for (const s of [-1, 1]) { g.beginPath(); g.moveTo(W / 2, 40); g.quadraticCurveTo(W / 2 + s * 40, 0, W / 2 + s * 60, 90); g.stroke(); }
  g.fillStyle = '#bae6fd'; g.beginPath(); g.arc(W / 2, 36, 7, 0, 7); g.fill();
}, { up: 60 });
function rug(g, W, H, a, b, c) {
  fillRR(g, 6, 6, W - 12, H - 12, 22, a, 'rgba(0,0,0,0.25)', 3);
  g.strokeStyle = b; g.lineWidth = 8; rr(g, 26, 26, W - 52, H - 52, 16); g.stroke();
  g.strokeStyle = c; g.lineWidth = 3; rr(g, 42, 42, W - 84, H - 84, 10); g.stroke();
  g.fillStyle = b; g.globalAlpha = 0.5;
  for (let i = 0; i < 5; i++) { g.save(); g.translate(W / 2, H / 2); g.rotate((i * Math.PI) / 5); g.beginPath(); g.ellipse(0, 0, Math.min(W, H) * 0.28, 12, 0, 0, 7); g.fill(); g.restore(); }
  g.globalAlpha = 1;
}
add('rug', 6, 4, (g, W, H) => rug(g, W, H, '#8b3a3a', '#e2b065', '#f5deb3'), { flat: true });
add('rug_neon', 5, 3, (g, W, H) => rug(g, W, H, '#1e1b4b', '#22d3ee', '#f472b6'), { flat: true });

// ── on the wall (anchored at the wall's floor line) ──────────
add('board', 4, 1, (g, W, H) => {
  fillRR(g, 4, 6, W - 8, H - 12, 8, '#5b4630');
  fillRR(g, 14, 16, W - 28, H - 32, 4, '#c79a62', null);
  const R = rand(4), cols = ['#fef3c7', '#e0f2fe', '#fce7f3', '#ecfccb'];
  for (let i = 0; i < 7; i++) { const x = 26 + R() * (W - 110), y = 26 + R() * (H - 100); g.save(); g.translate(x, y); g.rotate((R() - 0.5) * 0.2); g.fillStyle = cols[i % 4]; g.fillRect(0, 0, 64, 50); g.fillStyle = 'rgba(0,0,0,0.25)'; for (let l = 0; l < 4; l++) g.fillRect(8, 12 + l * 9, 30 + R() * 18, 2); g.fillStyle = '#ef4444'; g.beginPath(); g.arc(32, 5, 4, 0, 7); g.fill(); g.restore(); }
}, { kind: 'wallitem', wallY: 0.15, tall: 1.3 });
add('window', 2, 1, (g, W, H) => {
  fillRR(g, 4, 4, W - 8, H - 8, 6, '#e8dcc8');
  g.fillStyle = 'rgba(0,0,0,0)';
  // the panes are cut out: the world draws the sky behind them.
  g.clearRect(16, 16, W - 32, H - 32);
  g.fillStyle = '#e8dcc8'; g.fillRect(W / 2 - 4, 16, 8, H - 32); g.fillRect(16, H / 2 - 4, W - 32, 8);
  g.fillStyle = '#9b5a3c'; g.fillRect(0, H - 14, W, 14);
}, { kind: 'wallitem', wallY: 0.2, tall: 1.1 });
add('painting', 2, 1, (g, W, H) => {
  fillRR(g, 10, 10, W - 20, H - 20, 4, '#c9a227');
  g.fillStyle = vgrad(g, 24, H - 24, '#fbbf24', '#7dd3fc'); g.fillRect(24, 24, W - 48, H - 48);
  g.fillStyle = '#3f8f4f'; g.beginPath(); g.moveTo(24, H - 24); g.lineTo(W * 0.4, H * 0.45); g.lineTo(W * 0.6, H * 0.62); g.lineTo(W * 0.75, H * 0.4); g.lineTo(W - 24, H - 24); g.closePath(); g.fill();
  g.fillStyle = '#fff7ed'; g.beginPath(); g.arc(W * 0.72, H * 0.32, 10, 0, 7); g.fill();
}, { kind: 'wallitem', wallY: 0.18, tall: 0.9 });
add('neon', 3, 1, (g, W, H) => {
  glow(g, W / 2, H / 2, W / 2, '#f472b6', 0.35);
  g.strokeStyle = '#f9a8d4'; g.lineWidth = 6; g.lineJoin = 'round'; g.shadowColor = '#f472b6'; g.shadowBlur = 16;
  g.font = 'bold 56px sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.strokeText('PLAY', W / 2, H / 2 + 2);
  g.shadowBlur = 0; g.fillStyle = '#fff0f8'; g.fillText('PLAY', W / 2, H / 2 + 2);
}, { kind: 'wallitem', wallY: 0.12, tall: 0.9 });
add('fireplace', 3, 1, (g, W, H) => {
  fillRR(g, 6, 10, W - 12, H - 10, 8, vgrad(g, 0, H, '#9ca3af', '#6b7280'));
  fillRR(g, 0, 0, W, 22, 6, '#e5e7eb');
  fillRR(g, 40, 50, W - 80, H - 50, 30, '#111827', null);
  glow(g, W / 2, H - 30, 90, '#ff9a3c', 0.8);
  for (const [x, h, c] of [[-26, 50, '#f97316'], [0, 70, '#fbbf24'], [24, 54, '#f97316'], [10, 36, '#fde68a']]) { g.fillStyle = c; g.beginPath(); g.moveTo(W / 2 + x - 18, H - 14); g.quadraticCurveTo(W / 2 + x, H - 14 - h * 1.4, W / 2 + x + 18, H - 14); g.closePath(); g.fill(); }
  g.fillStyle = '#5b3a22'; g.fillRect(W / 2 - 50, H - 18, 100, 10);
}, { kind: 'wallitem', wallY: 0.48, tall: 1.4 });
add('clock', 1, 1, (g, W, H) => {
  g.fillStyle = '#7a5236'; g.beginPath(); g.arc(W / 2, H / 2, 38, 0, 7); g.fill();
  g.fillStyle = '#fffbeb'; g.beginPath(); g.arc(W / 2, H / 2, 30, 0, 7); g.fill();
  g.fillStyle = '#1f2937'; for (let i = 0; i < 12; i++) { const a = (i / 12) * Math.PI * 2; g.fillRect(W / 2 + Math.cos(a) * 24 - 1.5, H / 2 + Math.sin(a) * 24 - 1.5, 3, 3); }
}, { kind: 'wallitem', wallY: 0.15, tall: 0.8 });
add('string_lights', 2, 1, (g, W, H) => {
  g.strokeStyle = '#1f2937'; g.lineWidth = 2; g.beginPath(); g.moveTo(0, 10); g.quadraticCurveTo(W / 2, 40, W, 10); g.stroke();
  for (let i = 1; i < 6; i++) { const t = i / 6, x = W * t, y = 10 + 120 * t * (1 - t); glow(g, x, y + 6, 18, '#ffe08a', 0.7); g.fillStyle = '#fff3c4'; g.beginPath(); g.arc(x, y + 6, 5, 0, 7); g.fill(); }
}, { kind: 'wallitem', wallY: 0.0, tall: 0.6 });
add('tv', 3, 1, (g, W, H) => {
  fillRR(g, 4, 4, W - 8, H - 8, 6, '#0b0d12');
  g.fillStyle = vgrad(g, 14, H - 14, '#1e3a8a', '#0e7490'); g.fillRect(14, 14, W - 28, H - 28);
}, { kind: 'wallitem', wallY: 0.2, tall: 1 });

/** Lay the sprites out in rows and draw them; returns the canvas and the frame list. */
function build() {
  const PAD = 4, MAXW = 2048;
  const placed = [];
  let x = PAD, y = PAD, rowH = 0;
  // Biggest first, to pack tighter.
  const order = sprites
    .map((s) => ({ s, w: Math.ceil(s.w * S + (s.pad || 0) * 2), h: Math.ceil((s.tall ? s.tall : s.h) * S + (s.up || 0)) }))
    .sort((a, b) => b.h - a.h);
  for (const o of order) {
    if (x + o.w + PAD > MAXW) { x = PAD; y += rowH + PAD; rowH = 0; }
    placed.push({ ...o, x, y });
    x += o.w + PAD;
    rowH = Math.max(rowH, o.h);
  }
  const canvas = document.createElement('canvas');
  canvas.width = MAXW;
  canvas.height = y + rowH + PAD;
  const g = canvas.getContext('2d');
  const frames = {};
  for (const p of placed) {
    g.save();
    g.beginPath(); g.rect(p.x, p.y, p.w, p.h); g.clip();
    g.translate(p.x + (p.s.pad || 0), p.y);
    p.s.draw(g, p.s.w * S, p.h);
    g.restore();
    frames[p.s.name] = [p.x, p.y, p.w, p.h, p.s.pad || 0, p.s.up || 0, p.s.wallY ?? 0];
  }
  return { canvas, frames, scale: S };
}
window.__hangoutArt = build;
