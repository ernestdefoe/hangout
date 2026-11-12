<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Furniture and decor placed in a room. `kind` is a key of Furniture::CATALOG;
// y = -1 hangs it on the back wall.
return Migration::createTable('hangout_items', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('room_id');
    $table->string('kind', 32);
    $table->smallInteger('x');
    $table->smallInteger('y');
    $table->unsignedTinyInteger('rot')->default(0);
    $table->unsignedInteger('owner_id')->nullable();

    $table->index('room_id');
    $table->foreign('room_id')->references('id')->on('hangout_rooms')->cascadeOnDelete();
    $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
});
