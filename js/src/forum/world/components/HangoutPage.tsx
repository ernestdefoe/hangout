import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { call, duration, errorText, fullName, idle, t, tt, type Exit, type Person } from '../api';
import Live, { type Tab } from '../Live';
import Stage from '../Stage';
import { linkify, renderLine, WhoTable } from './lines';
import { PageStructure } from '../index';

const EMOTES = ['wave', 'laugh', 'dance', 'sit', 'hug'];

interface Card extends Person {
  description?: string;
  total?: number;
  joined?: number;
  post?: { excerpt: string; discussion: string; did: number; number: number };
  partner?: string;
}

/** /hangout: the room, the command bar, who's on, and the logs. */
export default class HangoutPage extends Page {
  live!: Live;
  stage: Stage | null = null;
  input = '';
  history: string[] = [];
  historyAt = -1;
  card: Card | null = null;
  cardId: number | null = null;
  board: any[] | null = null;
  bbs: { topics: any[]; topic: number; messages: any[] } | null = null;
  boardTab: 'bbs' | 'newest' = 'bbs';
  writing = false;
  posting = false;
  note = { subject: '', body: '' };
  boardOpen = false;
  sending = false;
  walked = false;
  settled = false;
  hintGone = false;
  private tick: number | undefined;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('hangout', tt('page.title'));
    app.setTitle(tt('page.title'));
    app.setTitleCount(0);
    this.bodyClass = 'App--hangout';
    this.live = new Live();
    this.live.onUi = (ui) => this.ui(ui);
    // Idle times and the sky move by the minute; nothing else redraws on a timer.
    this.tick = window.setInterval(() => m.redraw(), 30000);
    window.setTimeout(() => ((this.hintGone = true), m.redraw()), 7000);
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    const canvas = vnode.dom.querySelector('.HangoutStage-canvas') as HTMLCanvasElement;
    const live = this.live;
    this.stage = new Stage(canvas, {
      walk: (x, y) => {
        this.walked = true;
        live.walk(x, y);
      },
      exit: (e: Exit) => live.exit(e.id),
      person: (id) => this.openCard(id),
      board: () => this.openBoard(),
      ranks: {
        color: (rk) => live.state?.ranks[rk]?.color || '#cbd5e1',
        name: (rk) => live.state?.ranks[rk]?.name || '',
      },
      get idleAfter() {
        return live.state?.idle || 300;
      },
      get tz() {
        return live.state?.tz || 0;
      },
      get meId() {
        return live.me?.id ?? null;
      },
    });
    live.stage = this.stage;
    live.start().then(() => {
      if (new URLSearchParams(window.location.search).get('tab') === 'mail' && live.me) this.switchTab('mail');
    });
    document.addEventListener('keydown', this.keys);
    window.visualViewport?.addEventListener('resize', this.fitPhone);
    window.visualViewport?.addEventListener('scroll', this.fitPhone);
    window.addEventListener('resize', this.fitPhone);
    document.addEventListener('focusin', this.fitSoon);
    document.addEventListener('focusout', this.fitSoon);
    this.fitPhone();
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.live.stop();
    this.stage?.destroy();
    clearInterval(this.tick);
    document.removeEventListener('keydown', this.keys);
    window.visualViewport?.removeEventListener('resize', this.fitPhone);
    window.visualViewport?.removeEventListener('scroll', this.fitPhone);
    window.removeEventListener('resize', this.fitPhone);
    document.removeEventListener('focusin', this.fitSoon);
    document.removeEventListener('focusout', this.fitSoon);
    const root = document.documentElement;
    ['--hangout-top', '--hangout-height'].forEach((v) => root.style.removeProperty(v));
    root.classList.remove('Hangout-phone', 'Hangout-typing');
  }

  /** On phones the Hangout fills the screen like an app; true while it does. */
  phone = false;

  /**
   * Phones: the Hangout is pinned (position: fixed) to exactly the part of the
   * screen you can see, between the forum's top bar and the keyboard (or a
   * bottom tab bar), sized from visualViewport. Opening the keyboard shrinks
   * it instead of scrolling the room away, so you see the room while you
   * type. (A page that scrolls under the keyboard leaves a gap on iOS, and
   * sticky dies under any theme's overflow.) Desktops keep the normal page.
   */
  fitPhone = () => {
    const root = document.documentElement;
    const phone = window.matchMedia('(max-width: 767.98px)').matches;
    if (phone !== this.phone) {
      this.phone = phone;
      root.classList.toggle('Hangout-phone', phone);
      if (!phone && this.live.tab === 'who') this.live.tab = 'room';
      m.redraw();
    }
    if (!phone) return;
    const vv = window.visualViewport;
    const viewH = vv ? vv.height : window.innerHeight;
    const offset = vv ? vv.offsetTop : 0;
    // The visible top bar on phones is #app-navigation; #header is the off-screen drawer.
    const nav = document.querySelector<HTMLElement>('#app-navigation, .App-navigation');
    const navRect = nav && getComputedStyle(nav).display !== 'none' ? nav.getBoundingClientRect() : null;
    const top = Math.max(0, navRect && navRect.bottom > 0 && navRect.bottom < viewH / 2 ? navRect.bottom : 0);
    const typing = document.activeElement?.classList.contains('HangoutBar-input') && viewH < window.innerHeight - 120;
    const tab = document.querySelector<HTMLElement>('.MobileTab-bar, .MobileTab');
    const tabH = !typing && tab && getComputedStyle(tab).position === 'fixed' ? tab.offsetHeight : 0;
    root.classList.toggle('Hangout-typing', !!typing);
    root.style.setProperty('--hangout-top', `${Math.round(offset + top)}px`);
    root.style.setProperty('--hangout-height', `${Math.max(240, Math.round(viewH - top - tabH))}px`);
  };

  /** Focus changes settle after the keyboard animates; measure again then. */
  fitSoon = () => {
    this.fitPhone();
    window.setTimeout(this.fitPhone, 350);
  };

  /** Arrow keys walk when you're not typing; Enter jumps to the command bar. */
  keys = (e: KeyboardEvent) => {
    const typing = /INPUT|TEXTAREA|SELECT/.test((e.target as HTMLElement)?.tagName || '') || (e.target as HTMLElement)?.isContentEditable;
    if (typing || e.metaKey || e.ctrlKey || e.altKey || !this.live.me) return;
    const dirs: Record<string, [number, number]> = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0] };
    if (dirs[e.key]) {
      e.preventDefault();
      this.stage?.step(...dirs[e.key]);
    } else if (e.key === 'Enter' || e.key === '/') {
      const input = document.querySelector<HTMLInputElement>('.HangoutBar-input');
      if (input) {
        e.preventDefault();
        input.focus();
      }
    } else if (e.key === 'Escape') {
      this.card = null;
      this.cardId = null;
      this.boardOpen = false;
      m.redraw();
    }
  };

  ui(ui: Record<string, any>) {
    if (ui.card) this.openCard(ui.card);
    if (ui.channels) this.live.setChannels(ui.channels);
    if (ui.relations) {
      this.live.friends = new Set(ui.relations.friends);
      this.live.enemies = new Set(ui.relations.enemies);
    }
    if (ui.board) this.openBoard();
    if (ui.tab) this.switchTab(ui.tab);
    if (ui.quit) m.route.set(app.route('index'));
    m.redraw();
  }

  switchTab(tab: Tab) {
    if (!this.live.logs[tab] && tab !== 'mail' && tab !== 'who') tab = 'room';
    this.live.tab = tab;
    if (tab === 'mail') this.live.loadMail();
    else this.live.unread[tab] = 0;
    requestAnimationFrame(() => this.scrollLog(true));
  }

  async openCard(id: number) {
    this.cardId = id;
    this.boardOpen = false;
    this.card = (this.live.people.get(id) as Card) || (this.live.who.find((p) => p.id === id) as Card) || null;
    m.redraw();
    try {
      const res = await call<{ card: Card }>('GET', '/people/' + id);
      if (this.cardId === id) this.card = res.card;
    } catch {
      // the quick version stays
    }
    m.redraw();
  }

  async openBoard(topic?: number) {
    this.boardOpen = true;
    this.card = null;
    m.redraw();
    try {
      const [bbs, newest] = await Promise.all([
        call<any>('GET', '/bbs', topic ? { topic: String(topic) } : undefined),
        this.board ? Promise.resolve(this.board) : app.store.find<any>('discussions', { sort: '-createdAt', page: { limit: 6 } } as any),
      ]);
      this.bbs = bbs;
      this.board = newest as any;
    } catch {
      this.board = this.board || [];
    }
    m.redraw();
  }

  async postNote(e: Event) {
    e.preventDefault();
    if (!this.bbs || this.posting) return;
    this.posting = true;
    try {
      await call('POST', '/bbs', { topic: this.bbs.topic, subject: this.note.subject, body: this.note.body });
      this.note = { subject: '', body: '' };
      this.writing = false;
      await this.openBoard(this.bbs.topic);
    } catch (err: any) {
      app.alerts.show({ type: 'error' } as any, errorText(err));
    }
    this.posting = false;
    m.redraw();
  }

  async deleteNote(id: number) {
    if (!confirm(tt('board.delete_confirm'))) return;
    await call('POST', '/bbs', { delete: id }).catch(() => {});
    this.openBoard(this.bbs?.topic);
  }

  async send(line?: string) {
    const text = (line ?? this.input).trim();
    if (!text || this.sending) return;
    if (line === undefined) {
      this.history.unshift(text);
      this.history = this.history.slice(0, 50);
      this.historyAt = -1;
      this.input = '';
    }
    this.sending = true;
    await this.live.act(text);
    this.sending = false;
    this.scrollLog(true);
    m.redraw();
  }

  scrollLog(force = false) {
    const log = document.querySelector<HTMLElement>('.HangoutLog');
    if (!log) return;
    const near = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
    if (force || near) log.scrollTop = log.scrollHeight;
  }

  /** Whether the log was at its newest line before this redraw: then it follows along however many lines arrive. */
  stick = true;

  onbeforeupdate(vnode: Mithril.Vnode<any, this>) {
    const log = document.querySelector<HTMLElement>('.HangoutLog');
    this.stick = !log || log.scrollHeight - log.scrollTop - log.clientHeight < 40;
    return super.onbeforeupdate(vnode);
  }

  onupdate(vnode: Mithril.VnodeDOM) {
    super.onupdate?.(vnode);
    // The first lines arrive at the bottom; after that, follow along only while the reader is at the bottom.
    this.scrollLog(this.stick || (!this.settled && !!this.live.logs[this.live.tab]?.length));
    if (this.live.logs[this.live.tab]?.length) this.settled = true;
  }

  view() {
    const live = this.live;
    const room = live.room;
    const here = room ? [...live.people.values()].filter((p) => !p.inv || p.id === live.me?.id).length : 0;
    return (
      <div className="HangoutPage-wrap">
        <PageStructure className="HangoutPage">
          <div className="HangoutApp">
            <div className="HangoutHead">
              <h1 className="HangoutHead-title">{room ? room.name : tt('page.title')}</h1>
              {room ? (
                <span className="HangoutHead-sub">
                  {t('head.here', { count: here })}
                  {room.exits.length ? ' · ' + tt('head.doors', { doors: room.exits.map((e) => e.room || e.name).join(', ') }) : ''}
                </span>
              ) : null}
            </div>
            <div className="HangoutMain">
              <section className="HangoutStageCol">
                <div className={classList('HangoutStage', room?.look.outdoor && 'is-outdoor')}>
                  <canvas className="HangoutStage-canvas" aria-label={tt('stage.label')} role="img" />
                  {live.loading ? <LoadingIndicator /> : null}
                  {live.error ? <div className="HangoutStage-error">{live.error}</div> : null}
                  {this.card ? this.cardView(this.card) : null}
                  {this.boardOpen ? this.boardView() : null}
                  {room && (!live.me || (!this.walked && !this.hintGone)) ? (
                    <span className="HangoutStage-hint">{t(live.me ? 'stage.hint' : 'stage.hint_guest')}</span>
                  ) : null}
                </div>
                {live.me ? this.emotes() : null}
              </section>
              <aside className="HangoutSide">
                {this.phone ? null : this.whoPanel()}
                {this.logPanel()}
              </aside>
            </div>
            {live.me ? this.bar() : live.state ? this.guestBar() : null}
          </div>
        </PageStructure>
      </div>
    );
  }

  emotes() {
    const target = this.cardId && this.cardId !== this.live.me?.id ? this.live.people.get(this.cardId) : null;
    return (
      <div className="HangoutEmotes" role="toolbar" aria-label={tt('emotes.label')}>
        {EMOTES.map((e) => (
          <button
            type="button"
            className="HangoutEmotes-button"
            onclick={() => this.send(target && e !== 'sit' && e !== 'dance' ? `${e} ${target.u}` : e)}
          >
            {t('emotes.' + e)}
          </button>
        ))}
        <button type="button" className="HangoutEmotes-button" onclick={() => this.send('feelings')}>
          {t('emotes.more')}
        </button>
      </div>
    );
  }

  bar() {
    return (
      <form
        className="HangoutBar"
        onsubmit={(e: Event) => {
          e.preventDefault();
          this.send();
        }}
      >
        <label className="HangoutBar-label" htmlFor="hangout-say">
          {t('bar.label')}
        </label>
        <input
          id="hangout-say"
          className="FormControl HangoutBar-input"
          type="text"
          autocomplete="off"
          enterkeyhint="send"
          maxlength={500}
          placeholder={tt('bar.placeholder', { room: this.live.room?.name || '' })}
          value={this.input}
          oninput={(e: InputEvent) => (this.input = (e.target as HTMLInputElement).value)}
          onkeydown={(e: KeyboardEvent) => {
            if (e.key === 'ArrowUp' && this.history.length) {
              e.preventDefault();
              this.historyAt = Math.min(this.history.length - 1, this.historyAt + 1);
              this.input = this.history[this.historyAt];
            } else if (e.key === 'ArrowDown') {
              e.preventDefault();
              this.historyAt = Math.max(-1, this.historyAt - 1);
              this.input = this.historyAt < 0 ? '' : this.history[this.historyAt];
            }
          }}
        />
        <Button type="submit" className="Button Button--primary HangoutBar-send" loading={this.sending}>
          {t('bar.say')}
        </Button>
      </form>
    );
  }

  guestBar() {
    return (
      <div className="HangoutBar HangoutBar--guest">
        <span>{t(app.session.user ? 'bar.cannot_talk' : 'bar.guest')}</span>
        {!app.session.user ? (
          <Button className="Button Button--primary" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
            {t('bar.log_in')}
          </Button>
        ) : null}
      </div>
    );
  }

  whoPanel() {
    const live = this.live;
    return (
      <div className="HangoutCard HangoutWhoPanel">
        <div className="HangoutCard-head">
          <h2 className="HangoutCard-title">{t('who.title', { count: live.who.length })}</h2>
          {live.me ? (
            <button type="button" className="HangoutLinkButton" onclick={() => this.send('who')}>
              {t('who.full')}
            </button>
          ) : null}
        </div>
        {live.who.length ? (
          WhoTable({ live, people: [...live.who].sort((a, b) => Number(live.friends.has(b.id)) - Number(live.friends.has(a.id))).slice(0, 12) })
        ) : (
          <p className="HangoutEmpty">{t('who.empty')}</p>
        )}
        {live.who.length > 12 ? <p className="HangoutEmpty">{t('who.more', { count: live.who.length - 12 })}</p> : null}
      </div>
    );
  }

  logPanel() {
    const live = this.live;
    const chans = live.channels.filter((c) => c.in);
    const tabs: Tab[] = [...(live.me ? ['room', ...chans.map((c) => 'chan:' + c.id), 'tells', 'mail'] : ['room']), ...(this.phone ? ['who'] : [])];
    const label = (tab: Tab) => (tab.startsWith('chan:') ? '#' + (chans.find((c) => 'chan:' + c.id === tab)?.name || '') : t('tabs.' + tab));
    return (
      <div className="HangoutCard HangoutLogPanel">
        <div className="HangoutTabs" role="tablist">
          {tabs.map((tab) => (
            <button
              type="button"
              role="tab"
              aria-selected={live.tab === tab}
              className={classList('HangoutTab', live.tab === tab && 'is-active')}
              onclick={() => this.switchTab(tab)}
            >
              {label(tab)}
              {live.unread[tab] ? <span className="HangoutTab-badge">{live.unread[tab]}</span> : null}
            </button>
          ))}
        </div>
        <div className="HangoutLog" role="log" aria-live="polite">
          {live.tab === 'who' ? (
            live.who.length ? (
              WhoTable({ live, people: [...live.who].sort((a, b) => Number(live.friends.has(b.id)) - Number(live.friends.has(a.id))) })
            ) : (
              <p className="HangoutEmpty">{t('who.empty')}</p>
            )
          ) : live.tab === 'mail' ? (
            this.mailView()
          ) : live.logs[live.tab]?.length ? (
            live.logs[live.tab].map((line) => <div key={line.id}>{renderLine(live, line)}</div>)
          ) : (
            <p className="HangoutEmpty">
              {t(live.tab === 'tells' ? 'tabs.tells_empty' : live.tab.startsWith('chan:') ? 'tabs.chan_empty' : 'tabs.room_empty')}
            </p>
          )}
        </div>
      </div>
    );
  }

  mailView() {
    const mail = this.live.mail;
    if (!mail) return <LoadingIndicator size="small" />;
    return (
      <div className="HangoutMail">
        <p className="HangoutLine-hint">{t('mail.hint')}</p>
        {mail.length ? (
          mail.map((x) => (
            <details
              className={classList('HangoutMail-item', !x.read && 'is-unread')}
              ontoggle={(e: Event) => {
                if ((e.target as HTMLDetailsElement).open && !x.read) {
                  x.read = true;
                  this.live.unread.mail = Math.max(0, this.live.unread.mail - 1);
                  call('POST', '/act', { line: 'mail read ' + x.id }).catch(() => {});
                }
              }}
            >
              <summary>
                <strong>{x.from}</strong> <span className="HangoutMail-subject">{x.subject}</span>
                <span className="HangoutLine-time">
                  {new Date(x.at * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
                </span>
              </summary>
              <p className="HangoutMail-body">{linkify(x.body)}</p>
              <div className="HangoutMail-actions">
                {x.fromU ? (
                  <button type="button" className="HangoutLinkButton" onclick={() => this.prefill(`mail ${x.fromU} = `)}>
                    {t('mail.reply')}
                  </button>
                ) : null}
                <button
                  type="button"
                  className="HangoutLinkButton"
                  onclick={async () => {
                    await call('POST', '/act', { line: 'mail delete ' + x.id }).catch(() => {});
                    this.live.loadMail();
                  }}
                >
                  {t('mail.delete')}
                </button>
              </div>
            </details>
          ))
        ) : (
          <p className="HangoutEmpty">{t('mail.empty')}</p>
        )}
      </div>
    );
  }

  cardView(c: Card) {
    const live = this.live;
    const rank = live.state?.ranks[c.rk];
    const self = c.id === live.me?.id;
    const now = Date.now() / 1000;
    const local = live.state ? new Date((now + live.state.tz) * 1000) : null;
    return (
      <div className="HangoutMember" role="dialog" aria-label={fullName(c)}>
        <button
          type="button"
          className="HangoutMember-close"
          aria-label={tt('card.close')}
          onclick={() => ((this.card = null), (this.cardId = null))}
        >
          ×
        </button>
        <div className="HangoutMember-top">
          <span className="HangoutMember-avatar" style={{ background: c.col }}>
            {c.av ? <img src={c.av} alt="" /> : (c.n || '?').charAt(0).toUpperCase()}
          </span>
          <div className="HangoutMember-who">
            <strong className="HangoutMember-name">{fullName(c)}</strong>
            {c.ti ? <span className="HangoutMember-title">{c.ti}</span> : null}
            {rank ? (
              <span className="HangoutMember-rank">
                <span className="HangoutName-dot" style={{ background: rank.color }} />
                {rank.name}
              </span>
            ) : null}
          </div>
        </div>
        {c.fe ? <p className="HangoutMember-line">{t('card.feeling', { name: c.n, feeling: c.fe.toLowerCase() })}</p> : null}
        {c.description ? <p className="HangoutMember-desc">{linkify(c.description)}</p> : null}
        <p className="HangoutMember-line">
          {c.con
            ? t('card.here', { time: duration(now - c.con), room: c.room || live.state?.rooms.find((r) => r.id === c.r)?.name || '' })
            : t('card.offline')}
          {c.act && c.con ? ' · ' + tt('card.idle', { idle: idle(now - c.act) }) : ''}
          {c.afk !== undefined && c.afk !== null ? ' · ' + tt(c.afk ? 'card.afk_reason' : 'card.afk', { reason: c.afk }) : ''}
        </p>
        {c.total ? <p className="HangoutMember-line">{t('card.total', { time: duration(c.total) })}</p> : null}
        {c.partner ? <p className="HangoutMember-line">{t('card.partner', { name: c.partner })}</p> : null}
        {local && c.con ? (
          <p className="HangoutMember-line">
            {t('card.clock', { time: local.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', timeZone: 'UTC' }) })}
          </p>
        ) : null}
        {live.me && !self ? (
          <div className="HangoutMember-actions">
            <button type="button" className="Button Button--primary" onclick={() => this.prefill(`tell ${c.u} `)}>
              {t('card.tell')}
            </button>
            {live.people.has(c.id) ? (
              <button
                type="button"
                className="Button"
                onclick={() => {
                  const p = live.people.get(c.id)!;
                  this.stage?.walkSelf(Math.min((p.x || 0) + 1, (live.room?.w || 20) - 1), p.y || 0);
                  this.card = null;
                }}
              >
                {t('card.walk')}
              </button>
            ) : (
              <button type="button" className="Button" onclick={() => this.prefill(`page ${c.u} `)}>
                {t('card.page')}
              </button>
            )}
            <button type="button" className="Button" onclick={() => this.send(`wave ${c.u}`)}>
              {t('card.wave')}
            </button>
            <a className="Button" href={app.route('user', { username: c.s })}>
              {t('card.profile')}
            </a>
            <button type="button" className="Button" onclick={() => this.send(`friend ${live.friends.has(c.id) ? 'remove' : 'add'} ${c.u}`)}>
              {t(live.friends.has(c.id) ? 'card.unfriend' : 'card.friend')}
            </button>
            <button type="button" className="Button" onclick={() => this.send(`enemy ${live.enemies.has(c.id) ? 'remove' : 'add'} ${c.u}`)}>
              {t(live.enemies.has(c.id) ? 'card.unignore' : 'card.ignore')}
            </button>
          </div>
        ) : (
          <div className="HangoutMember-actions">
            <a className="Button" href={app.route('user', { username: c.s })}>
              {t('card.profile')}
            </a>
          </div>
        )}
        {c.post ? (
          <p className="HangoutMember-post">
            {t('card.latest', {
              excerpt: <em>“{c.post.excerpt}”</em>,
              discussion: <a href={app.route('discussion.near', { id: c.post.did, near: c.post.number })}>{c.post.discussion}</a>,
            })}
          </p>
        ) : null}
      </div>
    );
  }

  prefill(text: string) {
    this.input = text;
    this.card = null;
    this.cardId = null;
    requestAnimationFrame(() => document.querySelector<HTMLInputElement>('.HangoutBar-input')?.focus());
  }

  boardView() {
    const bbs = this.bbs;
    return (
      <div className="HangoutMember HangoutBoard" role="dialog" aria-label={tt('board.title')}>
        <button type="button" className="HangoutMember-close" aria-label={tt('card.close')} onclick={() => (this.boardOpen = false)}>
          ×
        </button>
        <strong className="HangoutMember-name">{t('board.title')}</strong>
        <div className="HangoutTabs">
          {(['bbs', 'newest'] as const).map((k) => (
            <button type="button" className={classList('HangoutTab', this.boardTab === k && 'is-active')} onclick={() => (this.boardTab = k)}>
              {t('board.tab_' + k)}
            </button>
          ))}
        </div>
        {this.boardTab === 'newest' ? (
          this.board === null ? (
            <LoadingIndicator size="small" />
          ) : this.board.length ? (
            <ul className="HangoutBoard-list">
              {this.board.map((d: any) => (
                <li>
                  <a href={app.route.discussion(d)}>{d.title()}</a>
                  <span className="HangoutBoard-meta">{d.user()?.displayName?.() || ''}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="HangoutEmpty">{t('board.empty')}</p>
          )
        ) : !bbs ? (
          <LoadingIndicator size="small" />
        ) : (
          <div className="HangoutBbs">
            <div className="HangoutWords">
              {bbs.topics.map((topic) => (
                <button
                  type="button"
                  className={classList('HangoutWord', topic.id === bbs.topic && 'is-active')}
                  onclick={() => this.openBoard(topic.id)}
                >
                  {topic.name} · {topic.n}
                </button>
              ))}
            </div>
            {this.live.me ? (
              this.writing ? (
                <form className="HangoutBbs-form" onsubmit={(e: Event) => this.postNote(e)}>
                  <label className="HangoutBar-label" htmlFor="hangout-note-subject">
                    {t('board.subject')}
                  </label>
                  <input
                    id="hangout-note-subject"
                    className="FormControl"
                    maxlength={80}
                    placeholder={tt('board.subject')}
                    value={this.note.subject}
                    oninput={(e: InputEvent) => (this.note.subject = (e.target as HTMLInputElement).value)}
                  />
                  <label className="HangoutBar-label" htmlFor="hangout-note-body">
                    {t('board.body')}
                  </label>
                  <textarea
                    id="hangout-note-body"
                    className="FormControl"
                    rows={4}
                    maxlength={2000}
                    placeholder={tt('board.body')}
                    value={this.note.body}
                    oninput={(e: InputEvent) => (this.note.body = (e.target as HTMLTextAreaElement).value)}
                  />
                  <div className="HangoutBbs-actions">
                    <Button type="submit" className="Button Button--primary" loading={this.posting}>
                      {t('board.post')}
                    </Button>
                    <Button className="Button" onclick={() => (this.writing = false)}>
                      {t('board.cancel')}
                    </Button>
                  </div>
                </form>
              ) : (
                <Button className="Button" icon="fas fa-thumbtack" onclick={() => (this.writing = true)}>
                  {t('board.write')}
                </Button>
              )
            ) : null}
            {bbs.messages.length ? (
              <ul className="HangoutBoard-list">
                {bbs.messages.map((note: any) => (
                  <li>
                    <strong className="HangoutBbs-subject">{note.subject}</strong>
                    <span className="HangoutBbs-body">{linkify(note.body)}</span>
                    <span className="HangoutBoard-meta">
                      {note.by} · {new Date(note.at * 1000).toLocaleDateString()}
                      {note.mine ? (
                        <button type="button" className="HangoutLinkButton" onclick={() => this.deleteNote(note.id)}>
                          {t('board.delete')}
                        </button>
                      ) : null}
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="HangoutEmpty">{t('board.no_notes')}</p>
            )}
          </div>
        )}
      </div>
    );
  }
}
