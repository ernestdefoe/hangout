import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LinkButton from 'flarum/common/components/LinkButton';
import Notification from 'flarum/forum/components/Notification';
import username from 'flarum/common/helpers/username';

/*
 * forum.js is loaded on every page, so it only adds the route, the nav link
 * and the "sent you Hangout mail" alert. The world (renderer, panels,
 * command bar) is one chunk, fetched the first time someone opens it.
 */

class MailNotification extends Notification {
  icon() {
    return 'fas fa-envelope';
  }
  href() {
    return app.route('hangout') + '?tab=mail';
  }
  content() {
    const from = (this.attrs.notification as any).fromUser();
    return app.translator.trans('ernestdefoe-hangout.forum.notification.mail', { username: from ? username(from) : '' });
  }
  excerpt() {
    return null;
  }
}

app.initializers.add('ernestdefoe/hangout', () => {
  app.routes['hangout'] = {
    path: '/hangout',
    component: () => import('./world').then(async (w) => (await w.loadCore(), { default: w.HangoutPage })),
  };

  app.notificationComponents.hangoutMail = MailNotification;

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

// The notification preference row in Settings.
extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: any) {
  items.add('hangoutMail', {
    name: 'hangoutMail',
    icon: 'fas fa-envelope',
    label: app.translator.trans('ernestdefoe-hangout.forum.notification.setting'),
  });
});
