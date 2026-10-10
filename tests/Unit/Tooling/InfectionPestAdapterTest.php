<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('adaptador Pest do Infection', function () {
    it('applies forced PHPUnit environment variables to the Pest subprocess', function () {
        $root = dirname(__DIR__, 3);
        $environment = array_merge(getenv() ?: [], [
            'QUEUE_CONNECTION' => 'redis',
            'CACHE_STORE' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'MAIL_MAILER' => 'smtp',
        ]);
        $process = new Process([
            PHP_BINARY,
            $root.'/scripts/infection-pest.php',
            '--configuration',
            $root.'/phpunit.xml',
            '--filter=mantém filas e armazenamentos efêmeros',
            '--no-progress',
        ], $root, $environment);
        $process->setTimeout(30);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())->toContain('1 passed');
    });
});
