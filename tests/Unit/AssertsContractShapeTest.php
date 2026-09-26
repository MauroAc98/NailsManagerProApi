<?php

namespace Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssertsContractShape;

/**
 * Proves the contract helper is meaningful: additive changes pass, while a
 * removed, renamed or retyped pinned key fails.
 */
class AssertsContractShapeTest extends TestCase
{
    use AssertsContractShape;

    private const SHAPE = [
        'id' => 'int',
        'precio' => 'string|null',
        'items' => ['*' => ['n' => 'number']],
        'nested' => ['ok' => 'bool'],
    ];

    private function payload(array $override = []): array
    {
        return array_replace([
            'id' => 1, 'precio' => null, 'items' => [['n' => 1.5]], 'nested' => ['ok' => true],
        ], $override);
    }

    public function test_accepts_the_recorded_payload_and_new_optional_keys(): void
    {
        $this->assertContractShape(self::SHAPE, $this->payload());
        $this->assertContractShape(self::SHAPE, $this->payload(['grupo_id' => null, 'nested' => ['ok' => false, 'extra' => 1]]));
    }

    public function test_rejects_a_removed_key(): void
    {
        $payload = $this->payload();
        unset($payload['id']);

        $this->expectException(AssertionFailedError::class);
        $this->assertContractShape(self::SHAPE, $payload);
    }

    public function test_rejects_a_retyped_key(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertContractShape(self::SHAPE, $this->payload(['id' => '1']));
    }

    public function test_rejects_a_missing_nested_key_inside_a_list_element(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertContractShape(self::SHAPE, $this->payload(['items' => [['x' => 1]]]));
    }

    public function test_rejects_an_empty_list_because_the_fixture_would_pin_nothing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertContractShape(self::SHAPE, $this->payload(['items' => []]));
    }
}
