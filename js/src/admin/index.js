import app from 'flarum/admin/app';

app.initializers.add('komari-fcm', () => {
  app.extensionData
    .for('komari-fcm')
    .registerSetting({
      setting: 'komari-fcm.service_account_path',
      label: app.translator.trans('komari-fcm.admin.settings.service_account_path_label'),
      help: app.translator.trans('komari-fcm.admin.settings.service_account_path_help'),
      type: 'text',
    });
});
