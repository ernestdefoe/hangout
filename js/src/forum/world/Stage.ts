import atlasData from './atlas.json';
import type { Exit, Item, Person, RoomState } from './api';

/**
 * The room, drawn on one canvas with the 2D context: no engine.
 *
 * Layers, back to front: the sky (outdoor rooms and behind windows), the
 * wall and floor (pre-rendered once per room and size), wall pictures and
 * doors, then furniture and people sorted by depth, then the light (an
 * ambient dark with holes cut for lamps, screens and the fire, following the
 * forum's clock), then name tags and speech bubbles on top.
 *
 * People walk smoothly along a grid path; the server only hears where they
 * were going. The canvas draws every frame while anything moves and drops
 * to ~20 fps for the flicker of fires and screens otherwise; it stops when
 * the tab is hidden.
 */

const T = 48; // world px per tile
const WALL = 3 * T; // back wall height
const SPEED = 3.6; // tiles per second
const PEOPLE = 1.22; // people are drawn a little larger than the furniture's scale, to read well
const ART: { v: string; scale: number; frames: Record<string, number[]> } = atlasData as any;
const A = ART.scale / T; // atlas px per world px

/** Furniture footprints and light, mirroring Furniture::CATALOG on the server. */
const CATALOG: Record<string, [number, number, boolean, boolean, number, string]> = {
  rug: [6, 4, false, false, 0, ''],
  rug_neon: [5, 3, false, false, 0, ''],
  sofa: [3, 1, true, false, 0, ''],
  armchair: [1, 1, true, false, 0, ''],
  coffee_table: [2, 1, true, false, 0, ''],
  table_round: [1, 1, true, false, 0, ''],
  plant: [1, 1, true, false, 0, ''],
  tall_plant: [1, 1, true, false, 0, ''],
  lamp: [1, 1, true, false, 4, '#ffc56b'],
  bookshelf: [2, 1, true, false, 0, ''],
  cabinet: [1, 1, true, false, 2, '#7dd3fc'],
  pinball: [1, 2, true, false, 2, '#f472b6'],
  claw: [1, 1, true, false, 2, '#fde047'],
  jukebox: [1, 1, true, false, 3, '#c084fc'],
  stool: [1, 1, false, false, 0, ''],
  beanbag: [1, 1, false, false, 0, ''],
  bar: [5, 1, true, false, 0, ''],
  bench: [2, 1, true, false, 0, ''],
  planter: [2, 1, true, false, 0, ''],
  telescope: [1, 1, true, false, 0, ''],
  hammock: [3, 1, true, false, 0, ''],
  deckchair: [1, 1, false, false, 0, ''],
  fountain: [2, 2, true, false, 2, '#a5f3fc'],
  board: [4, 1, false, true, 0, ''],
  window: [2, 1, false, true, 0, ''],
  painting: [2, 1, false, true, 0, ''],
  neon: [3, 1, false, true, 4, '#f472b6'],
  fireplace: [3, 1, false, true, 5, '#ff9a3c'],
  clock: [1, 1, false, true, 0, ''],
  string_lights: [20, 1, false, true, 3, '#ffe08a'],
};

export interface Ranky {
  color(rank: string): string;
  name(rank: string): string;
}

export interface StageHooks {
  walk(x: number, y: number): void;
  exit(exit: Exit): void;
  person(id: number): void;
  board(): void;
  ranks: Ranky;
  idleAfter: number;
  /** Seconds east of UTC on the forum's clock. */
  tz: number;
  meId: number | null;
}

interface Actor {
  p: Person;
  x: number;
  y: number;
  path: [number, number][];
  face: 1 | -1;
  walk: number; // walk-cycle phase
  anim: string | null;
  animUntil: number;
  bubble: { text: string; think: boolean; born: number; until: number } | null;
  fade: number; // 1 = here; <1 fading in or out
  leaving: boolean;
  img?: HTMLImageElement;
}

let atlas: HTMLImageElement | null = null;
const avatars = new Map<string, HTMLImageElement>();

function avatar(url?: string): HTMLImageElement | undefined {
  if (!url) return undefined;
  let img = avatars.get(url);
  if (!img) {
    img = new Image();
    img.decoding = 'async';
    img.src = url;
    avatars.set(url, img);
  }
  return img.complete && img.naturalWidth ? img : undefined;
}

export default class Stage {
  room: RoomState | null = null;
  actors = new Map<number, Actor>();
  private ctx: CanvasRenderingContext2D;
  private bg: HTMLCanvasElement | null = null;
  private light: HTMLCanvasElement = document.createElement('canvas');
  private blocked = new Set<number>();
  private scale = 1;
  private ox = 0;
  private oy = 0;
  private zoom = 1;
  private panX = 0;
  private panY = 0;
  private dpr = 1;
  private raf = 0;
  private last = 0;
  private lastDraw = 0;
  private hover: { kind: string; id: number } | null = null;
  private pointers = new Map<number, { x: number; y: number }>();
  private pinch: { d: number; z: number } | null = null;
  private ro: ResizeObserver;
  private font = 'system-ui, sans-serif';
  private target: { x: number; y: number; t: number } | null = null;
  private stopped = false;
  private manualUntil = 0;

  constructor(
    private canvas: HTMLCanvasElement,
    private hooks: StageHooks
  ) {
    this.ctx = canvas.getContext('2d')!;
    this.font = getComputedStyle(document.body).fontFamily || this.font;
    if (!atlas) {
      atlas = new Image();
      atlas.src = (window as any).app.forum.attribute('apiUrl') + '/hangout/art?v=' + ART.v;
    }
    atlas.addEventListener('load', this.rebuild);
    this.ro = new ResizeObserver(() => this.resize());
    this.ro.observe(canvas.parentElement!);
    canvas.addEventListener('pointerdown', this.down);
    canvas.addEventListener('pointermove', this.moved);
    canvas.addEventListener('pointerup', this.up);
    canvas.addEventListener('pointercancel', this.cancel);
    canvas.addEventListener('pointerleave', this.left);
    canvas.addEventListener('wheel', this.wheel, { passive: false });
    document.addEventListener('visibilitychange', this.visible);
    this.resize();
    this.frame(performance.now());
  }

  destroy() {
    this.stopped = true;
    cancelAnimationFrame(this.raf);
    this.ro.disconnect();
    atlas?.removeEventListener('load', this.rebuild);
    document.removeEventListener('visibilitychange', this.visible);
  }

  // ── what's in the room ────────────────────────────────────

  setRoom(room: RoomState) {
    this.room = room;
    this.actors.clear();
    this.blocked.clear();
    this.zoom = 1;
    this.panX = this.panY = 0;
    for (const item of room.items) {
      const [w, h, solid, wall] = CATALOG[item.k] || [1, 1, false, false];
      if (!solid || wall) continue;
      for (let dy = 0; dy < h; dy++) for (let dx = 0; dx < w; dx++) this.blocked.add((item.y + dy) * 256 + item.x + dx);
    }
    for (const p of room.people) this.add(p, false);
    this.rebuild();
  }

