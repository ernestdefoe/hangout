<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// TCZ's mail: a note for someone, connected or not. It arrives as a Flarum notification.
return Migration::createTable('hangout_mail', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('from_id')->nullable();
    $table->unsignedInteger('to_id');
    $table->string('subject', 80)->nullable();
    $table->text('body');
    $table->dateTime('read_at')->nullable();
    $table->dateTime('created_at');

    $table->index(['to_id', 'id']);
    $table->foreign('from_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('to_id')->references('id')->on('users')->cascadeOnDelete();
});
