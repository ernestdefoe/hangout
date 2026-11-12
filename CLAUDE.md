# Hangout: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle. After changing `art/draw.js`, run `node art/build.mjs` (writes `assets/atlas.webp` and `js/src/forum/world/atlas.json`), then build.
- Nothing is released, tagged or announced until Ernest has tested it and said so; `ship-check` runs before 1.0.
- **Licence: GPL-2.0-or-later.** It ports TCZ. Keep COPYRIGHT's TCZ/UglyMUG/TinyMUD notices in full. TinyMUD's condition 5: never use the TinyMUD authors' names or Carnegie-Mellon in announcements or promotion; credit lives in README and COPYRIGHT only.

## Design rules that exist for a reason

- **The event log's id is every reader's cursor; never a clock.** `Events::write` inserts under the `hangout_meta` row lock, so ids commit in order and a poll can't skip one. The newest id is cached (`ernestdefoe-hangout.head`): a browser that is up to date is answered without reading `hangout_events`.
- **Who sees an event** is its `audience`: `room` (+ room_id), `all`, `area` (yell), `chan` (channels), or `user` (only the ids in `hangout_inbox`, which always include the sender). `Events::after` reads through the primary key range `id > since`, so a reader that keeps up scans a handful of rows.
- **Event `data` is a snapshot** (names, ranks, positions): reading needs no joins, so the poll has no N+1 by construction. Room state and the who list cost four queries however many people (presence, users, groups, profiles); `HangoutTest::room_state_costs_the_same...` guards it.
- **Realtime:** `Job\Push` sends each event to every reader's own `private-user=<id>` channel (the one flarum/realtime authorises), queued so a dead daemon never slows a message. The browser shows pushed events at once but only moves its cursor with what a poll returns.
- **Presence rows exist only while connected.** The poll is the heartbeat (written at most every 25 s); a browser quiet for 150 s is swept out with TCZ's "lost their connection" line (by any poll, at most every 20 s, and by the `hangout:prune` schedule). Leaving the page sends a beacon to `/leave`.
- **Walking:** the browser paths round furniture on the grid and walks smoothly; the server only hears the destination (`{walk: [x, y]}`) and checks bounds and furniture (`Furniture::CATALOG`, mirrored in `Stage.ts`).
- **Feelings** are read from TCZ's own file format (`resources/*.feelings.tcz`). The public TCZ source ships its loader as empty stubs, so `Feelings::render` implements the documented format. `local.feelings.tcz` is Hangout's own and overrides generic ones.
- **Text is never HTML.** Messages are cleaned (control and bidi characters stripped, 500 chars), drawn with `fillText` on the canvas and as text nodes in the log; links are made clickable with `rel="nofollow ugc noopener"`.
- **Art** is original, drawn by code (`art/draw.js`) and packed into one WebP atlas served by `/api/hangout/art?v=<hash>` (cached a year). 🚨 No route may end in `.webp`/`.png`: nginx would serve it from disk.
- **LESS:** escape `min()`/`max()` as `~"min(...)"` (an unescaped one 500s the forum). Every colour comes from Flarum's CSS properties; link rules carry `html:not(#x-hi)` to beat themes.
- Never name an index, unique or foreign key in a migration.
- Mithril: a bare function used as a JSX tag is a closure component; call render helpers as functions (`WhoTable({...})`).

## Testing

- `tests/run.sh mysql`, `tests/run.sh pgsql` (Postgres from docker `hangout_pg` on 55435), `tests/run.sh sqlite`. 🚨 `composer test` without the env vars silently runs SQLite.
- PHPStan: `DB_DRIVER=mysql DB_DATABASE=hangout_test DB_USERNAME=root DB_PASSWORD= FLARUM_TEST_TMP_DIR_LOCAL=./tmp vendor/bin/phpstan analyse`.
- `cd js && npm ci && npm run build && npm run format-check && npm run check-typings`.
- Browser checks and screenshots: `~/github/scopes/hangout/` (`shot.mjs`, `scene.mjs`, `api.mjs`) against https://hangoutdev.test.
