<?php

namespace Tests\Unit\Modules;

use App\Modules\VersionConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VersionConstraintTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function cases(): array
    {
        return [
            'caret major' => ['1.4.0', '^1.2', true],
            'caret next major' => ['2.0.0', '^1.2', false],
            'caret below' => ['1.1.9', '^1.2', false],
            'caret zero minor' => ['0.1.5', '^0.1', true],
            'caret zero next minor' => ['0.2.0', '^0.1', false],
            'caret zero patch' => ['0.0.4', '^0.0.3', false],
            'caret excludes next pre-release' => ['2.0.0-beta', '^1.0', false],
            'tilde patch' => ['1.2.9', '~1.2.3', true],
            'tilde patch next minor' => ['1.3.0', '~1.2.3', false],
            'tilde minor' => ['1.9.0', '~1.2', true],
            'tilde minor next major' => ['2.0.0', '~1.2', false],
            'wildcard' => ['1.2.7', '1.2.*', true],
            'wildcard miss' => ['1.3.0', '1.2.*', false],
            'star' => ['9.9.9', '*', true],
            'range with space' => ['1.5.0', '>=1.0 <2.0', true],
            'range with comma' => ['2.0.0', '>=1.0, <2.0', false],
            'operator with space' => ['1.0.0', '>= 1.0', true],
            'hyphen range' => ['2.0.5', '1.0 - 2.0', true],
            'hyphen range above' => ['2.1.0', '1.0 - 2.0', false],
            'hyphen range full upper' => ['2.0.1', '1.0.0 - 2.0.0', false],
            'exact' => ['1.2.3', '1.2.3', true],
            'exact miss' => ['1.2.4', '1.2.3', false],
            'not equal' => ['1.2.4', '!=1.2.3', true],
            'or' => ['2.1.0', '^1.0 || ^2.0', true],
            'or miss' => ['3.0.0', '^1.0 || ^2.0', false],
            'pre-release below release' => ['1.0.0-alpha', '>=1.0.0', false],
            'pre-release order' => ['1.0.0-beta', '>1.0.0-alpha', true],
            'v prefix' => ['1.2.0', '^v1.0', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_versions_against_constraints(string $version, string $constraint, bool $expected)
    {
        $this->assertSame($expected, VersionConstraint::satisfies($version, $constraint));
    }

    public function test_it_rejects_unreadable_constraints()
    {
        $this->assertFalse(VersionConstraint::isValid(''));
        $this->assertFalse(VersionConstraint::isValid('latest'));
        $this->assertFalse(VersionConstraint::isValid('^one'));
        $this->assertTrue(VersionConstraint::isValid('^0.1 || >=2.0, <3'));
    }

    public function test_versions_need_three_numbers()
    {
        $this->assertTrue(VersionConstraint::isVersion('1.2.3'));
        $this->assertTrue(VersionConstraint::isVersion('1.2.3-beta.1+build'));
        $this->assertFalse(VersionConstraint::isVersion('1.2'));
        $this->assertFalse(VersionConstraint::isVersion('one'));
    }
}
