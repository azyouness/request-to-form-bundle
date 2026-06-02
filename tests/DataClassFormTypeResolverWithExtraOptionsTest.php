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

final class DataClassFormTypeResolverWithExtraOptionsTest extends TestCase
{
    #[Test]
    public function resolvesDataClassAndFormTypeWithRequiredOptions(): void
    {
        $resolver = $this->createResolver([
            ExtraOptionsTestProductType::class,
        ]);

        $this->assertSame(ExtraOptionsTestProduct::class, $resolver->resolveDataClass(ExtraOptionsTestProductType::class));
        $this->assertSame(ExtraOptionsTestProductType::class, $resolver->resolveFormType(ExtraOptionsTestProduct::class));
    }

    /**
     * @param list<class-string<FormTypeInterface<mixed>>> $formTypeClasses
     */
    private function createResolver(array $formTypeClasses): DataClassFormTypeResolver
    {
        $formRegistry = $this->createStub(FormRegistryInterface::class);
        $formRegistry
            ->method('getType')
            ->willReturnCallback(
                function (string $formType): ResolvedFormTypeInterface {
                    $formType = new $formType();

                    if (!$formType instanceof FormTypeInterface) {
                        throw new \LogicException('Expected a Symfony form type.');
                    }

                    return $this->createResolvedFormType($formType);
                }
            );

        $formTypes = array_map(
            static fn (string $formType) => new $formType(),
            $formTypeClasses
        );

        return new DataClassFormTypeResolver($formTypes, $formRegistry);
    }

    /**
     * @param FormTypeInterface<mixed> $formType
     */
    private function createResolvedFormType(FormTypeInterface $formType): ResolvedFormTypeInterface
    {
        $optionsResolver = new OptionsResolver();
        $formType->configureOptions($optionsResolver);

        $resolvedType = $this->createStub(ResolvedFormTypeInterface::class);
        $resolvedType->method('getOptionsResolver')->willReturn($optionsResolver);

        return $resolvedType;
    }
}

final class ExtraOptionsTestProduct
{
}

/**
 * @extends AbstractType<ExtraOptionsTestProduct>
 */
final class ExtraOptionsTestProductType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('slug');
        $resolver->setDefault('data_class', ExtraOptionsTestProduct::class);
    }
}
