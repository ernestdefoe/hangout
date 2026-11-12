<?php

use Flarum\Database\Migration;

// Aliases, TCZ's @partner, and when someone last shouted (the wait lives on the server).
return Migration::addColumns('hangout_profiles', [
    'aliases' => ['text', 'nullable' => true],
    'partner_id' => ['integer', 'unsigned' => true, 'nullable' => true],
    'partner_state' => ['string', 'length' => 10, 'nullable' => true],
    'shouted_at' => ['dateTime', 'nullable' => true],
]);
