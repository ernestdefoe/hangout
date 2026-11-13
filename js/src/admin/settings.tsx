import app from 'flarum/admin/app';
import type Mithril from 'mithril';

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-hangout.admin.${key}`, params);
const ts = (key: string): string => String(app.translator.trans(`ernestdefoe-hangout.admin.${key}`));
const key = (name: string) => `ernestdefoe-hangout.${name}`;

/** TCZ's ranks, highest first; defaults mirror Ranks::CATALOG on the server. */
const RANKS: [string, string, string][] = [
  ['deity', 'Deity', '#d946ef'],
  ['elder_wizard', 'Elder Wizard', '#4ade80'],
  ['elder_druid', 'Elder Druid', '#16a34a'],
  ['wizard', 'Wizard', '#22d3ee'],
  ['druid', 'Druid', '#0e9fb8'],
  ['apprentice_wizard', 'Apprentice Wizard', '#facc15'],
  ['apprentice_druid', 'Apprentice Druid', '#ca8a04'],
  ['retired', 'Retired', '#f87171'],
  ['experienced', 'Experienced Builder', '#fb7185'],
  ['assistant', 'Assistant', '#dc2626'],
  ['builder', 'Builder', '#e2e8f0'],
  ['mortal', 'Mortal', '#cbd5e1'],
  ['moron', 'Moron', '#60a5fa'],
];
/** Where pages and tells go for someone who isn't here (Outside::TARGETS). */
const OUTSIDE = ['parley,chat,messages', 'chat,parley,messages', 'messages,parley,chat', 'parley', 'chat', 'messages', 'off'];
const DEFAULT_GROUPS: Record<string, string> = { deity: '1', druid: '4' };

export function settingsPage(): void {
  app.registry
    .for('ernestdefoe-hangout')
    .registerSetting({ setting: key('nav'), type: 'boolean', label: t('settings.nav') })
    .registerSetting(function (this: any) {
      return ranks(this.setting(key('ranks')));
    })
    .registerSetting({
      setting: key('say_per_minute'),
      type: 'number',
      min: 1,
      max: 600,
      label: t('settings.say_per_minute'),
      help: t('settings.say_per_minute_help'),
    })
    .registerSetting({
      setting: key('shout_minutes'),
      type: 'number',
      min: 0,
      max: 1440,
      label: t('settings.shout_minutes'),
      help: t('settings.shout_minutes_help'),
    })
    .registerSetting({
      setting: key('idle_minutes'),
      type: 'number',
      min: 1,
      max: 240,
      label: t('settings.idle_minutes'),
      help: t('settings.idle_minutes_help'),
    })
    .registerSetting({
      setting: key('keep_days'),
      type: 'number',
      min: 1,
      max: 365,
      label: t('settings.keep_days'),
      help: t('settings.keep_days_help'),
    })
    .registerSetting({
      setting: key('timezone'),
      type: 'text',
      label: t('settings.timezone'),
      help: t('settings.timezone_help'),
      placeholder: 'America/Chicago',
    })
    .registerSetting({
      setting: key('outside'),
      type: 'select',
      label: t('settings.outside'),
      help: t('settings.outside_help'),
      // String keys: an integer-like key would be sorted first.
      options: Object.fromEntries(OUTSIDE.map((o) => [o, ts(`settings.outside_options.${o.replace(/,/g, '_')}`)])),
      default: OUTSIDE[0],
    })
    .registerPermission({ permission: 'ernestdefoe-hangout.view', icon: 'fas fa-couch', label: t('permissions.view'), allowGuest: true }, 'view', 60)
    .registerPermission({ permission: 'ernestdefoe-hangout.talk', icon: 'fas fa-comment-dots', label: t('permissions.talk') }, 'reply', 60)
    .registerPermission({ permission: 'ernestdefoe-hangout.shout', icon: 'fas fa-bullhorn', label: t('permissions.shout') }, 'reply', 59)
    .registerPermission(
      { permission: 'ernestdefoe-hangout.createChannels', icon: 'fas fa-hashtag', label: t('permissions.create_channels') },
      'start',
      58
    );
}

/** One row per rank: the name and colour members see, and the Flarum group that holds it. */
function ranks(stream: any): Mithril.Children {
  let saved: Record<string, { name?: string; color?: string; group?: string | null }> | null = null;
  try {
    saved = JSON.parse(stream() || 'null');
  } catch {
    saved = null;
  }
  const value = (rank: string) => ({ ...(saved?.[rank] || {}), group: saved ? (saved[rank]?.group ?? null) : (DEFAULT_GROUPS[rank] ?? null) });
  const set = (rank: string, field: 'name' | 'color' | 'group', v: string) => {
    const next: Record<string, any> = {};
    for (const [k] of RANKS) next[k] = value(k);
    next[rank] = { ...next[rank], [field]: v === '' ? null : v };
    stream(JSON.stringify(next));
  };
  const groups = app.store.all<any>('groups').filter((g) => !['2', '3'].includes(g.id()));

  return (
    <div className="Form-group HangoutAdmin-ranks">
      <label>{t('settings.ranks')}</label>
      <div className="helpText">{t('settings.ranks_help')}</div>
      <table className="HangoutAdmin-table">
        <thead>
          <tr>
            <th>{t('settings.rank')}</th>
            <th>{t('settings.rank_name')}</th>
            <th>{t('settings.rank_color')}</th>
            <th>{t('settings.rank_group')}</th>
          </tr>
        </thead>
        <tbody>
          {RANKS.map(([rank, name, color]) => {
            const v = value(rank);
            return (
              <tr>
                <td>{t('ranks.' + rank)}</td>
                <td>
                  <input
                    className="FormControl"
                    aria-label={ts('settings.rank_name')}
                    placeholder={name}
                    value={v.name || ''}
                    maxlength={40}
                    oninput={(e: InputEvent) => set(rank, 'name', (e.target as HTMLInputElement).value)}
                  />
                </td>
                <td>
                  <input
                    className="FormControl HangoutAdmin-color"
                    type="color"
                    aria-label={ts('settings.rank_color')}
                    value={v.color || color}
                    oninput={(e: InputEvent) => set(rank, 'color', (e.target as HTMLInputElement).value)}
                  />
                </td>
                <td>
                  {rank === 'mortal' ? (
                    <span className="helpText">{t('settings.mortal_group')}</span>
                  ) : (
                    <select
                      className="FormControl"
                      aria-label={ts('settings.rank_group')}
                      value={v.group ? 'g' + v.group : ''}
                      onchange={(e: Event) => set(rank, 'group', (e.target as HTMLSelectElement).value.replace(/^g/, ''))}
                    >
                      <option value="">{t('settings.no_group')}</option>
                      {groups.map((g: any) => (
                        <option value={'g' + g.id()}>{g.namePlural()}</option>
                      ))}
                    </select>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
