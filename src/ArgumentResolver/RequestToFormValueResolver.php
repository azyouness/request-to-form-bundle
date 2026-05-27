<?php

namespace AzYouness\RequestToFormBundle\ArgumentResolver;

use AzYouness\RequestToFormBundle\ArgumentTypeMatcher;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use AzYouness\RequestToFormBundle\PendingRequestToFormArgument;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Creates a temporary argument value for #[MapRequestToForm].
 *
 * This resolver does not submit the form. It only returns a pending marker so
 * Symfony can finish resolving the controller arguments. The listener later
 * replaces the marker with the handled form or form data.
 */
final readonly class RequestToFormValueResolver implements ValueResolverInterface
{
    public function __construct(
        private ArgumentTypeMatcher $typeMatcher,
    ) {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $attribute = $argument->getAttributesOfType(MapRequestToForm::class, ArgumentMetadata::IS_INSTANCEOF)[0] ?? null;

        if (!$attribute) {
            return [];
        }

        if ($argument->isVariadic()) {
            throw new \LogicException(sprintf('Mapping variadic argument "$%s" is not supported.', $argument->getName()));
        }

        $this->assertArgumentIsResolvable($argument, $attribute);

        // Symfony stops at the first resolver that returns a value for the argument,
        // so this marker is only created when no earlier resolver handled it.
        return [new PendingRequestToFormArgument()];
    }

    private function assertArgumentIsResolvable(ArgumentMetadata $argument, MapRequestToForm $attribute): void
    {
        $argumentName = $argument->getName();
        $argumentType = $argument->getType();

        // Only resolve the argument when the provided type/options are enough
        // to create a valid pending argument.

        // Without a type, we do not know what the form result should map to.
        if (null === $argumentType) {
            throw new \LogicException(sprintf('Could not resolve the "$%s" controller argument: argument should have a type.', $argumentName));
        }

        // Explicit formType or dataArgument gives the listener enough information to continue.
        if (null !== $attribute->formType || null !== $attribute->dataArgument) {
            return;
        }

        // FormInterface needs explicit instructions because no form type can be inferred from it.
        if (is_a($argumentType, FormInterface::class, true)) {
            $exceptionMessage = sprintf(
                'Could not resolve the "$%s" controller argument: FormInterface targets require $formType or $dataArgument in #[%s].',
                $argumentName,
                MapRequestToForm::class
            );
            throw new \LogicException($exceptionMessage);
        }

        // Built-in targets like string/int/array also need an explicit form type.
        if ($this->typeMatcher->isBuiltInType($argumentType)) {
            $exceptionMessage = sprintf(
                'Could not resolve the "$%s" controller argument: built-in targets require $formType in #[%s].',
                $argumentName,
                MapRequestToForm::class
            );
            throw new \LogicException($exceptionMessage);
        }
    }
}
