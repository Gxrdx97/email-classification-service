<?php

use Illuminate\Support\Facades\DB;

describe('ambiente de teste', function () {
    it('conecta ao PostgreSQL no banco de teste dedicado', function () {
        // Given: o PHPUnit configura PostgreSQL e email_classification_test.
        $connection = DB::connection();

        // When: uma consulta real é executada contra o serviço PostgreSQL.
        $identity = $connection->selectOne(
            'select current_database() as database_name, current_user as database_user'
        );

        // Then: o teste não caiu em SQLite nem em uma base local genérica.
        expect($connection->getDriverName())->toBe('pgsql')
            ->and($identity->database_name)->toBe('email_classification_test')
            ->and($identity->database_user)->toBe('sail');
    });

    it('mantém filas e armazenamentos efêmeros na suíte normal', function () {
        expect(config('queue.default'))->toBe('sync')
            ->and(config('cache.default'))->toBe('array')
            ->and(config('session.driver'))->toBe('array')
            ->and(config('mail.default'))->toBe('array');
    });

    it('expõe os health checks HTTP aprovados', function () {
        $this->get('/api/health/live')->assertOk();
        $this->get('/api/health/ready')->assertOk();
    });
});
