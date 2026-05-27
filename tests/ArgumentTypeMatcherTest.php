<?php

declare(strict_types=1);

namespace AzYouness\RequestToFormBundle\Tests;

use AzYouness\RequestToFormBundle\ArgumentTypeMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArgumentTypeMatcherTest extends TestCase
{
    #[Test]
    public function matchesIntValueWithIntType(): void
    {
        $matcher = new ArgumentTypeMatcher();
        $type = $this->getFirstParameterType(static fn (int $value) => null);

        $this->assertTrue($matcher->matches(10, $type));
    }

    #[Test]
    public function doesNotMatchStringValueWithIntType(): void
    {
        $matcher = new ArgumentTypeMatcher();
        $type = $this->getFirstParameterType(static fn (int $value) => null);

        $this->assertFalse($matcher->matches('10', $type));
    }

    #[Test]
    public function matchesObjectValueWithClassType(): void
    {
        $matcher = new ArgumentTypeMatcher();
        $type = $this->getFirstParameterType(static fn (\stdClass $value) => null);

        $this->assertTrue($matcher->matches(new \stdClass(), $type));
    }

    #[Test]
    public function doesNotMatchObjectValueWithDifferentClassType(): void
    {
        $matcher = new ArgumentTypeMatcher();
        $type = $this->getFirstParameterType(static function (\stdClass $value): void {});

        $this->assertFalse($matcher->matches(new \DateTimeImmutable(), $type));
    }

    #[Test]
    public function matchesNullWhenTypeAllowsNull(): void
    {
        $matcher = new ArgumentTypeMatcher();
        $type = $this->getFirstParameterType(static function (?int $value): void {});

        $this->assertTrue($matcher->matches(null, $type));
    }

    #[Test]
    public function doesNotMatchNullWhenTypeDoesNotAllowNull(): void
    {
        $matcher = new ArgumentTypeMatcher();
        $type = $this->getFirstParameterType(static function (int $value): void {});

        $this->assertFalse($matcher->matches(null, $type));
    }

    #[Test]
    public function matchesTrueValueWithTrueType(): void
    {
        $matcher = new ArgumentTypeMatcher();
        $type = $this->getFirstParameterType(static function (true $value): void {});

        $this->assertTrue($matcher->matches(true, $type));
    }

    #[Test]
    public function doesNotMatchFalseValueWithTrueType(): void
    {
        $matcher = new ArgumentTypeMatcher();
        $type = $this->getFirstParameterType(static function (true $value): void {});

        $this->assertFalse($matcher->matches(false, $type));
    }

    private function getFirstParameterType(\Closure $closure): \ReflectionNamedType
    {
        $type = (new \ReflectionFunction($closure))->getParameters()[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);

        return $type;
    }

    public static function builtInTypeProvider(): array
    {
        return [
            'int' => ['int', true],
            'string' => ['string', true],
            'array' => ['array', true],
            'bool' => ['bool', true],
            'float' => ['float', true],
            'object' => ['object', true],
            'mixed' => ['mixed', true],
            'null' => ['null', true],
            'true' => ['true', true],
            'false' => ['false', true],
            'class' => [\stdClass::class, false],
            'unknown' => ['unknown', false],
        ];
    }

    #[Test]
    #[DataProvider('builtInTypeProvider')]
    public function checksIfTypeIsBuiltIn(string $type, bool $expected): void
    {
        $matcher = new ArgumentTypeMatcher();

        $this->assertSame($expected, $matcher->isBuiltInType($type));
    }
}
