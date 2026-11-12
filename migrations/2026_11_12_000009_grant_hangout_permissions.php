<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Members can enter, talk and shout (shouting is rate-limited and rank-gated
// on top); guests see nothing until an admin allows them to look in.
return Migration::addPermissions([
    'ernestdefoe-hangout.view' => Group::MEMBER_ID,
    'ernestdefoe-hangout.talk' => Group::MEMBER_ID,
    'ernestdefoe-hangout.shout' => Group::MEMBER_ID,
]);
