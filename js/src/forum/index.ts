import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LinkButton from 'flarum/common/components/LinkButton';

/*
 * forum.js is loaded on every page, so it only adds the route and the nav
 * link. The world (renderer, panels, command bar) is one chunk, fetched the
 * first time someone opens the Hangout.
 */
app.initializers.add('ernestdefoe/hangout', () => {
  app.routes['hangout'] = {
    path: '/hangout',
    component: () => import('./world').then(async (w) => (await w.loadCore(), { default: w.HangoutPage })),
  };

  // String form: IndexSidebar is lazy in Flarum 2, so an imported copy is never the one rendered.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: any) {
    if (!app.forum.attribute('hangoutCanView') || !app.forum.attribute('hangoutNav')) return;
    items.add(
      'hangout',
      LinkButton.component({ href: app.route('hangout'), icon: 'fas fa-couch' }, app.translator.trans('ernestdefoe-hangout.forum.nav')),
      77
    );
  });
});
