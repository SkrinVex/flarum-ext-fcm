<?php

namespace Komari\Fcm\Model;

use Flarum\Database\AbstractModel;

class FcmToken extends AbstractModel
{
    protected $table = 'fcm_tokens';

    protected $fillable = ['user_id', 'token', 'device_name'];
}
