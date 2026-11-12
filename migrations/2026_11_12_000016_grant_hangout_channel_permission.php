<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'ernestdefoe-hangout.createChannels' => Group::MODERATOR_ID,
]);
