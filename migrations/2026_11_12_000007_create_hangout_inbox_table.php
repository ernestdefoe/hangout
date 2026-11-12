<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Who a private event (page, tell, remote) reaches. Only these people read it.
return Migration::createTable('hangout_inbox', function (Blueprint $table) {
    $table->unsignedInteger('user_id');
    $table->unsignedBigInteger('event_id');

    $table->primary(['user_id', 'event_id']);
    $table->index('event_id');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('event_id')->references('id')->on('hangout_events')->cascadeOnDelete();
});
