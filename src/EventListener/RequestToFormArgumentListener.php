<?php

namespace AzYouness\RequestToFormBundle\EventListener;

use AzYouness\RequestToFormBundle\ArgumentTypeMatcher;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use AzYouness\RequestToFormBundle\DataClassFormTypeResolver;
use AzYouness\RequestToFormBundle\PendingRequestToFormArgument;
use AzYouness\RequestToFormBundle\RequestToFormMapper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Replaces #[MapRequestToForm] controller arguments with handled form values.
 *
 * This listener runs after Symfony has resolved the full controller argument
 * list. At this point route entities and other arguments are available, so the
 * form can be submitted into existing data or into the pending marker created
 * by RequestToFormValueResolver.
 */
#[AsEventListener(
    event: KernelEvents::CONTROLLER_ARGUMENTS,
    priority: -10, // Make sure to run after Symfony's controller-arguments listeners, which usually run at priority 0 or higher.
)]
final readonly class RequestToFormArgumentListener
{
    public function __construct(
        private RequestToFormMapper $requestToFormMapper,
        private DataClassFormTypeResolver $formTypeResolver,
        private ArgumentTypeMatcher $typeMatcher,
    ) {
    }

    // This event happens after argument resolution, but before the controller method is executed.
    public function __invoke(ControllerArgumentsEvent $event): void
    {
        // Runtime values produced by Symfony's argument resolver chain.
        $arguments = $event->getArguments();

        // ControllerArgumentsEvent does not expose Symfony's internal controller reflector,
        // so we reflect the resolved callable to inspect parameter attributes.
        $parameters = $this->getControllerCallableReflector($event->getController())->getParameters();

        foreach ($parameters as $index => $parameter) {
            $attribute = $parameter->getAttributes(MapRequestToForm::class)[0] ?? null;

            if (null === $attribute) {
                continue;
            }

            // Variadic parameters use "...$arg" and represent multiple values.
            if ($parameter->isVariadic()) {
                throw new \LogicException(sprintf('Mapping variadic argument "$%s" is not supported.', $parameter->getName()));
            }

            // Only map parameters that have a resolved runtime argument.
            if (!array_key_exists($index, $arguments)) {
                continue;
            }

            $attribute = $attribute->newInstance();
            $argumentType = $parameter->getType();
            $argumentName = $parameter->getName();

            if ($argumentName === $attribute->dataArgument) {
                $exceptionMessage = sprintf(
                    'The dataArgument option cannot reference the same argument "$%s". Reference another controller argument or remove dataArgument.',
                    $argumentName
                );
                throw new \LogicException($exceptionMessage);
            }

            // Only a single named type is supported (e.g. Product or FormInterface);
            // union/intersection or missing types are ambiguous for form mapping.
            if (!$argumentType instanceof \ReflectionNamedType) {
                $exceptionMessage = sprintf('Could not resolve the "$%s" controller argument: argument should have a single named type.', $argumentName);
                throw new \LogicException($exceptionMessage);
            }

            // Replace Symfony's resolved argument with the form-handled value.
            $arguments[$index] = $this->mapRequestToForm(
                request: $event->getRequest(),
                attribute: $attribute,
                argumentType: $argumentType,
                argumentName: $argumentName,
                argumentValue: $arguments[$index],
                namedArguments: $this->createNamedArguments($parameters, $arguments),
            );
        }

        $event->setArguments($arguments);
    }

    private function getControllerCallableReflector(callable $controller): \ReflectionFunctionAbstract
    {
        // Array callable, e.g. [$productControllerObject, 'create'] or [ProductController::class, 'create'] or ['ProductController', 'create'].
        if (\is_array($controller) && method_exists(...$controller)) {
            return new \ReflectionMethod(...$controller);
        }

        // Static method string, e.g. ProductController::create.
        if (\is_string($controller) && str_contains($controller, '::')) {
            return new \ReflectionMethod(...explode('::', $controller, 2));
        }

        // Closure or invokable object; convert the callable to a Closure so it can be reflected as a function.
        return new \ReflectionFunction($controller(...));
    }

    /**
     * @param array<string, mixed> $namedArguments
     */
    private function mapRequestToForm(
        Request $request,
        MapRequestToForm $attribute,
        \ReflectionNamedType $argumentType,
        string $argumentName,
        mixed $argumentValue,
        array $namedArguments,
    ): mixed {
        $argumentTypeName = $argumentType->getName();

        // If formType is provided, make sure it matches the argument type before submitting the form.
        if (null !== $attribute->formType) {
            $this->assertFormTypeMatchesArgumentType($attribute->formType, $argumentType, $argumentName);
        }

        $data = $this->resolveInitialData($attribute, $argumentValue, $namedArguments);
        $formType = $attribute->formType ?? $this->resolveFormType($data, $argumentTypeName, $argumentName);

        $form = $this->requestToFormMapper->handle(
            request: $request,
            formType: $formType,
            data: $data,
            formOptions: $attribute->formOptions,
            clearMissing: $attribute->clearMissing,
            acceptFormat: $attribute->acceptFormat,
            validationFailedStatusCode: $attribute->validationFailedStatusCode,
        );

        return $this->resolveArgumentValueFromForm(
            $form,
            $argumentType,
            $argumentName,
        );
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function resolveArgumentValueFromForm(
        FormInterface $form,
        \ReflectionNamedType $argumentType,
        string $argumentName,
    ): mixed {
        $argumentTypeName = $argumentType->getName();

        if (is_a($argumentTypeName, FormInterface::class, true)) {
            return $form;
        }

        $formData = $form->getData();

        if ($this->typeMatcher->matches($formData, $argumentType)) {
            return $formData;
        }

        $exceptionMessage = sprintf(
            'The data resolved by #[%s] for argument "$%s" must be of type "%s", "%s" given.',
            MapRequestToForm::class,
            $argumentName,
            $argumentTypeName,
            get_debug_type($formData)
        );
        throw new \LogicException($exceptionMessage);
    }

    /**
     * @param class-string $formType
     */
    private function assertFormTypeMatchesArgumentType(
        string $formType,
        \ReflectionNamedType $argumentType,
        string $argumentName,
    ): void {
        $argumentTypeName = $argumentType->getName();

        if (is_a($argumentTypeName, FormInterface::class, true)) {
            return;
        }

        $dataClass = $this->formTypeResolver->resolveDataClass($formType);

        if (null === $dataClass || is_a($dataClass, $argumentTypeName, true)) {
            return;
        }

        $exceptionMessage = sprintf(
            'The form type "%s" uses data_class "%s", but argument "$%s" expects "%s".',
            $formType,
            $dataClass,
            $argumentName,
            $argumentTypeName
        );
        throw new \LogicException($exceptionMessage);
    }

    /**
     * Builds a name-indexed view of the current runtime arguments.
     *
     * This lets dataArgument reference another controller argument by name,
     * e.g. #[MapRequestToForm(dataArgument: 'product')].
     *
     * @param list<\ReflectionParameter> $parameters
     * @param array<int, mixed>          $arguments
     *
     * @return array<string, mixed>
     */
    private function createNamedArguments(array $parameters, array $arguments): array
    {
        $namedArguments = [];

        foreach ($parameters as $index => $parameter) {
            if (array_key_exists($index, $arguments)) {
                $namedArguments[$parameter->getName()] = $arguments[$index];
            }
        }

        return $namedArguments;
    }

    /**
     * @param array<string, mixed> $namedArguments
     */
    private function resolveInitialData(MapRequestToForm $attribute, mixed $argumentValue, array $namedArguments): mixed
    {
        // Use another resolved controller argument as the form's initial data.
        if (null !== $attribute->dataArgument) {
            if (!array_key_exists($attribute->dataArgument, $namedArguments)) {
                throw new \LogicException(sprintf('Data argument "$%s" was not found.', $attribute->dataArgument));
            }

            $data = $namedArguments[$attribute->dataArgument];

            if ($data instanceof PendingRequestToFormArgument) {
                $exceptionMessage = sprintf(
                    'Argument "$%s" is not yet resolved. Move "$%s" before this argument or remove #[%s].',
                    $attribute->dataArgument,
                    $attribute->dataArgument,
                    MapRequestToForm::class
                );

                throw new \LogicException($exceptionMessage);
            }

            return $data;
        }

        // Create-style arguments reach this point as our placeholder because no
        // previous resolver, such as Doctrine's EntityValueResolver, produced an object.
        if ($argumentValue instanceof PendingRequestToFormArgument) {
            // Let Symfony Form create the object from the form data_class.
            return null;
        }

        // Edit-style arguments are already resolved objects; submit into them.
        return $argumentValue;
    }

    private function resolveFormType(mixed $data, string $argumentType, string $argumentName): string
    {
        // Existing form data gives the most precise data class, e.g. an entity resolved by Doctrine.
        if (is_object($data)) {
            return $this->formTypeResolver->resolveFormType($data::class);
        }

        // Create-style mapping has no object yet, so infer from the controller argument type.
        if (!is_a($argumentType, FormInterface::class, true) && class_exists($argumentType)) {
            return $this->formTypeResolver->resolveFormType($argumentType);
        }

        // FormInterface targets need an explicit form type unless dataArgument provides an object.
        $exceptionMessage = sprintf(
            'Could not resolve the "$%s" controller argument: set the $formType argument of #[%s].',
            $argumentName,
            MapRequestToForm::class
        );
        throw new \LogicException($exceptionMessage);
    }
}
