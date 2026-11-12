<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// A room: a floor of w×h tiles with a back wall. `look` holds the wall, floor
// and lighting; `area` groups rooms for `yell`.
return Migration::createTable('hangout_rooms', function (Blueprint $table) {
    $table->increments('id');
    $table->string('slug', 60)->unique();
    $table->string('name', 80);
    $table->text('description')->nullable();
    $table->string('area', 60)->default('Hangout');
    $table->unsignedInteger('owner_id')->nullable();
    $table->unsignedTinyInteger('width')->default(20);
    $table->unsignedTinyInteger('height')->default(11);
    $table->text('look')->nullable();
    $table->boolean('locked')->default(false);
    $table->boolean('is_default')->default(false);
    $table->integer('position')->default(0);
    $table->timestamps();

    $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
});
