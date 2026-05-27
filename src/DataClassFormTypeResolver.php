<?php

namespace AzYouness\RequestToFormBundle;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Form\Exception\ExceptionInterface as FormExceptionInterface;
use Symfony\Component\Form\FormRegistryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface as OptionsResolverExceptionInterface;

/**
 * Resolves the relation between Symfony form types and their data_class.
 *
 * It can infer the unique form type for a data class and inspect the data_class
 * configured by a form type. The form registry is used so inherited options and
 * form type extensions are included.
 */
final class DataClassFormTypeResolver
{
    /**
     * @var array<class-string, list<class-string>>|null
     */
    private ?array $formTypesByDataClass = null;

    /**
     * @param iterable<FormTypeInterface> $formTypes
     */
    public function __construct(
        // Symfony injects this as a lazy iterable; form type services are created
        // only when the map is built for the first time.
        #[AutowireIterator('form.type')]
        private readonly iterable $formTypes,
        private readonly FormRegistryInterface $formRegistry,
    ) {
    }

    /**
     * @param class-string $dataClass
     *
     * @return class-string
     */
    public function resolveFormType(string $dataClass): string
    {
        $this->assertDataClassExists($dataClass);
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
     * @param class-string $formTypeClass
     *
     * @return class-string|null
     */
    public function resolveDataClass(string $formTypeClass): ?string
    {
        try {
            // Use the registry to inspect Symfony's resolved type, including
            // inherited options and form type extensions.
            $options = $this->formRegistry->getType($formTypeClass)->getOptionsResolver()->resolve([]);
        } catch (FormExceptionInterface|OptionsResolverExceptionInterface) {
            return null;
        }

        $dataClass = $options['data_class'] ?? null;

        return is_string($dataClass) && '' !== $dataClass ? $dataClass : null;
    }

    /**
     * @param class-string $dataClass
     *
     * @return list<class-string>
     */
    private function findFormTypesForDataClass(string $dataClass): array
    {
        return $this->getFormTypesIndexedByDataClass()[$dataClass] ?? [];
    }

    /**
     * @return array<class-string, list<class-string>>
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

    private function assertDataClassExists(string $dataClass): void
    {
        if (!class_exists($dataClass)) {
            throw new \LogicException(sprintf('Data class "%s" does not exist.', $dataClass));
        }
    }
}