  add(p: Person, fadeIn = true) {
    const old = this.actors.get(p.id);
    if (old && !old.leaving) {
      old.p = p;
      return;
    }
    this.actors.set(p.id, {
      p,
      x: p.x ?? 0,
      y: p.y ?? 0,
      path: [],
      face: 1,
      walk: 0,
      anim: null,
      animUntil: 0,
      bubble: null,
      fade: fadeIn ? 0.01 : 1,
      leaving: false,
    });
  }

  update(p: Person) {
    const a = this.actors.get(p.id);
    if (a) a.p = { ...a.p, ...p, x: a.p.x, y: a.p.y };
  }

  remove(id: number) {
    const a = this.actors.get(id);
    if (a) a.leaving = true;
  }

  /** Walk someone to a tile along a path round the furniture. */
  walkTo(id: number, x: number, y: number) {
    const a = this.actors.get(id);
    if (!a) return;
    a.p.x = x;
    a.p.y = y;
    const from: [number, number] = [Math.round(a.x), Math.round(a.y)];
    a.path = this.findPath(from, [x, y]) || [[x, y]];
  }

  bubble(id: number, text: string, think = false) {
    const a = this.actors.get(id);
    if (!a) return;
    const now = performance.now();
    a.bubble = { text: text.length > 140 ? text.slice(0, 137) + '…' : text, think, born: now, until: now + Math.min(12000, 4000 + text.length * 70) };
  }

  animate(id: number, anim: string, ms = 2400) {
    const a = this.actors.get(id);
    if (!a) return;
    a.anim = anim;
    a.animUntil = performance.now() + ms;
  }

  // ── geometry ──────────────────────────────────────────────

  private worldW() {
    return (this.room?.w || 20) * T;
  }
  private worldH() {
    return (this.room?.h || 11) * T + WALL;
  }

  private resize = () => {
    const box = this.canvas.parentElement!.getBoundingClientRect();
    this.dpr = Math.min(2, window.devicePixelRatio || 1);
    this.canvas.width = Math.max(1, Math.round(box.width * this.dpr));
    this.canvas.height = Math.max(1, Math.round(box.height * this.dpr));
    this.canvas.style.width = box.width + 'px';
    this.canvas.style.height = box.height + 'px';
    this.rebuild();
  };

  private fit() {
    const cw = this.canvas.width;
    const ch = this.canvas.height;
    const contain = Math.min(cw / this.worldW(), ch / this.worldH());
    // Never smaller than ~30 CSS px a tile: on phones the camera follows you round a bigger room.
    const base = Math.max(contain, (this.dpr * 30) / T);
    this.scale = base * this.zoom;
    const vw = this.worldW() * this.scale;
    const vh = this.worldH() * this.scale;
    const me = this.hooks.meId ? this.actors.get(this.hooks.meId) : null;
    if (me && performance.now() > this.manualUntil) {
      this.panX = (this.worldW() / 2 - (me.x * T + T / 2)) * this.scale;
      this.panY = (this.worldH() / 2 - (WALL + me.y * T)) * this.scale;
    }
    const maxX = Math.max(0, (vw - cw) / 2);
    const maxY = Math.max(0, (vh - ch) / 2);
    this.panX = Math.max(-maxX, Math.min(maxX, this.panX));
    this.panY = Math.max(-maxY, Math.min(maxY, this.panY));
    this.ox = (cw - vw) / 2 + this.panX;
    this.oy = (ch - vh) / 2 + this.panY;
  }

  /** Screen (CSS px) → world px. */
  private toWorld(cx: number, cy: number) {
    const r = this.canvas.getBoundingClientRect();
    return { x: ((cx - r.left) * this.dpr - this.ox) / this.scale, y: ((cy - r.top) * this.dpr - this.oy) / this.scale };
  }

  private walkable(x: number, y: number) {
    return !!this.room && x >= 0 && y >= 0 && x < this.room.w && y < this.room.h && !this.blocked.has(y * 256 + x);
  }

  /** Breadth-first search on the floor grid, 8 directions, no corner cutting. */
  private findPath(from: [number, number], to: [number, number]): [number, number][] | null {
    if (!this.room || !this.walkable(to[0], to[1])) return null;
    const key = (x: number, y: number) => y * 256 + x;
    const prev = new Map<number, number>();
    const start = key(from[0], from[1]);
    const goal = key(to[0], to[1]);
    prev.set(start, -1);
    const queue = [start];
    const dirs = [
      [1, 0],
      [-1, 0],
      [0, 1],
      [0, -1],
      [1, 1],
      [1, -1],
      [-1, 1],
      [-1, -1],
    ];
    while (queue.length) {
      const k = queue.shift()!;
      if (k === goal) break;
      const x = k % 256;
      const y = Math.floor(k / 256);
      for (const [dx, dy] of dirs) {
        const nx = x + dx;
        const ny = y + dy;
        const nk = key(nx, ny);
        if (prev.has(nk) || !this.walkable(nx, ny)) continue;
        if (dx && dy && (!this.walkable(x + dx, y) || !this.walkable(x, y + dy))) continue;
        prev.set(nk, k);
        queue.push(nk);
      }
    }
    if (!prev.has(goal)) return null;
    const path: [number, number][] = [];
    for (let k = goal; k !== start && k !== -1; k = prev.get(k)!) path.unshift([k % 256, Math.floor(k / 256)]);
    return path;
  }

  /** The tile in front of a door. */
  doorTile(e: Exit): [number, number] {
    const r = this.room!;
    if (e.side === 'e') return [r.w - 1, Math.min(e.pos, r.h - 1)];
    if (e.side === 'w') return [0, Math.min(e.pos, r.h - 1)];
    if (e.side === 's') return [Math.min(e.pos, r.w - 1), r.h - 1];
    return [Math.min(e.pos, r.w - 1), 0];
  }

  /** Where a door is drawn, in world px. */
  private doorRect(e: Exit) {
    const r = this.room!;
    if (e.side === 'n') return { x: e.pos * T - 6, y: WALL - T * 2.6, w: T + 12, h: T * 2.6 };
    if (e.side === 's') return { x: e.pos * T, y: WALL + r.h * T - T / 2, w: T, h: T / 2 };
    const x = e.side === 'e' ? r.w * T - T / 2 : 0;
    return { x, y: WALL + e.pos * T - T * 1.4, w: T / 2, h: T * 2.4 };
  }

  // ── input ─────────────────────────────────────────────────

