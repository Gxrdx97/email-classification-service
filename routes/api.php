<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', fn () => response()->json(['status' => 'ok']));

Route::get('/health/ready', function () {
    $checks = [
        'postgresql' => false,
        'redis' => false,
    ];

    try {
        DB::connection()->select('select 1');
        $checks['postgresql'] = true;
    } catch (Throwable) {
        // A falha fica limitada ao indicador genérico de prontidão.
    }

    try {
        Redis::connection()->ping();
        $checks['redis'] = true;
    } catch (Throwable) {
        // A falha fica limitada ao indicador genérico de prontidão.
    }

    $ready = ! in_array(false, $checks, true);

    return response()->json([
        'status' => $ready ? 'ready' : 'not_ready',
        'checks' => $checks,
    ], $ready ? 200 : 503);
});
