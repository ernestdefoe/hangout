import app from 'flarum/forum/app';

/** A person as the server snapshots them (World::snapshot). */
export interface Person {
  id: number;
  n: string;
  u: string;
  s: string;
  av?: string;
  rk: string;
  col: string;
  ti?: string;
  pf?: string;
  sf?: string;
  fe?: string;
  x?: number;
  y?: number;
  r?: number;
  afk?: string;
  act?: number;
  con?: number;
  inv?: boolean;
  room?: string;
}

/** One event from the log (Events::present). */
export interface HEvent {
  i: number;
  k: string;
  a?: number;
  r?: number;
  h?: number;
  t?: string;
  d?: any;
  at: number;
}

export interface Exit {
  id: number;
  to: number;
  name: string;
  room: string;
  side: 'n' | 'e' | 'w' | 's';
  pos: number;
}

export interface Item {
  id: number;
  k: string;
  x: number;
  y: number;
  r: number;
}

export interface RoomState {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  area: string;
  w: number;
  h: number;
  look: { wall?: string; floor?: string; dark?: number; windows?: boolean; outdoor?: boolean };
  locked: boolean;
  exits: Exit[];
  items: Item[];
  people: Person[];
  log: HEvent[];
}

export interface Rank {
  name: string;
  color: string;
  level: number;
}

export interface State {
  me: Person | null;
  room: RoomState;
  rooms: { id: number; name: string; n: number }[];
  ranks: Record<string, Rank>;
  feelings: string[];
  head: number;
  can: { talk: boolean; shout: boolean; staff: boolean; invisible: boolean; channels: boolean; moderate: boolean; report: boolean };
  rank: string;
  idle: number;
  tz: number;
  push: boolean;
  now: number;
  channels: { id: number; name: string; description: string | null; n: number; in: boolean }[];
  friends: number[];
  enemies: number[];
  mail: number;
  chanlog?: HEvent[];
}

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans('ernestdefoe-hangout.forum.' + key, params);
export const tt = (key: string, params: Record<string, any> = {}): string => {
  const out = t(key, params);
  return Array.isArray(out) ? out.join('') : String(out);
};

export function call<T = any>(method: 'GET' | 'POST', path: string, body?: Record<string, any>): Promise<T> {
  let url = app.forum.attribute<string>('apiUrl') + '/hangout' + path;
  if (method === 'GET' && body) url += '?' + new URLSearchParams(body as Record<string, string>);
  return app.request<T>({ method, url, body: method === 'POST' ? body : undefined, background: true, errorHandler: () => {} } as any);
}

/** The sentence for a refusal from the server. */
export function errorText(e: any): string {
  const err = e?.response?.errors?.[0];
  const code = String(err?.code || '').replace(/^hangout_/, '');
  if (code && app.translator.translations['ernestdefoe-hangout.forum.errors.' + code]) return tt('errors.' + code, err.meta || {});
  if (e?.status === 403 || code === 'permission_denied') return tt('errors.permission_denied');
  return tt('errors.generic');
}

/** "4m", "2h", "now": TCZ-style idle times. */
export function idle(seconds: number): string {
  if (seconds < 60) return tt('idle.now');
  if (seconds < 3600) return tt('idle.m', { n: Math.floor(seconds / 60) });
  if (seconds < 86400) return tt('idle.h', { n: Math.floor(seconds / 3600) });
  return tt('idle.d', { n: Math.floor(seconds / 86400) });
}

export function duration(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  return h ? tt('duration.hm', { h, m }) : tt('duration.m', { m });
}

/** Prefix, name and suffix, as on TCZ's who list. */
export function fullName(p: { n: string; pf?: string; sf?: string }): string {
  return [p.pf, p.n, p.sf].filter(Boolean).join(' ');
}
