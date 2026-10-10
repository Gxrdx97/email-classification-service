<?php

use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Tests\TestCase;

describe('fronteira de testes da aplicação', function () {
    it('mantém as suites Laravel sobre o TestCase compartilhado do projeto', function () {
        expect(is_subclass_of(TestCase::class, LaravelTestCase::class))->toBeTrue();
    });
});
