<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// A door out of a room: on the back wall (n), a side (e, w) or the front (s),
// `pos` tiles along that edge.
return Migration::createTable('hangout_exits', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('room_id');
    $table->unsignedInteger('target_id');
    $table->string('label', 60)->nullable();
    $table->char('side', 1)->default('n');
    $table->unsignedTinyInteger('pos')->default(0);

    $table->foreign('room_id')->references('id')->on('hangout_rooms')->cascadeOnDelete();
    $table->foreign('target_id')->references('id')->on('hangout_rooms')->cascadeOnDelete();
});
