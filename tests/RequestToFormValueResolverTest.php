<?php

declare(strict_types=1);

namespace AzYouness\RequestToFormBundle\Tests;

use AzYouness\RequestToFormBundle\ArgumentResolver\RequestToFormValueResolver;
use AzYouness\RequestToFormBundle\ArgumentTypeMatcher;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use AzYouness\RequestToFormBundle\PendingRequestToFormArgument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

final class RequestToFormValueResolverTest extends TestCase
{
    #[Test]
    public function returnsNoValuesWithoutMapRequestToFormAttribute(): void
    {
        $resolver = $this->createResolver();
        $argument = $this->createArgumentMetadata(name: 'product', type: \stdClass::class);

        $resolvedValues = iterator_to_array($resolver->resolve(new Request(), $argument));

        $this->assertSame([], $resolvedValues);
    }

    #[Test]
    public function returnsPendingArgumentWhenClassTypeCanBeMapped(): void
    {
        $resolver = $this->createResolver();
        $argument = $this->createArgumentMetadata(name: 'product', type: \stdClass::class, attributes: [new MapRequestToForm()]);

        $resolvedValues = iterator_to_array($resolver->resolve(new Request(), $argument));

        $this->assertCount(1, $resolvedValues);
        $this->assertInstanceOf(PendingRequestToFormArgument::class, $resolvedValues[0]);
    }

    #[Test]
    public function returnsPendingArgumentWhenBuiltInTypeHasExplicitFormType(): void
    {
        $resolver = $this->createResolver();
        $argument = $this->createArgumentMetadata(name: 'name', type: 'string', attributes: [
            new MapRequestToForm(formType: TextType::class),
        ]);

        $resolvedValues = iterator_to_array($resolver->resolve(new Request(), $argument));

        $this->assertCount(1, $resolvedValues);
        $this->assertInstanceOf(PendingRequestToFormArgument::class, $resolvedValues[0]);
    }

    #[Test]
    public function returnsPendingArgumentWhenFormInterfaceHasExplicitDataArgument(): void
    {
        $resolver = $this->createResolver();
        $argument = $this->createArgumentMetadata(name: 'form', type: FormInterface::class, attributes: [
            new MapRequestToForm(dataArgument: 'product'),
        ]);

        $resolvedValues = iterator_to_array($resolver->resolve(new Request(), $argument));

        $this->assertCount(1, $resolvedValues);
        $this->assertInstanceOf(PendingRequestToFormArgument::class, $resolvedValues[0]);
    }

    #[Test]
    public function throwsWhenMappedArgumentIsVariadic(): void
    {
        $resolver = $this->createResolver();
        $argument = $this->createArgumentMetadata(name: 'products', type: \stdClass::class, attributes: [new MapRequestToForm()], isVariadic: true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Mapping variadic argument "$products" is not supported.');

        iterator_to_array($resolver->resolve(new Request(), $argument));
    }

    #[Test]
    public function throwsWhenMappedArgumentHasNoType(): void
    {
        $resolver = $this->createResolver();
        $argument = $this->createArgumentMetadata(name: 'product', type: null, attributes: [new MapRequestToForm()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Could not resolve the "$product" controller argument: argument should have a type.');

        iterator_to_array($resolver->resolve(new Request(), $argument));
    }

    #[Test]
    public function throwsWhenFormInterfaceHasNoExplicitMappingOptions(): void
    {
        $resolver = $this->createResolver();
        $argument = $this->createArgumentMetadata(name: 'form', type: FormInterface::class, attributes: [new MapRequestToForm()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FormInterface targets require $formType or $dataArgument');

        iterator_to_array($resolver->resolve(new Request(), $argument));
    }

    #[Test]
    public function throwsWhenBuiltInTypeHasNoExplicitFormType(): void
    {
        $resolver = $this->createResolver();
        $argument = $this->createArgumentMetadata(name: 'name', type: 'string', attributes: [new MapRequestToForm()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('built-in targets require $formType');

        iterator_to_array($resolver->resolve(new Request(), $argument));
    }

    private function createResolver(): RequestToFormValueResolver
    {
        return new RequestToFormValueResolver(new ArgumentTypeMatcher());
    }

    /**
     * @param object[] $attributes
     */
    private function createArgumentMetadata(
        string $name,
        ?string $type,
        array $attributes = [],
        bool $isVariadic = false,
    ): ArgumentMetadata {
        return new ArgumentMetadata(
            name: $name,
            type: $type,
            isVariadic: $isVariadic,
            hasDefaultValue: false,
            defaultValue: null,
            attributes: $attributes,
        );
    }
}
