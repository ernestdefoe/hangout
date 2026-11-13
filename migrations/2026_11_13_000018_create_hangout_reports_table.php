<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// One report per member per message: the Flarum flag it raised sits on post_id.
return Migration::createTable('hangout_reports', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedBigInteger('event_id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('post_id')->nullable();
    $table->dateTime('created_at');

    $table->unique(['event_id', 'user_id']);
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('post_id')->references('id')->on('posts')->nullOnDelete();
});
