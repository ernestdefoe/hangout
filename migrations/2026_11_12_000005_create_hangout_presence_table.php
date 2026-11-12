<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Who is connected and where they stand. A row exists only while connected:
// leaving deletes it, and a browser that stops polling is swept out.
return Migration::createTable('hangout_presence', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->unsignedInteger('room_id');
    $table->unsignedTinyInteger('x')->default(0);
    $table->unsignedTinyInteger('y')->default(0);
    $table->boolean('invisible')->default(false);
    $table->string('afk', 120)->nullable();
    $table->dateTime('connected_at');
    $table->dateTime('seen_at');
    $table->dateTime('active_at');

    $table->index('room_id');
    $table->index('seen_at');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('room_id')->references('id')->on('hangout_rooms')->cascadeOnDelete();
});
