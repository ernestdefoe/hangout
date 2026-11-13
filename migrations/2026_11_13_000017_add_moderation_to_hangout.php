<?php

use Flarum\Database\Migration;

// A Druid's mute: the member can't talk until this time.
return Migration::addColumns('hangout_profiles', [
    'muted_until' => ['dateTime', 'nullable' => true],
]);
