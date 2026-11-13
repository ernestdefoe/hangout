import type Mithril from 'mithril';
import { fullName, idle, t, tt, type HEvent, type Person } from '../api';
import type Live from '../Live';
import type { Line } from '../Live';

/** Text with its links made clickable; never HTML. */
export function linkify(text: string): Mithril.Children {
  const parts = String(text || '').split(/(https?:\/\/[^\s<>"]+[^\s<>".,;:!?)\]])/g);
  return parts.map((part, i) =>
    i % 2 ? (
      <a href={part} target="_blank" rel="nofollow ugc noopener noreferrer">
        {part}
      </a>
    ) : (
      part
    )
  );
}

/** A clickable name with its rank's colour as a dot (the name itself stays readable on every theme). */
export function Name(live: Live, id: number | undefined, name: string, rank?: string): Mithril.Children {
  const color = rank && live.state?.ranks[rank]?.color;
  return (
    <button type="button" className="HangoutName" onclick={() => id && live.onUi({ card: id })}>
      {color && rank !== 'mortal' ? <span className="HangoutName-dot" style={{ background: color }} /> : null}
      {name}
    </button>
  );
}

const COMMANDS = [
  'say',
  'emote',
  'think',
  'feelings',
  'page',
  'tell',
  'remote',
  'recall',
  'reply',
  'who',
  'look',
  'go',
  'home',
  'afk',
  'title',
  'prefix',
  'describe',
  'feeling',
  'cmessage',
  'colour',
  'time',
  'shout',
  'yell',
  'channel',
  'friend',
  'mail',
  'bbs',
  'alias',
  'partner',
  'report',
  'quit',
];

/** Only Druids and up see these in help. */
const STAFF_COMMANDS = ['mute', 'remove'];

/** Kinds of line that carry someone's words: they get a ⋯ menu (report; remove and mute for Druids). */
const SPOKEN = ['say', 'emote', 'think', 'feel', 'shout', 'yell', 'chan', 'page', 'tell', 'remote'];

/** One line of a log: an event, or the answer to one of our commands. */
export function renderLine(live: Live, line: Line): Mithril.Children {
  if (line.out) return renderOut(live, line.out);
  const body = renderEvent(live, line.e as HEvent);
  const menu = lineMenu(live, line.e as HEvent);
  return menu ? (
    <div className="HangoutMsg">
      {body}
      {menu}
    </div>
  ) : (
    body
  );
}

/** The ⋯ beside someone else's message: Report for everyone, Remove and Mute for Druids and up. */
function lineMenu(live: Live, e: HEvent): Mithril.Children {
  const can = live.state?.can;
  if (!can || !e.a || e.a === live.me?.id || !SPOKEN.includes(e.k) || !(can.report || can.moderate)) return null;
  const open = live.menu === e.i;
  const name = e.d?.n || '';
  const run = (line: string) => {
    live.menu = null;
    live.act(line);
  };
  const user = live.people.get(e.a)?.u || live.who.find((p) => p.id === e.a)?.u || `#${e.a}`;
  const isPrivate = ['page', 'tell', 'remote'].includes(e.k);
  return [
    <button
      type="button"
      className="HangoutMsg-more"
      aria-label={tt('menu.open')}
      aria-expanded={open ? 'true' : 'false'}
      title={tt('menu.open')}
      onclick={() => (live.menu = open ? null : e.i)}
    >
      ⋯
    </button>,
    open ? (
      <div className="HangoutMsg-menu" role="group" aria-label={name} oncreate={(v: Mithril.VnodeDOM) => v.dom.scrollIntoView({ block: 'nearest' })}>
        {can.report ? (
          <button type="button" className="HangoutWord" onclick={() => run(`/report ${e.i}`)}>
            {t('menu.report')}
          </button>
        ) : null}
        {can.moderate && !isPrivate ? (
          <button type="button" className="HangoutWord" onclick={() => run(`/remove ${e.i}`)}>
            {t('menu.remove')}
          </button>
        ) : null}
        {can.moderate
          ? [
              <button type="button" className="HangoutWord" onclick={() => run(`/mute ${user} 10`)}>
                {t('menu.mute')}
              </button>,
              <button type="button" className="HangoutWord" onclick={() => run(`/mute ${user} 60`)}>
                {t('menu.mute_hour')}
              </button>,
            ]
          : null}
      </div>
    ) : null,
  ];
}

