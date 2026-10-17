<?php

namespace Tests\Unit;

use App\Support\PasswordProvisoria;
use PHPUnit\Framework\TestCase;

/**
 * The provisional password of a professional account is the only credential
 * that travels by email, so it must be high-entropy and never derived from
 * anything the invited person (or the titular) controls.
 */
class PasswordProvisoriaTest extends TestCase
{
    public function test_it_has_the_documented_length_and_alphabet(): void
    {
        $password = PasswordProvisoria::generar();

        $this->assertSame(PasswordProvisoria::LONGITUD, strlen($password));
        $this->assertSame(12, PasswordProvisoria::LONGITUD);
        $this->assertMatchesRegularExpression('/\A['.PasswordProvisoria::ALFABETO.']{12}\z/', $password);
    }

    public function test_the_alphabet_has_no_visually_ambiguous_characters(): void
    {
        // The password is read from an email and typed by hand.
        foreach (['0', 'O', '1', 'l', 'I'] as $ambiguo) {
            $this->assertStringNotContainsString($ambiguo, PasswordProvisoria::ALFABETO);
        }
        $this->assertGreaterThanOrEqual(50, strlen(PasswordProvisoria::ALFABETO));
    }

    public function test_every_call_returns_a_different_value(): void
    {
        $generadas = [];
        for ($i = 0; $i < 300; $i++) {
            $generadas[] = PasswordProvisoria::generar();
        }

        $this->assertCount(300, array_unique($generadas));
    }

    public function test_every_position_draws_from_the_whole_alphabet(): void
    {
        // A constant, a counter or a hash prefix would fail this: with 600
        // draws over a 55-character alphabet each position shows dozens of
        // distinct characters.
        $porPosicion = array_fill(0, PasswordProvisoria::LONGITUD, []);
        for ($i = 0; $i < 600; $i++) {
            foreach (str_split(PasswordProvisoria::generar()) as $posicion => $caracter) {
                $porPosicion[$posicion][$caracter] = true;
            }
        }

        foreach ($porPosicion as $posicion => $vistos) {
            $this->assertGreaterThan(35, count($vistos), "Position {$posicion} is not uniformly drawn");
        }
    }

    public function test_it_has_no_dependency_on_any_input(): void
    {
        // generar() takes no arguments: nothing about the person can leak in.
        $metodo = new \ReflectionMethod(PasswordProvisoria::class, 'generar');

        $this->assertSame(0, $metodo->getNumberOfParameters());
    }
}
