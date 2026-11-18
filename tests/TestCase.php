<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Los tests corren en la hora de la clínica demo (La Paz), igual que cada
     * petición web o de la API (SetTenant fija la zona de la clínica). Así
     * «hoy» es el mismo día en el test y en el servidor, también entre las
     * 20:00 y la medianoche, cuando en UTC ya es el día siguiente.
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Después del arranque: al iniciar, Laravel fija la zona de config/app.php (UTC).
        config(['app.timezone' => 'America/La_Paz']);
        date_default_timezone_set('America/La_Paz');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        date_default_timezone_set('UTC');
    }
}
