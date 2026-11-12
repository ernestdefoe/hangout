<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Builder;

// TCZ's BBS, as the notice board in the Lobby: topics, and short notes in each.
return [
    'up' => function (Builder $schema) {
        $schema->create('hangout_bbs_topics', function ($table) {
            $table->increments('id');
            $table->string('name', 40);
            $table->string('description', 160)->nullable();
            $table->integer('position')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->dateTime('last_at')->nullable();
        });
        $schema->create('hangout_bbs_messages', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('topic_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('subject', 80);
            $table->text('body');
            $table->dateTime('created_at');
            $table->index(['topic_id', 'id']);
            $table->foreign('topic_id')->references('id')->on('hangout_bbs_topics')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        $p = 0;
        foreach ([['General', 'Anything and everything.'], ['Events', 'Parties, tournaments and meet-ups in the Hangout.'], ['Ideas', 'Rooms, feelings and features you would like to see.']] as [$name, $desc]) {
            $schema->getConnection()->table('hangout_bbs_topics')->insert(['name' => $name, 'description' => $desc, 'position' => $p++]);
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('hangout_bbs_messages');
        $schema->dropIfExists('hangout_bbs_topics');
    },
];
