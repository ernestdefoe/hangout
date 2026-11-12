import app from 'flarum/forum/app';
import { call, errorText, tt, type HEvent, type Person, type RoomState, type State } from './api';
import type Stage from './Stage';

/**
 * Keeps an open Hangout true to the server.
 *
 * Opening it connects (GET /api/hangout). After that only events travel:
 * pushed on our own realtime channel the moment they happen when
 * flarum/realtime is installed, and asked for every 1.8 s otherwise ("what
 * happened after event N?", which an up-to-date browser gets from the cache).
 * With realtime the poll slows to a 20 s heartbeat and safety net. The cursor
 * moves only with what a poll returns; pushed events are shown at once and
 * de-duplicated by id.
 */

const POLL = 1800;
const SAFETY = 20000;
const HIDDEN = 20000;
const KEEP = 250;

/** room, tells, mail, or chan:<id> for a channel. */
export type Tab = string;

export interface Line {
  /** An event from the log… */
  e?: HEvent;
  /** …or a private answer to a command ({k: locale key, …params}). */
  out?: Record<string, any>;
  id: number;
}

export interface Channel {
  id: number;
  name: string;
  description: string | null;
  n: number;
  in: boolean;
}

export interface Mail {
  id: number;
  from: string;
  fromU: string;
  subject: string;
  body: string;
  read: boolean;
  at: number;
}

let lineId = 0;

export default class Live {
  state: State | null = null;
  room: RoomState | null = null;
  people = new Map<number, Person>();
  who: Person[] = [];
  logs: Record<Tab, Line[]> = { room: [], tells: [] };
  unread: Record<Tab, number> = { room: 0, tells: 0, mail: 0 };
  channels: Channel[] = [];
  friends = new Set<number>();
  enemies = new Set<number>();
  mail: Mail[] | null = null;
  tab: Tab = 'room';
  error: string | null = null;
  loading = true;
  stage: Stage | null = null;
  onUi: (ui: Record<string, any>) => void = () => {};
  private upto = 0;
  private seen = new Set<number>();
  private timer: number | undefined;
  private whoTimer: number | undefined;
  private polling = false;
  private stopped = false;
  private realtime = false;

  constructor() {
    document.addEventListener('visibilitychange', this.visible);
    window.addEventListener('pagehide', this.bye);
  }

  get me(): Person | null {
    return this.state?.me || null;
  }

  async start(): Promise<void> {
    this.loading = true;
    try {
      const params = new URLSearchParams(window.location.search);
      const state = await call<State>('GET', '', params.get('room') ? { room: params.get('room')! } : undefined);
      if (this.stopped) return;
      this.state = state;
      this.upto = state.head;
      this.error = null;
      this.setChannels(state.channels || []);
      for (const e of state.chanlog || []) {
        if (e.h && this.logs['chan:' + e.h] && !this.seen.has(e.i)) {
          this.seen.add(e.i);
          this.logs['chan:' + e.h].push({ e, id: ++lineId });
        }
      }
      this.friends = new Set(state.friends || []);
      this.enemies = new Set(state.enemies || []);
      this.unread.mail = state.mail || 0;
      this.enterRoom(state.room, false);
      this.bindRealtime();
      this.loadWho();
      if (this.me) this.loadTells();
    } catch (e: any) {
      this.error = errorText(e);
    }
    this.loading = false;
    m.redraw();
    this.schedule();
  }

  stop() {
    this.stopped = true;
    clearTimeout(this.timer);
    clearTimeout(this.whoTimer);
    document.removeEventListener('visibilitychange', this.visible);
    window.removeEventListener('pagehide', this.bye);
    this.bye();
  }

  /** Leaving the page: say goodbye at once instead of waiting to be swept out. */
  private said = false;
  private bye = () => {
    if (!this.me || this.said) return;
    this.said = true;
    const body = new FormData();
    body.append('csrfToken', app.session.csrfToken || '');
    navigator.sendBeacon?.(app.forum.attribute<string>('apiUrl') + '/hangout/leave', body);
  };

  // ── rooms ─────────────────────────────────────────────────

  enterRoom(room: RoomState, moved = true) {
    this.room = room;
    this.people = new Map(room.people.map((p) => [p.id, p]));
    if (this.me && this.people.has(this.me.id)) this.state!.me = { ...this.me, ...this.people.get(this.me.id)! };
    this.stage?.setRoom(room);
    if (moved) this.logs.room.push({ out: { k: 'arrived', room: room.name }, id: ++lineId });
    for (const e of room.log) {
      if (this.seen.has(e.i)) continue;
      this.seen.add(e.i);
      if (!moved) this.logs.room.push({ e, id: ++lineId });
    }
    this.trim('room');
    const url = new URL(window.location.href);
    url.searchParams.delete('room');
    window.history.replaceState(window.history.state, '', url.toString());
  }

  // ── doing things ──────────────────────────────────────────

