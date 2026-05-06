<?php

namespace Komari\Fcm;

use Flarum\Extend;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->post('/fcm/register', 'komari.fcm.register', Api\RegisterTokenController::class)
        ->delete('/fcm/unregister', 'komari.fcm.unregister', Api\UnregisterTokenController::class),

    (new Extend\Notification())
        ->beforeSending(Listener\SendFcmNotification::class),
];
