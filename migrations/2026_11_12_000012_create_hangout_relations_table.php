<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// TCZ's friends and enemies lists: friends are pinged when they connect;
// an enemy's talk is hidden from you and their pages and tells don't reach you.
return Migration::createTable('hangout_relations', function (Blueprint $table) {
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('other_id');
    $table->string('kind', 6);
    $table->dateTime('created_at');

    $table->primary(['user_id', 'other_id']);
    $table->index('other_id');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('other_id')->references('id')->on('users')->cascadeOnDelete();
});
