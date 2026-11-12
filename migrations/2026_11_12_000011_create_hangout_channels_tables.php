<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Builder;

// TCZ's chat channels: members join them, and they follow you from room to room.
return [
    'up' => function (Builder $schema) {
        $schema->create('hangout_channels', function ($table) {
            $table->increments('id');
            $table->string('slug', 30)->unique();
            $table->string('name', 30);
            $table->string('description', 160)->nullable();
            $table->unsignedInteger('owner_id')->nullable();
            $table->boolean('permanent')->default(false);
            $table->dateTime('created_at');
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
        });
        $schema->create('hangout_channel_members', function ($table) {
            $table->unsignedInteger('channel_id');
            $table->unsignedInteger('user_id');
            $table->dateTime('joined_at');
            $table->primary(['channel_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('channel_id')->references('id')->on('hangout_channels')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        $now = date('Y-m-d H:i:s');
        foreach ([['chat', 'The main channel: talk across every room.'], ['gaming', 'Games, the Arcade, and who is playing what.'], ['help', 'Ask about the Hangout or the forum; staff listen here.']] as [$slug, $desc]) {
            $schema->getConnection()->table('hangout_channels')->insert(['slug' => $slug, 'name' => $slug, 'description' => $desc, 'permanent' => true, 'created_at' => $now]);
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('hangout_channel_members');
        $schema->dropIfExists('hangout_channels');
    },
];
