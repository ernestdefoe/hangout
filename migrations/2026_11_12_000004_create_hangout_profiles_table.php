<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// What a member has set about themselves in the Hangout (TCZ's @title,
// @prefix, @suffix, @desc, @feeling, connect/disconnect messages…).
return Migration::createTable('hangout_profiles', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->string('title', 80)->nullable();
    $table->string('prefix', 40)->nullable();
    $table->string('suffix', 40)->nullable();
    $table->text('description')->nullable();
    $table->string('feeling', 30)->nullable();
    $table->string('cmessage', 120)->nullable();
    $table->string('dmessage', 120)->nullable();
    $table->string('color', 9)->nullable();
    $table->unsignedInteger('home_id')->nullable();
    $table->unsignedInteger('total_seconds')->default(0);
    $table->dateTime('last_connected_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('home_id')->references('id')->on('hangout_rooms')->nullOnDelete();
});