  async act(line: string): Promise<boolean> {
    line = line.trim();
    if (!line || !this.me) return false;
    try {
      const res = await call<{ e?: HEvent[]; out?: any[]; room?: RoomState; ui?: any }>('POST', '/act', { line });
      if (res.room) this.enterRoom(res.room);
      res.e?.forEach((e) => this.apply(e));
      res.out?.forEach((out) => this.print(out));
      if (res.ui) this.onUi(res.ui);
      m.redraw();
      return true;
    } catch (e: any) {
      this.print({ k: 'error', text: errorText(e) });
      m.redraw();
      return false;
    }
  }

  walk(x: number, y: number) {
    call('POST', '/act', { walk: [x, y] }).catch(() => {});
  }

  async exit(id: number) {
    try {
      const res = await call<{ room: RoomState }>('POST', '/act', { exit: id });
      this.enterRoom(res.room);
      this.loadWho();
    } catch (e: any) {
      this.print({ k: 'error', text: errorText(e) });
    }
    m.redraw();
  }

  setChannels(list: Channel[]) {
    this.channels = list;
    for (const c of list) if (c.in && !this.logs['chan:' + c.id]) this.logs['chan:' + c.id] = [];
    if (this.tab.startsWith('chan:') && !list.find((c) => c.in && 'chan:' + c.id === this.tab)) this.tab = 'room';
  }

  async loadMail() {
    try {
      this.mail = (await call<{ mail: Mail[] }>('GET', '/mail')).mail;
      this.unread.mail = this.mail.filter((x) => !x.read).length;
    } catch {
      this.mail = this.mail || [];
    }
    m.redraw();
  }

  print(out: Record<string, any>, tab: Tab = this.tab) {
    if (!this.logs[tab]) tab = 'room';
    this.logs[tab].push({ out, id: ++lineId });
    this.trim(tab);
  }

  private trim(tab: Tab) {
    if (this.logs[tab].length > KEEP) this.logs[tab].splice(0, this.logs[tab].length - KEEP);
  }

  // ── staying in step ───────────────────────────────────────

  private bindRealtime() {
    if (!this.state?.push || !this.me || !('flarum-realtime' in ((window as any).flarum?.extensions || {}))) return;
    let rt: any;
    try {
      rt = (window as any).flarum.reg.get('flarum-realtime', 'forum/RealtimeState');
    } catch {
      return;
    }
    if (!rt || typeof rt.onUserChannelReady !== 'function') return;
    // Fires again after every reconnect with a fresh channel, so bindings never sit on a dead socket.
    rt.onUserChannelReady((channel: any) => {
      this.realtime = true;
      channel.bind('hangout', (e: HEvent) => {
        if (!this.stopped && e && e.i > this.upto) {
          this.apply(e);
          m.redraw();
        }
      });
      this.schedule();
    });
  }

  private connected(): boolean {
    const socket = (app as any).websocket;
    return this.realtime && socket?.connection?.state === 'connected';
  }

  private schedule(delay?: number) {
    clearTimeout(this.timer);
    if (this.stopped || !this.state) return;
    const wait = delay ?? (document.hidden ? HIDDEN : this.connected() ? SAFETY : POLL);
    this.timer = window.setTimeout(() => this.poll(), wait);
  }

  private visible = () => {
    if (!document.hidden) this.poll();
    else this.schedule();
  };

  async poll(): Promise<void> {
    if (!this.state || this.polling || this.stopped) return;
    this.polling = true;
    let soon = false;
    try {
      const q: Record<string, string> = { since: String(this.upto) };
      if (!this.me && this.room) q.room = String(this.room.id);
      const res = await call<{ gone?: boolean; upto: number; e: HEvent[]; more?: boolean }>('GET', '/poll', q);
      if (res.gone) {
        this.polling = false;
        this.print({ k: 'reconnected' }, 'room');
        await this.start();
        return;
      }
      res.e.forEach((e) => this.apply(e));
      this.upto = Math.max(this.upto, res.upto);
      soon = !!res.more;
      if (res.e.length) m.redraw();
    } catch {
      // A blip: the next beat tries again.
    } finally {
      this.polling = false;
    }
    this.schedule(soon ? 200 : undefined);
  }

