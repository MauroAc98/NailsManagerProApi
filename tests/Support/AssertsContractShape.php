<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Additive-only contract pins for JSON payloads.
 *
 * A "shape" is the recorded snapshot of what an endpoint returns TODAY:
 *  - object  => ['key' => <spec>, ...]   every listed key must be present;
 *  - list    => ['*' => <spec>]          non-empty list, every element matches;
 *  - scalar  => 'int|string|null' ...    union of int, float, number (int|float),
 *               string, bool, array, null.
 *
 * Keys missing from the shape are ALLOWED in the response, so later changes
 * can add optional keys but can never remove, rename or retype a pinned one.
 */
trait AssertsContractShape
{
    protected function assertContractShape(array $shape, mixed $actual, string $path = '$'): void
    {
        if (array_keys($shape) === ['*']) {
            Assert::assertIsArray($actual, "{$path} must be a list");
            Assert::assertTrue(array_is_list($actual), "{$path} must be a list");
            Assert::assertNotEmpty($actual, "{$path} fixture must return at least one element to pin its shape");
            foreach ($actual as $i => $item) {
                $this->assertContractSpec($shape['*'], $item, "{$path}[{$i}]");
            }

            return;
        }

        Assert::assertIsArray($actual, "{$path} must be an object");
        foreach ($shape as $key => $spec) {
            Assert::assertArrayHasKey($key, $actual, "{$path}.{$key} is missing from the response");
            $this->assertContractSpec($spec, $actual[$key], "{$path}.{$key}");
        }
    }

    private function assertContractSpec(string|array $spec, mixed $value, string $path): void
    {
        if (is_array($spec)) {
            $this->assertContractShape($spec, $value, $path);

            return;
        }

        $actualType = match (true) {
            is_null($value) => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            default => 'array',
        };
        $allowed = explode('|', $spec);
        if (in_array('number', $allowed, true)) {
            $allowed = array_merge($allowed, ['int', 'float']);
        }

        Assert::assertContains($actualType, $allowed, "{$path} is {$actualType}, contract pins {$spec}");
    }
}
