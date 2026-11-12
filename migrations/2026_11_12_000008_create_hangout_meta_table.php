<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Builder;

// One row: the lock every event write takes, and the newest event id.
return [
    'up' => function (Builder $schema) {
        $schema->create('hangout_meta', function ($table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('head')->default(0);
        });
        $schema->getConnection()->table('hangout_meta')->insert(['id' => 1, 'head' => 0]);
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('hangout_meta');
    },
];
