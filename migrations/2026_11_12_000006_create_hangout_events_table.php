<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Everything said and done, in order. The id is every reader's cursor (never a
// clock): Events::write takes the hangout_meta row lock, so ids commit in order.
// `audience` says who sees it: room, all, area, chan, or user (via hangout_inbox).
// `data` is a JSON snapshot (names, colours, positions), so reading needs no joins.
return Migration::createTable('hangout_events', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->string('audience', 8);
    $table->string('kind', 16);
    $table->unsignedInteger('room_id')->nullable();
    $table->unsignedInteger('channel_id')->nullable();
    $table->string('area', 60)->nullable();
    $table->unsignedInteger('actor_id')->nullable();
    $table->text('body')->nullable();
    $table->text('data')->nullable();
    $table->dateTime('created_at');

    $table->index(['room_id', 'id']);
    $table->index('created_at');
    $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('room_id')->references('id')->on('hangout_rooms')->nullOnDelete();
});
