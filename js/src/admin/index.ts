import app from 'flarum/admin/app';
import { settingsPage } from './settings';

/*
 * Hangout's admin: TCZ's ranks (name, colour and the Flarum group behind each),
 * talk limits, how long talk is kept, the forum's clock, and permissions.
 * Rooms are built in the Hangout itself (phase 4).
 */
app.initializers.add('ernestdefoe-hangout', () => {
  settingsPage();
});