  private hit(cx: number, cy: number): { kind: string; id: number; x?: number; y?: number } | null {
    if (!this.room) return null;
    const w = this.toWorld(cx, cy);
    // People first, front-most first.
    const people = [...this.actors.values()].filter((a) => !a.leaving).sort((a, b) => b.y - a.y);
    for (const a of people) {
      const px = a.x * T + T / 2;
      const py = WALL + a.y * T + T * 0.8;
      if (w.x > px - 20 && w.x < px + 20 && w.y > py - 82 && w.y < py + 4) return { kind: 'person', id: a.p.id };
    }
    // Doors get a generous margin: a side door is a thin strip, too small for a finger.
    const pad = T * 0.6;
    for (const e of this.room.exits) {
      const r = this.doorRect(e);
      if (w.x >= r.x - pad && w.x <= r.x + r.w + pad && w.y >= r.y - pad && w.y <= r.y + r.h + pad) return { kind: 'exit', id: e.id };
    }
    for (const item of this.room.items) {
      if (item.k !== 'board') continue;
      if (w.x >= item.x * T && w.x <= (item.x + 4) * T && w.y >= WALL * 0.15 && w.y <= WALL * 0.15 + T * 1.3) return { kind: 'board', id: item.id };
    }
    const tx = Math.floor(w.x / T);
    const ty = Math.floor((w.y - WALL) / T);
    if (tx >= 0 && ty >= 0 && tx < this.room.w && ty < this.room.h) return { kind: 'floor', id: 0, x: tx, y: ty };
    return null;
  }

