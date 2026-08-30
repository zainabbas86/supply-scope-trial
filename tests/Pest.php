<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests are bound to the Laravel TestCase so they boot the full
| application, and each one runs inside a transaction that is rolled back
| afterwards (RefreshDatabase) — so tests never leak state into each other.
|
| Unit tests are deliberately left on PHPUnit's plain TestCase: they should
| not need a booted framework, and keeping them framework-free makes it
| obvious when a "unit" test has quietly grown a database dependency.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