  /** One event: move people, pop bubbles, add log lines. Each id counts once. */
  apply(e: HEvent) {
    if (this.seen.has(e.i)) return;
    this.seen.add(e.i);
    if (this.seen.size > 2000) this.seen = new Set([...this.seen].slice(-1000));
    const here = this.room && e.r === this.room.id;
    const mine = !!this.me && e.a === this.me.id;
    const d = e.d || {};
    const stage = this.stage;
    let log: Tab | null = null;
    // An enemy's talk never shows (they still come and go).
    const ignored = !!e.a && !mine && this.enemies.has(e.a);

    switch (e.k) {
      case 'move':
        if (here && e.a && !mine) {
          const p = this.people.get(e.a);
          if (p) Object.assign(p, { x: d.x, y: d.y });
          stage?.walkTo(e.a, d.x, d.y);
        }
        return;
      case 'enter':
        if (here && d.p) {
          this.people.set(d.p.id, d.p);
          stage?.add(d.p);
          log = 'room';
        }
        this.whoSoon();
        break;
      case 'leave':
        if (here && e.a) {
          this.people.delete(e.a);
          stage?.remove(e.a);
          log = 'room';
        }
        this.whoSoon();
        break;
      case 'connect':
        if (here && d.p && !this.people.has(d.p.id)) {
          this.people.set(d.p.id, d.p);
          stage?.add(d.p);
        }
        // TCZ pings you when a friend connects.
        if (e.a && this.friends.has(e.a) && !mine) app.alerts.show({ type: 'success' } as any, tt('alerts.friend_on', { name: d.p?.n || '' }));
        log = 'room';
        this.whoSoon();
        break;
      case 'disconnect':
        if (e.a && this.people.has(e.a)) {
          this.people.delete(e.a);
          stage?.remove(e.a);
        }
        log = 'room';
        this.whoSoon();
        break;
      case 'upd':
        if (d.p) {
          if (this.people.has(d.p.id)) this.people.set(d.p.id, { ...this.people.get(d.p.id)!, ...d.p });
          stage?.update(d.p);
          if (mine && this.state) this.state.me = { ...this.me!, ...d.p };
        }
        return;
      case 'say':
      case 'emote':
      case 'think':
        if (!here || ignored) return;
        if (e.a) stage?.bubble(e.a, e.k === 'emote' ? `${d.n} ${e.t}` : e.t || '', e.k === 'think');
        if (e.a) this.touch(e.a);
        log = 'room';
        break;
      case 'feel':
        if (ignored) return;
        if (here && e.a) {
          stage?.bubble(e.a, mine ? d.u : e.t || '');
          stage?.animate(e.a, d.f);
          this.touch(e.a);
          log = 'room';
        } else log = 'tells';
        break;
      case 'afk':
      case 'back':
        if (here && e.a) {
          const p = this.people.get(e.a);
          if (p) p.afk = e.k === 'afk' ? e.t || '' : undefined;
          stage?.update({ ...(p as Person), afk: e.k === 'afk' ? e.t || '' : undefined });
          log = 'room';
        }
        break;
      case 'chan':
        if (ignored || !e.h || !this.logs['chan:' + e.h]) return;
        log = 'chan:' + e.h;
        break;
      case 'shout':
      case 'yell':
        if (ignored) return;
        if (here && e.a) stage?.bubble(e.a, e.t || '');
        log = 'room';
        break;
      case 'partner':
        log = 'room';
        break;
      case 'propose':
        log = 'tells';
        if (!mine) app.alerts.show({ type: 'info' } as any, tt('alerts.proposal', { name: d.n }));
        break;
      case 'page':
      case 'tell':
      case 'remote':
        log = 'tells';
        if (!mine && this.tab !== 'tells') app.alerts.show({ type: 'info' } as any, `${d.n}: ${e.t || ''}`.slice(0, 120));
        break;
      default:
        log = here || !e.r ? 'room' : null;
    }
    if (!log) return;
    this.logs[log].push({ e, id: ++lineId });
    this.trim(log);
    // Your own page or tell also shows where you typed it (TCZ's "You tell Ben…"), so it doesn't seem to vanish.
    if (mine && log === 'tells' && this.tab !== 'tells' && this.logs[this.tab]) {
      this.logs[this.tab].push({ e, id: ++lineId });
      this.trim(this.tab);
    }
    if (log !== this.tab && !mine) this.unread[log] = (this.unread[log] || 0) + 1;
  }

  /** Someone did something: they're not idle any more. */
  private touch(id: number) {
    const p = this.people.get(id);
    if (p) {
      p.act = Date.now() / 1000;
      p.afk = undefined;
      this.stage?.update(p);
    }
  }

  // ── who's on ──────────────────────────────────────────────

  private whoSoon() {
    clearTimeout(this.whoTimer);
    this.whoTimer = window.setTimeout(() => this.loadWho(), 1500);
  }

  async loadWho() {
    clearTimeout(this.whoTimer);
    try {
      const res = await call<{ people: Person[] }>('GET', '/who');
      this.who = res.people;
      if (this.state) {
        const counts = new Map<number, number>();
        for (const p of res.people) counts.set(p.r!, (counts.get(p.r!) || 0) + 1);
        this.state.rooms.forEach((r) => (r.n = counts.get(r.id) || 0));
      }
      m.redraw();
    } catch {
      // keep the old list
    }
    if (!this.stopped) this.whoTimer = window.setTimeout(() => this.loadWho(), 30000);
  }

  private async loadTells() {
    // What came in while away: the recall, quietly, into the Tells tab.
    try {
      const res = await call<{ out?: any[] }>('POST', '/act', { line: 'recall' });
      const recall = res.out?.find((o) => o.k === 'recall');
      if (recall?.items?.length) {
        recall.items
          .slice()
          .reverse()
          .forEach((e: HEvent) => {
            if (!this.seen.has(e.i)) {
              this.seen.add(e.i);
              this.logs.tells.push({ e, id: ++lineId });
            }
          });
      }
    } catch {
      // fine
    }
  }
}