  private down = (e: PointerEvent) => {
    this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (this.pointers.size === 2) {
      const [a, b] = [...this.pointers.values()];
      this.pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), z: this.zoom };
    }
    this.canvas.setPointerCapture?.(e.pointerId);
  };

  private moved = (e: PointerEvent) => {
    const p = this.pointers.get(e.pointerId);
    if (p && this.pointers.size === 2 && this.pinch) {
      p.x = e.clientX;
      p.y = e.clientY;
      const [a, b] = [...this.pointers.values()];
      this.setZoom((this.pinch.z * Math.hypot(a.x - b.x, a.y - b.y)) / this.pinch.d);
      return;
    }
    if (p && (Math.abs(e.clientX - p.x) > 6 || Math.abs(e.clientY - p.y) > 6)) {
      this.manualUntil = performance.now() + 6000;
      this.panX += (e.clientX - p.x) * this.dpr;
      this.panY += (e.clientY - p.y) * this.dpr;
      p.x = e.clientX;
      p.y = e.clientY;
      (p as any).dragged = true;
      return;
    }
    if (e.pointerType === 'mouse') {
      const h = this.hit(e.clientX, e.clientY);
      this.hover = h && h.kind !== 'floor' ? h : null;
      this.canvas.style.cursor = this.hover ? 'pointer' : h ? 'crosshair' : '';
    }
  };

  private up = (e: PointerEvent) => {
    const p = this.pointers.get(e.pointerId);
    this.pointers.delete(e.pointerId);
    if (this.pointers.size < 2) this.pinch = null;
    if (!p || (p as any).dragged || this.pointers.size) return;
    const h = this.hit(e.clientX, e.clientY);
    if (!h) return;
    if (h.kind === 'person') this.hooks.person(h.id);
    else if (h.kind === 'board') this.hooks.board();
    else if (h.kind === 'exit') {
      const exit = this.room!.exits.find((x) => x.id === h.id)!;
      const [x, y] = this.doorTile(exit);
      this.walkSelf(x, y, () => this.hooks.exit(exit));
    } else if (h.kind === 'floor') this.walkSelf(h.x!, h.y!);
  };

  /** Walk to a door and go through it (the door buttons and the "doors to…" line). */
  goTo(exit: Exit) {
    const [x, y] = this.doorTile(exit);
    const me = this.hooks.meId && this.actors.get(this.hooks.meId);
    if (me && Math.round(me.x) === x && Math.round(me.y) === y && !me.path.length) this.hooks.exit(exit);
    else this.walkSelf(x, y, () => this.hooks.exit(exit));
  }

  private cancel = (e: PointerEvent) => {
    this.pointers.delete(e.pointerId);
    this.pinch = null;
  };

  private left = () => {
    this.hover = null;
  };

  private wheel = (e: WheelEvent) => {
    if (!e.ctrlKey && !e.metaKey && Math.abs(e.deltaY) < 40) return;
    e.preventDefault();
    this.setZoom(this.zoom * (e.deltaY < 0 ? 1.15 : 1 / 1.15));
  };

  setZoom(z: number) {
    this.zoom = Math.max(1, Math.min(3, z));
    this.rebuild();
  }

  private arrive: (() => void) | null = null;

  /** Walk our own character (click, tap or arrow keys), then tell the server where we ended up. */
  walkSelf(x: number, y: number, then?: () => void) {
    const me = this.hooks.meId && this.actors.get(this.hooks.meId);
    if (!me) return;
    // One person per tile: someone already there (or headed there) moves our target to the nearest free tile.
    if (!then) [x, y] = this.nearestFree(x, y, me.p.id);
    const path = this.findPath([Math.round(me.x), Math.round(me.y)], [x, y]);
    if (!path) return;
    this.target = { x, y, t: performance.now() };
    me.path = path;
    me.p.x = x;
    me.p.y = y;
    this.arrive = then || null;
    if (path.length) this.hooks.walk(x, y);
    else if (then) then();
  }

  /** The nearest walkable tile nobody else stands on or is walking to (Furniture + people), spiralling out. */
  nearestFree(x: number, y: number, mover: number): [number, number] {
    const taken = new Set<number>();
    for (const a of this.actors.values()) {
      if (a.p.id === mover || a.leaving) continue;
      taken.add((a.p.y ?? Math.round(a.y)) * 256 + (a.p.x ?? Math.round(a.x)));
      taken.add(Math.round(a.y) * 256 + Math.round(a.x));
    }
    const r = this.room!;
    for (let d = 0; d < Math.max(r.w, r.h); d++) {
      for (let dy = -d; dy <= d; dy++) {
        for (let dx = -d; dx <= d; dx++) {
          if (Math.max(Math.abs(dx), Math.abs(dy)) !== d) continue;
          const tx = x + dx;
          const ty = y + dy;
          if (this.walkable(tx, ty) && !taken.has(ty * 256 + tx)) return [tx, ty];
        }
      }
    }
    return [x, y];
  }

  step(dx: number, dy: number) {
    const me = this.hooks.meId && this.actors.get(this.hooks.meId);
    if (!me) return;
    const x = (me.path.length ? me.p.x! : Math.round(me.x)) + dx;
    const y = (me.path.length ? me.p.y! : Math.round(me.y)) + dy;
    if (!this.walkable(x, y)) {
      // Walking into a door goes through it.
      const exit = this.room!.exits.find((e) => {
        const [ex, ey] = this.doorTile(e);
        const out = e.side === 'n' ? dy < 0 : e.side === 's' ? dy > 0 : e.side === 'e' ? dx > 0 : dx < 0;
        return out && ex === x - dx && ey === y - dy;
      });
      if (exit) this.hooks.exit(exit);
      return;
    }
    // Someone is standing there: stay put rather than jump to another tile.
    const [fx, fy] = this.nearestFree(x, y, me.p.id);
    if (fx !== x || fy !== y) return;
    this.walkSelf(x, y);
  }

  private visible = () => {
    if (!document.hidden) this.frame(performance.now());
  };

  // ── drawing ───────────────────────────────────────────────

  private rebuild = () => {
    this.bg = null;
    this.fit();
  };

  private sprite(g: CanvasRenderingContext2D, name: string, dx: number, dy: number, dw?: number, dh?: number) {
    const f = ART.frames[name];
    if (!f || !atlas?.complete || !atlas.naturalWidth) return;
    const [sx, sy, sw, sh] = f;
    g.drawImage(atlas, sx, sy, sw, sh, dx, dy, dw ?? sw / A, dh ?? sh / A);
  }

  /** A sprite as a repeating fill (atlas px). */
  private pattern(g: CanvasRenderingContext2D, name: string): CanvasPattern | null {
    const f = ART.frames[name];
    if (!f || !atlas) return null;
    const c = document.createElement('canvas');
    c.width = f[2];
    c.height = f[3];
    c.getContext('2d')!.drawImage(atlas, f[0], f[1], f[2], f[3], 0, 0, f[2], f[3]);
    return g.createPattern(c, 'repeat');
  }

  /** The wall and floor, drawn once per room and size into an offscreen canvas. */
  private background(): HTMLCanvasElement | null {
    if (this.bg) return this.bg;
    if (!this.room || !atlas?.complete || !atlas.naturalWidth) return null;
    const r = this.room;
    const c = document.createElement('canvas');
    const s = Math.min(2, this.scale);
    c.width = Math.ceil(this.worldW() * s);
    c.height = Math.ceil(this.worldH() * s);
    const g = c.getContext('2d')!;
    g.scale(s, s);
    // Floor: a repeating pattern cut from the atlas, so no seams between tiles.
    const floor = this.pattern(g, 'floor_' + (r.look.floor || 'oak'));
    if (floor) {
      g.save();
      g.translate(0, WALL);
      g.scale(1 / A, 1 / A);
      g.fillStyle = floor;
      g.fillRect(0, 0, r.w * T * A, r.h * T * A);
      g.restore();
    }
    // Soft shadow along the foot of the wall.
    const sh = g.createLinearGradient(0, WALL, 0, WALL + T);
    sh.addColorStop(0, 'rgba(0,0,0,0.35)');
    sh.addColorStop(1, 'rgba(0,0,0,0)');
    g.fillStyle = sh;
    g.fillRect(0, WALL, r.w * T, T);
    // Wall (not outdoors: the sky is drawn live there)
    if (!r.look.outdoor) {
      const wall = 'wall_' + (r.look.wall || 'dusk');
      for (let x = 0; x < r.w; x += 2) this.sprite(g, wall, x * T, 0, 2 * T, WALL);
    }
    // Rugs lie flat on the floor.
    for (const item of r.items) {
      if (item.k.startsWith('rug')) {
        const [w, h] = CATALOG[item.k];
        this.sprite(g, item.k, item.x * T, WALL + item.y * T, w * T, h * T);
      }
    }
    // Doormats under side and front doors
    for (const e of r.exits) {
      if (e.side === 'n') continue;
      const [x, y] = this.doorTile(e);
      this.sprite(g, 'doormat', x * T, WALL + y * T, T, T);
    }
    // Vignette at the floor's edges
    const v = g.createRadialGradient((r.w * T) / 2, WALL + (r.h * T) / 2, T * 3, (r.w * T) / 2, WALL + (r.h * T) / 2, r.w * T * 0.7);
    v.addColorStop(0, 'rgba(0,0,0,0)');
    v.addColorStop(1, 'rgba(0,0,0,0.28)');
    g.fillStyle = v;
    g.fillRect(0, WALL, r.w * T, r.h * T);
    this.bg = c;
    return c;
  }

  /** 0 = full day, 1 = deep night, on the forum's clock. */
  night(): number {
    const h = ((((Date.now() / 1000 + this.hooks.tz) % 86400) + 86400) % 86400) / 3600;
    if (h >= 7.5 && h <= 17.5) return 0;
    if (h >= 20 || h <= 5) return 1;
    if (h < 7.5) return (7.5 - h) / 2.5;
    return (h - 17.5) / 2.5;
  }

  /** The sky behind the rooftop and the windows. */
  private sky(g: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, t: number, skyline: boolean) {
    const n = this.night();
    const top = n > 0.5 ? mix('#3b5b8c', '#070b1f', (n - 0.5) * 2) : mix('#5fb4f2', '#3b5b8c', n * 2);
    const bot = n > 0.5 ? mix('#f59e6b', '#1b2347', (n - 0.5) * 2) : mix('#c7e8ff', '#f59e6b', n * 2);
    const gr = g.createLinearGradient(0, y, 0, y + h);
    gr.addColorStop(0, top);
    gr.addColorStop(1, bot);
    g.fillStyle = gr;
    g.fillRect(x, y, w, h);
    g.save();
    g.beginPath();
    g.rect(x, y, w, h);
    g.clip();
    if (n > 0.3) {
      g.fillStyle = '#e6edff';
      for (let i = 0; i < 60; i++) {
        const sx = x + ((i * 97.13) % w);
        const sy = y + ((i * 53.7) % (h * 0.7));
        g.globalAlpha = (n - 0.3) * (0.5 + 0.5 * Math.sin(t / 900 + i));
        g.fillRect(sx, sy, i % 5 ? 1.5 : 2.5, i % 5 ? 1.5 : 2.5);
      }
      g.globalAlpha = 1;
    }
    // Sun by day, moon by night.
    const cx = x + w * 0.78;
    const cy = y + h * (0.25 + 0.3 * Math.abs(0.5 - n));
    g.fillStyle = n > 0.5 ? '#f5efd6' : '#fff4c2';
    g.shadowColor = g.fillStyle;
    g.shadowBlur = 24;
    g.beginPath();
    g.arc(cx, cy, Math.min(26, h * 0.12), 0, Math.PI * 2);
    g.fill();
    g.shadowBlur = 0;
    if (skyline) {
      const base = y + h;
      for (let i = 0; i < 18; i++) {
        const bw = 40 + ((i * 37) % 50);
        const bh = 40 + ((i * 71) % 70);
        const bx = x + i * (w / 16) - 20;
        g.fillStyle = mix(i % 2 ? '#7f93b3' : '#6c7fa0', i % 2 ? '#141a33' : '#1b2347', n);
        g.fillRect(bx, base - bh, bw, bh);
        if (n > 0.4) {
          g.fillStyle = `rgba(255,214,122,${(n - 0.4) * 1.4})`;
          for (let wy = base - bh + 8; wy < base - 8; wy += 12)
            for (let wx = bx + 6; wx < bx + bw - 6; wx += 10) if ((wx * 7 + wy * 3 + i) % 5 < 2) g.fillRect(wx, wy, 4, 5);
        }
      }
    }
    g.restore();
  }

  private frame = (now: number) => {
    if (this.stopped) return;
    cancelAnimationFrame(this.raf);
    if (document.hidden) return;
    this.raf = requestAnimationFrame(this.frame);
    const moving = [...this.actors.values()].some((a) => a.path.length || a.fade < 1 || a.leaving || a.anim || a.bubble);
    // Still rooms draw ~20 times a second, for flicker and twinkle.
    if (!moving && now - this.lastDraw < 50) return;
    const dt = Math.min(0.1, (now - (this.last || now)) / 1000);
    this.last = now;
    this.lastDraw = now;
    this.tick(dt, now);
    this.draw(now);
  };

  private tick(dt: number, now: number) {
    for (const [id, a] of this.actors) {
      if (a.path.length) {
        const [tx, ty] = a.path[0];
        const dx = tx - a.x;
        const dy = ty - a.y;
        const d = Math.hypot(dx, dy);
        const step = SPEED * dt;
        if (Math.abs(dx) > 0.01) a.face = dx > 0 ? 1 : -1;
        if (d <= step) {
          a.x = tx;
          a.y = ty;
          a.path.shift();
          if (!a.path.length && id === this.hooks.meId && this.arrive) {
            const go = this.arrive;
            this.arrive = null;
            go();
          }
        } else {
          a.x += (dx / d) * step;
          a.y += (dy / d) * step;
        }
        a.walk += dt * 10;
      } else a.walk = 0;
      if (a.leaving) {
        a.fade -= dt * 3;
        if (a.fade <= 0) this.actors.delete(id);
      } else if (a.fade < 1) a.fade = Math.min(1, a.fade + dt * 3);
      if (a.anim && now > a.animUntil) a.anim = null;
      if (a.bubble && now > a.bubble.until + 400) a.bubble = null;
    }
  }

  private draw(now: number) {
    const g = this.ctx;
    const r = this.room;
    g.setTransform(1, 0, 0, 1, 0, 0);
    g.fillStyle = '#0d0f15';
    g.fillRect(0, 0, this.canvas.width, this.canvas.height);
    if (!r) return;
    this.fit();
    g.setTransform(this.scale, 0, 0, this.scale, this.ox, this.oy);

    // Sky behind the rooftop and the windows.
    if (r.look.outdoor) this.sky(g, 0, 0, r.w * T, WALL, now, true);
    for (const item of r.items) if (item.k === 'window') this.sky(g, item.x * T + 8, WALL * 0.2 + 8, 2 * T - 16, T * 1.1 - 16, now, false);

    const bg = this.background();
    if (bg) {
      if (r.look.outdoor)
        g.drawImage(
          bg,
          0,
          WALL * (bg.height / this.worldH()),
          bg.width,
          bg.height - WALL * (bg.height / this.worldH()),
          0,
          WALL,
          this.worldW(),
          this.worldH() - WALL
        );
      else g.drawImage(bg, 0, 0, this.worldW(), this.worldH());
    }
    if (r.look.outdoor) for (let x = 0; x < r.w; x += 2) this.sprite(g, 'railing', x * T, WALL - T * 1.2 + 6, 2 * T, T * 1.2);

    // Wall pictures, then doors.
    for (const item of r.items) {
      const f = ART.frames[item.k];
      const cat = CATALOG[item.k];
      if (!f || !cat || !cat[3]) continue;
      if (item.k === 'string_lights') {
        for (let x = 0; x < r.w; x += 2) this.sprite(g, 'string_lights', x * T, 2, 2 * T, T * 0.6);
        continue;
      }
      this.sprite(g, item.k, item.x * T, WALL * f[6], cat[0] * T, f[3] / A);
    }
    for (const e of r.exits) this.drawDoor(g, e);

    // Furniture and people, back to front.
    const things: { y: number; draw: () => void }[] = [];
    for (const item of r.items) {
      const cat = CATALOG[item.k];
      if (!cat || cat[3] || item.k.startsWith('rug')) continue;
      things.push({ y: item.y + cat[1], draw: () => this.drawItem(g, item, now) });
    }
    for (const a of this.actors.values()) things.push({ y: a.y + 1.01, draw: () => this.drawPerson(g, a, now) });
    things.sort((p, q) => p.y - q.y);
    for (const t of things) t.draw();

    this.drawLight(g, now);

    // Where we're walking to.
    if (this.target && now - this.target.t < 900) {
      const k = (now - this.target.t) / 900;
      g.strokeStyle = `rgba(255,255,255,${0.7 * (1 - k)})`;
      g.lineWidth = 2;
      g.beginPath();
      g.ellipse(this.target.x * T + T / 2, WALL + this.target.y * T + T * 0.75, 10 + k * 12, 5 + k * 6, 0, 0, Math.PI * 2);
      g.stroke();
    }

    // Tags, bubbles and door names over everything, in screen pixels so they stay crisp and readable at any size.
    g.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    const sorted = [...this.actors.values()].sort((p, q) => p.y - q.y);
    for (const e of r.exits) this.drawDoorLabel(g, e);
    // Only people in view: a tag held at the edge for someone off camera would float with nobody under it.
    const seen = sorted.filter((a) => {
      const x = this.sx(a.x * T + T / 2);
      return x > -T / 2 && x < this.cssW() + T / 2;
    });
    // Name tags: front people keep their place; a tag behind that would overlap is lifted clear of it.
    const tags: { x: number; y: number; w: number; h: number }[] = [];
    const placed = [...seen].reverse().map((a) => ({ a, at: this.placeTag(g, a, tags) }));
    for (const { a, at } of placed.reverse()) this.drawTag(g, a, at);
    // Front people's bubbles first; anyone behind whose bubble would overlap is lifted above it.
    const taken: { x: number; y: number; w: number; h: number }[] = [...tags];
    for (const a of [...seen].reverse()) this.drawBubble(g, a, now, taken);
  }

  /** World px → CSS px on the canvas. */
  private sx(x: number) {
    return (this.ox + x * this.scale) / this.dpr;
  }
  private sy(y: number) {
    return (this.oy + y * this.scale) / this.dpr;
  }
  private cssW() {
    return this.canvas.width / this.dpr;
  }

  private drawDoor(g: CanvasRenderingContext2D, e: Exit) {
    const d = this.doorRect(e);
    if (e.side === 'n') this.sprite(g, this.room!.look.outdoor || /roof|stair/i.test(e.name) ? 'door_open' : 'door_n', d.x, d.y, d.w, d.h);
    else if (e.side !== 's') {
      g.save();
      if (e.side === 'w') {
        g.translate(d.x + d.w, 0);
        g.scale(-1, 1);
        g.translate(-d.x, 0);
      }
      this.sprite(g, 'door_side', d.x, d.y, d.w, d.h);
      g.restore();
    }
    if (this.hover?.kind === 'exit' && this.hover.id === e.id) {
      g.strokeStyle = 'rgba(255,230,160,0.9)';
      g.lineWidth = 2;
      g.strokeRect(d.x + 1, d.y + 1, d.w - 2, d.h - 2);
    }
  }

  private drawDoorLabel(g: CanvasRenderingContext2D, e: Exit) {
    const d = this.doorRect(e);
    const text = e.name;
    g.font = `600 11px ${this.font}`;
    const w = g.measureText(text).width + 14;
    let x = this.sx(d.x + d.w / 2) - w / 2;
    let y = this.sy(d.y) - 10;
    if (e.side === 'e') x = this.sx(d.x) - w + 6;
    if (e.side === 'w') x = this.sx(d.x + d.w) - 6;
    if (e.side === 's') y = this.sy(d.y) - 12;
    x = Math.max(2, Math.min(this.cssW() - w - 2, x));
    y = Math.max(11, y);
    pill(g, x, y - 9, w, 18, 'rgba(18,20,26,0.78)');
    g.fillStyle = '#ffe9b8';
    g.textAlign = 'left';
    g.textBaseline = 'middle';
    g.fillText(text, x + 7, y);
  }

  private drawItem(g: CanvasRenderingContext2D, item: Item, now: number) {
    const f = ART.frames[item.k];
    const cat = CATALOG[item.k];
    if (!f || !cat) return;
    const w = cat[0] * T;
    const h = f[3] / A;
    const x = item.x * T - f[4] / A;
    const y = WALL + (item.y + cat[1]) * T - h;
    this.sprite(g, item.k, x, y, f[2] / A, h);
    // A little life: the fountain's ripples.
    if (item.k === 'fountain') {
      const cx = item.x * T + T;
      const cy = WALL + (item.y + 2) * T - 28;
      g.strokeStyle = 'rgba(255,255,255,0.45)';
      g.lineWidth = 1.2;
      for (let i = 0; i < 2; i++) {
        const k = (now / 1600 + i / 2) % 1;
        g.globalAlpha = 1 - k;
        g.beginPath();
        g.ellipse(cx, cy, 8 + k * 26, 3 + k * 9, 0, 0, Math.PI * 2);
        g.stroke();
      }
      g.globalAlpha = 1;
    }
    void w;
  }

  /** A person: shadow, legs, body, arms and head (their avatar, else their initial). */
  private drawPerson(g: CanvasRenderingContext2D, a: Actor, now: number) {
    const p = a.p;
    const cx = a.x * T + T / 2;
    const foot = WALL + a.y * T + T * 0.8;
    const idle = !a.path.length && p.act && Date.now() / 1000 - p.act > this.hooks.idleAfter;
    const away = p.afk !== undefined && p.afk !== null;
    const anim = a.anim;
    const t = now / 1000;
    let bob = a.path.length ? Math.abs(Math.sin(a.walk)) * 3 : Math.sin(t * 2 + p.id) * 0.6;
    let sway = 0;
    let lift = 0;
    if (anim === 'dance') {
      bob = Math.abs(Math.sin(t * 8)) * 6;
      sway = Math.sin(t * 6) * 0.18;
    } else if (anim === 'bounce' || anim === 'cheer' || anim === 'laugh')
      bob = Math.abs(Math.sin(t * (anim === 'laugh' ? 14 : 9))) * (anim === 'laugh' ? 3 : 8);
    else if (anim === 'sit' || (away && !a.path.length)) lift = -8;
    const sit = lift < 0;

    g.save();
    g.globalAlpha = Math.max(0, Math.min(1, a.fade)) * (idle || away ? 0.82 : 1);
    // Shadow
    g.fillStyle = 'rgba(0,0,0,0.28)';
    g.beginPath();
    g.ellipse(cx, foot, 18, 6.5, 0, 0, Math.PI * 2);
    g.fill();

    g.translate(cx, foot - bob - lift);
    g.rotate(sway);
    g.scale(a.face * PEOPLE, PEOPLE);
    const body = p.col || '#e8833a';
    const outline = 'rgba(20,16,30,0.6)';

    // Legs
    const swing = a.path.length ? Math.sin(a.walk) * 5 : 0;
    g.fillStyle = '#2b2f3a';
    if (sit) {
      roundRect(g, -9, -10, 8, 8, 3);
      g.fill();
      roundRect(g, 1, -10, 8, 8, 3);
      g.fill();
    } else {
      roundRect(g, -8 + swing * 0.4, -12, 7, 12 + lift, 3);
      g.fill();
      roundRect(g, 1 - swing * 0.4, -12, 7, 12 + lift, 3);
      g.fill();
    }
    // Body
    const grad = g.createLinearGradient(0, -38, 0, -8);
    grad.addColorStop(0, shade(body, 0.18));
    grad.addColorStop(1, shade(body, -0.22));
    g.fillStyle = grad;
    roundRect(g, -13, -38, 26, 28, 10);
    g.fill();
    g.strokeStyle = outline;
    g.lineWidth = 2;
    g.stroke();
    // Arms
    g.fillStyle = shade(body, -0.1);
    const wave = anim === 'wave' || anim === 'greet' || anim === 'bye';
    const hug = anim === 'hug' || anim === 'cuddle' || anim === 'comfort';
    const armSwing = a.path.length ? Math.sin(a.walk) * 0.5 : 0;
    g.save();
    g.translate(-12, -34);
    g.rotate(hug ? -1.2 : 0.15 - armSwing);
    roundRect(g, -4, 0, 7, 18, 3.5);
    g.fill();
    g.restore();
    g.save();
    g.translate(12, -34);
    g.rotate(wave ? -2.6 + Math.sin(t * 12) * 0.35 : hug ? 1.2 : anim === 'cheer' ? -2.8 : -0.15 + armSwing);
    roundRect(g, -3, 0, 7, 18, 3.5);
    g.fill();
    g.fillStyle = '#f3d3b5';
    g.beginPath();
    g.arc(0.5, 18, 3.6, 0, Math.PI * 2);
    g.fill();
    g.restore();

    // Head
    g.scale(a.face, 1);
    const hy = -50;
    const img = avatar(p.av);
    g.beginPath();
    g.arc(0, hy, 14, 0, Math.PI * 2);
    if (img) {
      g.save();
      g.clip();
      g.drawImage(img, -14, hy - 14, 28, 28);
      g.restore();
    } else {
      g.fillStyle = '#f3d3b5';
      g.fill();
      g.fillStyle = '#3d2e1f';
      g.font = `700 13px ${this.font}`;
      g.textAlign = 'center';
      g.textBaseline = 'middle';
      g.fillText((p.n || '?').charAt(0).toUpperCase(), 0, hy + 1);
    }
    g.beginPath();
    g.arc(0, hy, 14, 0, Math.PI * 2);
    g.strokeStyle = this.hover?.kind === 'person' && this.hover.id === p.id ? '#ffe9b8' : outline;
    g.lineWidth = this.hover?.kind === 'person' && this.hover.id === p.id ? 3 : 2;
    g.stroke();
    g.restore();

    // Dozing: zZ drifting up.
    if ((idle || away) && !a.path.length && !a.bubble) {
      g.save();
      g.fillStyle = '#e9ebf1';
      g.font = `700 11px ${this.font}`;
      for (let i = 0; i < 2; i++) {
        const k = (t / 2 + i / 2) % 1;
        g.globalAlpha = 1 - k;
        g.fillText(i ? 'Z' : 'z', cx + 16 + k * 8, foot - 88 - k * 16);
      }
      g.restore();
    }
  }

  /** Where a name tag goes: over the head, or lifted above any tag already placed there. */
  private placeTag(g: CanvasRenderingContext2D, a: Actor, taken: { x: number; y: number; w: number; h: number }[]) {
    const { w } = this.tagSize(g, a);
    const h = 17;
    const x = Math.max(2, Math.min(this.cssW() - w - 2, this.sx(a.x * T + T / 2) - w / 2));
    const home = this.sy(WALL + a.y * T + T * 0.8 - 86) - 20 - (a.anim === 'dance' ? 4 : 0);
    let y = home;
    for (let i = 0; i < 5; i++) {
      const hit = taken.find((r) => x < r.x + r.w + 2 && x + w + 2 > r.x && y < r.y + r.h + 2 && y + h + 2 > r.y);
      if (!hit) break;
      y = hit.y - h - 3;
    }
    y = Math.max(2, y);
    taken.push({ x, y, w, h });
    return { x, y, home };
  }

  private tagSize(g: CanvasRenderingContext2D, a: Actor) {
    const p = a.p;
    const rank = this.hooks.ranks.name(p.rk);
    g.font = `600 11px ${this.font}`;
    const nameW = g.measureText(p.n).width;
    // The rank in small capitals: at 10 px a lower-case "Deity" reads as "Delty".
    g.font = `700 9px ${this.font}`;
    const showRank = p.rk !== 'mortal' && !!rank;
    const rankW = showRank ? g.measureText(rank.toUpperCase()).width + rank.length * 0.6 + 8 : 0;
    return { w: Math.ceil(nameW + rankW + 15), nameW, rank, showRank };
  }

  private drawTag(g: CanvasRenderingContext2D, a: Actor, at: { x: number; y: number; home: number }) {
    const p = a.p;
    const { w, nameW, rank, showRank } = this.tagSize(g, a);
    const { x, y } = at;
    const me = p.id === this.hooks.meId;
    g.save();
    g.globalAlpha = Math.max(0, Math.min(1, a.fade));
    // A lifted tag keeps a thin line down to its owner, so it's clear whose it is.
    if (y < at.home - 4) {
      const cx = this.sx(a.x * T + T / 2);
      g.lineCap = 'round';
      g.beginPath();
      g.moveTo(cx, y + 17);
      g.lineTo(cx, at.home + 14);
      g.strokeStyle = 'rgba(18,20,26,0.85)';
      g.lineWidth = 3.5;
      g.stroke();
      g.strokeStyle = me ? '#e8833a' : '#ffffff';
      g.lineWidth = 1.5;
      g.stroke();
    }
    pill(g, x, y, w, 17, me ? 'rgba(232,131,58,0.95)' : 'rgba(18,20,26,0.86)');
    g.textBaseline = 'middle';
    g.textAlign = 'left';
    g.font = `600 11px ${this.font}`;
    g.fillStyle = me ? '#1a1d25' : '#ffffff';
    g.fillText(p.n, x + 7, y + 9);
    if (showRank) {
      g.font = `700 9px ${this.font}`;
      g.fillStyle = me ? '#1a1d25' : this.hooks.ranks.color(p.rk);
      if ('letterSpacing' in g) (g as any).letterSpacing = '0.6px';
      g.fillText(rank.toUpperCase(), x + 7 + nameW + 7, y + 9.5);
    }
    g.restore();
  }

  private drawBubble(g: CanvasRenderingContext2D, a: Actor, now: number, taken: { x: number; y: number; w: number; h: number }[]) {
    const b = a.bubble;
    if (!b) return;
    const age = now - b.born;
    const pop = Math.min(1, age / 180);
    const scale = 0.6 + 0.4 * easeOutBack(pop);
    const alpha = now > b.until ? Math.max(0, 1 - (now - b.until) / 400) : 1;
    g.font = `${b.think ? 'italic ' : ''}13px ${this.font}`;
    const lines = wrap(g, b.text, 190);
    const lh = 17;
    const w = Math.min(214, Math.max(...lines.map((l) => g.measureText(l).width)) + 22);
    const h = lines.length * lh + 14;
    const cx = this.sx(a.x * T + T / 2);
    const bottom = this.sy(WALL + a.y * T + T * 0.8 - 86) - 28 - (now - b.born < 400 ? (1 - pop) * 6 : 0);
    let x = cx - w / 2;
    x = Math.max(4, Math.min(this.cssW() - w - 4, x));
    let y = bottom - h;
    for (let i = 0; i < 6; i++) {
      const hit = taken.find((r) => x < r.x + r.w + 4 && x + w + 4 > r.x && y < r.y + r.h + 4 && y + h + 4 > r.y);
      if (!hit) break;
      y = hit.y - h - 6;
    }
    // Never above the top of the stage: lower it over the head instead (the tail is dropped then).
    y = Math.max(4, y);
    taken.push({ x, y, w, h });
    g.save();
    g.globalAlpha = alpha;
    g.translate(cx, bottom);
    g.scale(scale, scale);
    g.translate(-cx, -bottom);
    if (b.think) {
      g.fillStyle = 'rgba(255,255,255,0.92)';
      g.strokeStyle = '#8a90a2';
      g.setLineDash([4, 3]);
      g.lineWidth = 1.6;
      roundRect(g, x, y, w, h, 14);
      g.fill();
      g.stroke();
      g.setLineDash([]);
      for (const [dx, dy, rr] of [
        [0, 6, 4],
        [4, 13, 2.6],
      ] as const) {
        g.beginPath();
        g.arc(cx + dx, bottom + dy, rr, 0, Math.PI * 2);
        g.fill();
        g.stroke();
      }
      g.fillStyle = '#3d4252';
    } else {
      g.shadowColor = 'rgba(0,0,0,0.3)';
      g.shadowBlur = 10;
      g.shadowOffsetY = 3;
      g.fillStyle = '#ffffff';
      roundRect(g, x, y, w, h, 11);
      g.fill();
      if (y + h >= bottom - 1) {
        g.beginPath();
        g.moveTo(cx - 6, bottom - 1);
        g.lineTo(cx, bottom + 8);
        g.lineTo(cx + 6, bottom - 1);
        g.fill();
      }
      g.shadowColor = 'transparent';
      g.fillStyle = '#1a1d25';
    }
    g.textAlign = 'left';
    g.textBaseline = 'top';
    lines.forEach((l, i) => g.fillText(l, x + 11, y + 8 + i * lh));
    g.restore();
  }

  /** Ambient dark with light pools cut out of it, then a warm additive glow. */
  private drawLight(g: CanvasRenderingContext2D, now: number) {
    const r = this.room!;
    const n = this.night();
    let dark = r.look.outdoor ? n * 0.62 : (r.look.dark ?? 0.3) + (r.look.windows ? n * 0.25 : 0.12);
    dark = Math.max(0, Math.min(0.82, dark));
    const lights: { x: number; y: number; r: number; c: string; f: number }[] = [];
    for (const item of r.items) {
      const cat = CATALOG[item.k];
      if (!cat || !cat[4]) continue;
      const f = ART.frames[item.k];
      if (item.k === 'string_lights') {
        for (let x = 1; x < r.w; x += 2) lights.push({ x: x * T, y: WALL * 0.6, r: cat[4] * T * 0.7, c: cat[5], f: 0.04 });
        continue;
      }
      const lx = (item.x + cat[0] / 2) * T;
      const ly = cat[3] ? WALL * (f?.[6] ?? 0.3) + T * 0.6 : WALL + (item.y + cat[1]) * T - (f ? f[3] / A : T) * 0.7;
      lights.push({ x: lx, y: ly, r: cat[4] * T, c: cat[5], f: item.k === 'fireplace' ? 0.12 : item.k.match(/cabinet|pinball|neon/) ? 0.05 : 0.02 });
    }
    if (r.look.windows && n < 0.8)
      for (const item of r.items) if (item.k === 'window') lights.push({ x: (item.x + 1) * T, y: WALL + T, r: 5 * T, c: '#cfe8ff', f: 0 });
    if (dark < 0.02) return;
    const L = this.light;
    const w = this.canvas.width;
    const h = this.canvas.height;
    if (L.width !== w || L.height !== h) {
      L.width = w;
      L.height = h;
    }
    const lg = L.getContext('2d')!;
    lg.setTransform(1, 0, 0, 1, 0, 0);
    lg.globalCompositeOperation = 'source-over';
    lg.clearRect(0, 0, w, h);
    lg.fillStyle = r.look.outdoor ? `rgba(8,12,34,${dark})` : `rgba(10,8,24,${dark})`;
    lg.fillRect(0, 0, w, h);
    lg.setTransform(this.scale, 0, 0, this.scale, this.ox, this.oy);
    lg.globalCompositeOperation = 'destination-out';
    const t = now / 1000;
    for (const l of lights) {
      const flick = 1 + Math.sin(t * 13 + l.x) * l.f + Math.sin(t * 7.3 + l.y) * l.f;
      const gr = lg.createRadialGradient(l.x, l.y, 0, l.x, l.y, l.r * flick);
      gr.addColorStop(0, 'rgba(0,0,0,0.95)');
      gr.addColorStop(0.5, 'rgba(0,0,0,0.5)');
      gr.addColorStop(1, 'rgba(0,0,0,0)');
      lg.fillStyle = gr;
      lg.beginPath();
      lg.arc(l.x, l.y, l.r * flick, 0, Math.PI * 2);
      lg.fill();
    }
    g.save();
    g.setTransform(1, 0, 0, 1, 0, 0);
    g.drawImage(L, 0, 0);
    g.restore();
    g.save();
    g.globalCompositeOperation = 'lighter';
    for (const l of lights) {
      const gr = g.createRadialGradient(l.x, l.y, 0, l.x, l.y, l.r * 0.8);
      gr.addColorStop(0, hexA(l.c, 0.16 * dark + 0.04));
      gr.addColorStop(1, hexA(l.c, 0));
      g.fillStyle = gr;
      g.beginPath();
      g.arc(l.x, l.y, l.r * 0.8, 0, Math.PI * 2);
      g.fill();
    }
    g.restore();
  }
}