function renderEvent(live: Live, e: HEvent): Mithril.Children {
  const d = e.d || {};
  const me = live.me?.id;
  const mine = !!me && e.a === me;
  const who = Name(live, e.a, d.n || d.p?.n || '', d.rk || d.p?.rk);
  const text = linkify(e.t || '');
  const to = (list: { id: number; n: string }[] = []) => list.map((p) => p.n).join(', ');

  switch (e.k) {
    case 'say':
      return <div className="HangoutLine">{t(mine ? `line.you_${d.v || 'says'}` : `line.${d.v || 'says'}`, { name: who, text })}</div>;
    case 'emote':
      return (
        <div className="HangoutLine HangoutLine--emote">
          {who}
          {/^['’]s\b|^[,.]/.test(e.t || '') ? '' : ' '}
          {text}
        </div>
      );
    case 'think':
      return <div className="HangoutLine HangoutLine--think">{t('line.think', { name: who, text })}</div>;
    case 'feel':
      return <div className="HangoutLine HangoutLine--feel">{mine ? d.u : e.r !== live.room?.id ? t('line.afar', { text: e.t }) : e.t}</div>;
    case 'enter':
      return <div className="HangoutLine HangoutLine--sys">{t(d.from ? 'line.enter_from' : 'line.enter', { name: who, room: d.from })}</div>;
    case 'leave':
      return <div className="HangoutLine HangoutLine--sys">{t('line.leave', { name: who, room: d.to })}</div>;
    case 'connect':
      return (
        <div className="HangoutLine HangoutLine--sys">
          {t('line.connect', { name: who, room: d.room })}
          {e.t ? <span className="HangoutLine-quote"> “{linkify(e.t)}”</span> : null}
        </div>
      );
    case 'disconnect':
      return (
        <div className="HangoutLine HangoutLine--sys">
          {t(d.lost ? 'line.lost' : 'line.disconnect', { name: who })}
          {e.t ? <span className="HangoutLine-quote"> “{linkify(e.t)}”</span> : null}
        </div>
      );
    case 'afk':
      return <div className="HangoutLine HangoutLine--sys">{t(e.t ? 'line.afk_reason' : 'line.afk', { name: who, reason: e.t })}</div>;
    case 'back':
      return <div className="HangoutLine HangoutLine--sys">{t('line.back', { name: who })}</div>;
    case 'page':
    case 'tell':
    case 'remote': {
      const group = (d.to || []).length > 1;
      // An answer to a whisper that went out, brought back from Chat or Messages.
      const key = mine ? `line.${e.k}_sent` : d.via ? 'line.tell_via' : `line.${e.k}${group ? '_group' : ''}`;
      return (
        <div className={`HangoutLine HangoutLine--private${mine ? ' is-mine' : ''}`}>
          <span className="HangoutLine-time">{new Date(e.at * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}</span>
          {t(key, {
            name: who,
            to: to(d.to),
            loc: d.loc,
            text,
            others: to((d.to || []).filter((p: any) => p.id !== me)),
            via: d.via ? tt(`outside.via.${d.via}`) : '',
          })}
        </div>
      );
    }
    case 'shout':
      return <div className="HangoutLine HangoutLine--shout">{t(mine ? 'line.you_shout' : 'line.shout', { name: who, text })}</div>;
    case 'chan':
      return (
        <div className="HangoutLine">
          <span className="HangoutLine-chan">[{d.ch}]</span> {d.em ? [who, ' ', text] : t('line.chan', { name: who, text })}
        </div>
      );
    case 'partner':
      return <div className="HangoutLine HangoutLine--shout">{t('line.partner', { name: who, with: d.with })}</div>;
    case 'propose':
      return <div className="HangoutLine HangoutLine--private">{t(mine ? 'line.propose_sent' : 'line.propose', { name: who, to: to(d.to) })}</div>;
    case 'yell':
      return <div className="HangoutLine HangoutLine--shout">{t('line.yell', { name: who, text, area: d.area })}</div>;
    case 'mute':
    case 'unmute':
      return (
        <div className="HangoutLine HangoutLine--error">{t(mine ? `line.${e.k}d_sent` : `line.${e.k}d`, { name: who, to: to(d.to), m: d.m })}</div>
      );
    default:
      return e.t ? <div className="HangoutLine">{text}</div> : null;
  }
}

function renderOut(live: Live, out: Record<string, any>): Mithril.Children {
  const k = out.k;
  const sys = (children: Mithril.Children, mod = 'info') => <div className={`HangoutLine HangoutLine--${mod}`}>{children}</div>;
  switch (k) {
    case 'error':
      return sys(out.text, 'error');
    case 'unknown':
      return sys(t('out.unknown', { command: out.command }), 'error');
    case 'who':
      return WhoTable({ live, people: out.people });
    case 'look_room':
      return sys(
        <div>
          <strong>{out.name}</strong>
          {out.description ? <p>{out.description}</p> : null}
          <p>{t('out.exits', { exits: out.exits.join(', ') || tt('out.none') })}</p>
          <p>{out.people.length ? t('out.here', { people: out.people.join(', ') }) : t('out.alone')}</p>
        </div>
      );
    case 'look_person':
      return sys(
        <div>
          <strong>{out.name}</strong>
          {out.feeling ? ' ' + tt('out.is_feeling', { feeling: out.feeling.toLowerCase() }) : null}
          <p>{out.description ? linkify(out.description) : t('out.no_description')}</p>
        </div>
      );
    case 'recall':
      return sys(
        out.items.length ? (
          <div>
            <strong>{t('out.recall_title')}</strong>
            <ol className="HangoutRecall">
              {out.items.map((e: HEvent) => (
                <li>{renderLine(live, { e, id: e.i })}</li>
              ))}
            </ol>
            <p className="HangoutLine-hint">{t('out.recall_hint')}</p>
          </div>
        ) : (
          t('out.recall_none')
        )
      );
    case 'help':
      return sys(
        <div>
          <strong>{t('help.title')}</strong>
          <dl className="HangoutHelp">
            {[...COMMANDS, ...(live.state?.can.moderate ? STAFF_COMMANDS : [])]
              .filter((c) => out.topic === 'index' || c.startsWith(out.topic))
              .map((c) => [<dt>{tt(`help.usage.${c}`)}</dt>, <dd>{t(`help.what.${c}`)}</dd>])}
          </dl>
          <p className="HangoutLine-hint">{t('help.tokens')}</p>
        </div>
      );
    case 'feelings':
      return sys(
        <div>
          <strong>{t('out.feelings_title')}</strong>
          <p className="HangoutWords">
            {out.names.map((n: string) => (
              <button type="button" className="HangoutWord" onclick={() => live.act(n)}>
                {n}
              </button>
            ))}
          </p>
          <p className="HangoutLine-hint">{t('out.feelings_hint')}</p>
        </div>
      );
    case 'moods':
      return sys(
        <div>
          <strong>{t('out.moods_title')}</strong>
          <p className="HangoutWords">{out.names.join(', ')}</p>
        </div>
      );
    case 'usage':
      return sys(t('out.usage', { usage: out.usage }), 'error');
    case 'channels':
      return sys(
        <div>
          <strong>{t('out.channels_title')}</strong>
          <ul className="HangoutList">
            {out.channels.map((c: any) => (
              <li>
                <strong>#{c.name}</strong> · {tt('out.channel_count', { count: c.n })}{' '}
                {c.description ? <span className="HangoutLine-hint">{c.description}</span> : null}{' '}
                <button type="button" className="HangoutWord" onclick={() => live.act(`channel ${c.in ? 'leave' : 'join'} ${c.name}`)}>
                  {t(c.in ? 'out.channel_leave_button' : 'out.channel_join_button')}
                </button>
              </li>
            ))}
          </ul>
          <p className="HangoutLine-hint">{t('out.channels_hint')}</p>
        </div>
      );
    case 'channel_who':
      return sys(t('out.channel_who', { name: out.name, people: out.people.join(', ') || tt('out.none') }));
    case 'friends':
    case 'enemies':
      return sys(
        <div>
          <strong>{t('out.' + k + '_title')}</strong>
          {out.people.length ? (
            <ul className="HangoutList">
              {out.people.map((p: any) => (
                <li>
                  {Name(live, p.id, p.n)}{' '}
                  {p.room ? (
                    <span className="HangoutLine-hint">{t('out.friend_in', { room: p.room })}</span>
                  ) : k === 'friends' ? (
                    <span className="HangoutLine-hint">{t('out.friend_off')}</span>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : (
            <p>{t('out.' + k + '_none')}</p>
          )}
        </div>
      );
    case 'aliases':
      return sys(
        <div>
          <strong>{t('out.aliases_title')}</strong>
          {Object.keys(out.aliases).length ? (
            <dl className="HangoutHelp">{Object.entries(out.aliases).map(([name, cmd]) => [<dt>{name}</dt>, <dd>{String(cmd)}</dd>])}</dl>
          ) : (
            <p>{t('out.aliases_none')}</p>
          )}
        </div>
      );
    case 'outside_sent':
    case 'outside_refused':
      return sys(
        t(`out.${k}`, { names: out.names.join(', '), count: out.names.length, via: tt(`outside.via.${out.via}`) }),
        k === 'outside_refused' ? 'error' : 'info'
      );
    case 'partner_status':
      return sys(t(out.name ? `out.partner_${out.state}` : 'out.partner_none', { name: out.name }));
    default:
      return sys(t('out.' + k, out));
  }
}

/** TCZ's who list: prefix, name, suffix and title, then where and how long idle. */
export function WhoTable({ live, people }: { live: Live; people: Person[] }): Mithril.Children {
  const now = Date.now() / 1000;
  return (
    <table className="HangoutWho">
      <tbody>
        {people.map((p) => (
          <tr key={p.id}>
            <td className="HangoutWho-name">
              {live.friends.has(p.id) ? (
                <span className="HangoutWho-friend" title={tt('who.friend')}>
                  ★{' '}
                </span>
              ) : null}
              {Name(live, p.id, fullName(p), p.rk)} {p.ti ? <span className="HangoutWho-title">{p.ti}</span> : null}
            </td>
            <td className="HangoutWho-room">{p.room || live.state?.rooms.find((r) => r.id === p.r)?.name || ''}</td>
            <td className={`HangoutWho-idle${p.afk !== undefined && p.afk !== null ? ' is-afk' : ''}`}>
              {p.afk !== undefined && p.afk !== null ? tt('idle.afk') : idle(now - (p.act || now))}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
