<?php

declare(strict_types=1);

namespace AzYouness\RequestToFormBundle\Tests;

use AzYouness\RequestToFormBundle\DataClassFormTypeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormRegistryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\ResolvedFormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DataClassFormTypeResolverTest extends TestCase
{
    #[Test]
    public function resolvesFormTypeByDataClass(): void
    {
        $resolver = $this->createResolver([
            TestProductType::class => TestProduct::class,
        ]);

        $this->assertSame(TestProductType::class, $resolver->resolveFormType(TestProduct::class));
    }

    #[Test]
    public function resolvesDataClassFromFormType(): void
    {
        $resolver = $this->createResolver([
            TestProductType::class => TestProduct::class,
        ]);

        $this->assertSame(TestProduct::class, $resolver->resolveDataClass(TestProductType::class));
    }

    #[Test]
    public function returnsNullWhenFormTypeHasNoDataClass(): void
    {
        $resolver = $this->createResolver([
            TestFormWithoutDataClassType::class => null,
        ]);

        $this->assertNull($resolver->resolveDataClass(TestFormWithoutDataClassType::class));
    }

    #[Test]
    public function throwsWhenNoFormTypeMatchesDataClass(): void
    {
        $resolver = $this->createResolver([]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('No form type found with data_class "%s".', TestProduct::class));

        $resolver->resolveFormType(TestProduct::class);
    }

    #[Test]
    public function throwsWhenMultipleFormTypesMatchDataClass(): void
    {
        $resolver = $this->createResolver([
            TestProductType::class => TestProduct::class,
            TestDuplicateProductType::class => TestProduct::class,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('Multiple form types found with data_class "%s"', TestProduct::class));

        $resolver->resolveFormType(TestProduct::class);
    }

    #[Test]
    public function throwsWhenDataClassDoesNotExist(): void
    {
        $resolver = $this->createResolver([]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Data class "MissingDataClass" does not exist.');

        $resolver->resolveFormType('MissingDataClass');
    }

    /**
     * @param array<class-string<FormTypeInterface<mixed>>, class-string|null> $formTypeDataClasses
     */
    private function createResolver(array $formTypeDataClasses): DataClassFormTypeResolver
    {
        $formRegistry = $this->createStub(FormRegistryInterface::class);
        $formRegistry
            ->method('getType')
            ->willReturnCallback(
                fn (string $formType): ResolvedFormTypeInterface => $this->createResolvedFormType($formTypeDataClasses[$formType] ?? null)
            );

        $formTypes = array_map(
            static fn (string $formType) => new $formType(),
            array_keys($formTypeDataClasses)
        );

        return new DataClassFormTypeResolver($formTypes, $formRegistry);
    }

    /**
     * @param class-string|null $dataClass
     */
    private function createResolvedFormType(?string $dataClass): ResolvedFormTypeInterface
    {
        $optionsResolver = new OptionsResolver();

        if (null !== $dataClass) {
            $optionsResolver->setDefault('data_class', $dataClass);
        }

        $resolvedType = $this->createStub(ResolvedFormTypeInterface::class);
        $resolvedType->method('getOptionsResolver')->willReturn($optionsResolver);

        return $resolvedType;
    }
}

final class TestProduct
{
}

/**
 * @extends AbstractType<TestProduct>
 */
final class TestProductType extends AbstractType
{
}

/**
 * @extends AbstractType<TestProduct>
 */
final class TestDuplicateProductType extends AbstractType
{
}

/**
 * @extends AbstractType<mixed>
 */
final class TestFormWithoutDataClassType extends AbstractType
{
}