// ── little drawing helpers ──────────────────────────────────

function roundRect(g: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, r: number) {
  r = Math.min(r, w / 2, h / 2);
  g.beginPath();
  g.moveTo(x + r, y);
  g.arcTo(x + w, y, x + w, y + h, r);
  g.arcTo(x + w, y + h, x, y + h, r);
  g.arcTo(x, y + h, x, y, r);
  g.arcTo(x, y, x + w, y, r);
  g.closePath();
}

function pill(g: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, fill: string) {
  roundRect(g, x, y, w, h, h / 2);
  g.fillStyle = fill;
  g.fill();
}

function wrap(g: CanvasRenderingContext2D, text: string, max: number): string[] {
  const out: string[] = [];
  let line = '';
  for (const word of text.split(' ')) {
    const next = line ? line + ' ' + word : word;
    if (g.measureText(next).width > max && line) {
      out.push(line);
      line = word;
    } else line = next;
    while (g.measureText(line).width > max && line.length > 8) {
      let cut = line.length - 1;
      while (cut > 1 && g.measureText(line.slice(0, cut)).width > max) cut--;
      out.push(line.slice(0, cut));
      line = line.slice(cut);
    }
  }
  if (line) out.push(line);
  return out.slice(0, 6);
}

function easeOutBack(k: number) {
  const c = 1.7;
  return 1 + (c + 1) * Math.pow(k - 1, 3) + c * Math.pow(k - 1, 2);
}

function rgb(hex: string): [number, number, number] {
  const n = parseInt(hex.replace('#', '').padEnd(6, '0').slice(0, 6), 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

function shade(hex: string, f: number): string {
  const [r, g, b] = rgb(hex);
  const t = f < 0 ? 0 : 255;
  const p = Math.abs(f);
  return `rgb(${Math.round((t - r) * p + r)},${Math.round((t - g) * p + g)},${Math.round((t - b) * p + b)})`;
}

function mix(a: string, b: string, k: number): string {
  const [r1, g1, b1] = rgb(a);
  const [r2, g2, b2] = rgb(b);
  k = Math.max(0, Math.min(1, k));
  return `rgb(${Math.round(r1 + (r2 - r1) * k)},${Math.round(g1 + (g2 - g1) * k)},${Math.round(b1 + (b2 - b1) * k)})`;
}

function hexA(hex: string, a: number) {
  const [r, g, b] = rgb(hex);
  return `rgba(${r},${g},${b},${a})`;
}
