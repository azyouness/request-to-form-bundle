<?php

namespace AzYouness\RequestToFormBundle;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Form\Exception\ExceptionInterface as FormExceptionInterface;
use Symfony\Component\Form\FormRegistryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface as OptionsResolverExceptionInterface;

/**
 * Resolves the relation between Symfony form types and their data_class option.
 *
 * It can infer the unique form type for a data class and inspect the data_class
 * configured by a form type.
 */
final class DataClassFormTypeResolver
{
    /**
     * @var array<class-string, list<class-string<FormTypeInterface<mixed>>>>|null
     */
    private ?array $formTypesByDataClass = null;

    /**
     * @param iterable<FormTypeInterface<mixed>> $formTypes
     */
    public function __construct(
        // Lazy iterable; form type services are instantiated only when the index is built.
        #[AutowireIterator('form.type')]
        private readonly iterable $formTypes,
        private readonly FormRegistryInterface $formRegistry,
    ) {
    }

    /**
     * @return class-string<FormTypeInterface<mixed>>
     */
    public function resolveFormType(string $dataClass): string
    {
        if (!class_exists($dataClass)) {
            throw new \LogicException(sprintf('Data class "%s" does not exist.', $dataClass));
        }

        $matches = $this->findFormTypesForDataClass($dataClass);

        if (1 === count($matches)) {
            return $matches[0];
        }

        if ([] === $matches) {
            throw new \LogicException(sprintf('No form type found with data_class "%s". Pass $formType explicitly.', $dataClass));
        }

        throw new \LogicException(sprintf('Multiple form types found with data_class "%s": "%s". Pass $formType explicitly.', $dataClass, implode('", "', $matches)));
    }

    /**
     * Returns null when the form type has no inspectable data_class.
     *
     * This keeps automatic inference tolerant of scalar forms, array forms, or
     * form types that depend on extra options.
     *
     * @return class-string|null
     */
    public function resolveDataClass(string $formTypeClass): ?string
    {
        try {
            // Use the registry instead of instantiating the form type directly so options
            // are resolved with parent types and form type extensions.
            $options = $this->formRegistry->getType($formTypeClass)->getOptionsResolver()->resolve([]);
        } catch (FormExceptionInterface|OptionsResolverExceptionInterface) {
            return null;
        }

        $dataClass = $options['data_class'] ?? null;

        if (!is_string($dataClass) || '' === $dataClass || !class_exists($dataClass)) {
            return null;
        }

        return $dataClass;
    }

    /**
     * @return list<class-string<FormTypeInterface<mixed>>>
     */
    private function findFormTypesForDataClass(string $dataClass): array
    {
        return $this->getFormTypesIndexedByDataClass()[$dataClass] ?? [];
    }

    /**
     * @return array<class-string, list<class-string<FormTypeInterface<mixed>>>>
     */
    private function getFormTypesIndexedByDataClass(): array
    {
        if (null !== $this->formTypesByDataClass) {
            return $this->formTypesByDataClass;
        }

        $this->formTypesByDataClass = [];

        foreach ($this->formTypes as $formType) {
            $formTypeClass = $formType::class;
            $dataClass = $this->resolveDataClass($formTypeClass);

            if (null === $dataClass) {
                continue;
            }

            $this->formTypesByDataClass[$dataClass] ??= [];

            if (!in_array($formTypeClass, $this->formTypesByDataClass[$dataClass], true)) {
                $this->formTypesByDataClass[$dataClass][] = $formTypeClass;
            }
        }

        return $this->formTypesByDataClass;
    }
}
