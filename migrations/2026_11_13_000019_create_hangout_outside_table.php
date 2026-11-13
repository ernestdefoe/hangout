<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// A whisper that went out to Parley, ramon/chat or Messages: where it went, so
// an answer there can come back to the sender's Tells.
return Migration::createTable('hangout_outside', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('sender_id');
    $table->unsignedInteger('recipient_id');
    $table->string('target', 16);
    $table->unsignedInteger('thread_id');
    $table->dateTime('sent_at');

    $table->unique(['sender_id', 'recipient_id', 'target']);
    $table->index(['target', 'thread_id']);
    $table->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('recipient_id')->references('id')->on('users')->cascadeOnDelete();
});
