<?php

namespace AzYouness\RequestToFormBundle\Attribute;

use Symfony\Component\HttpFoundation\Response;

/**
 * Controller parameter attribute that submits the current request to a Symfony form.
 *
 * This is intentionally not a Symfony ValueResolver attribute. Keeping it as
 * a marker lets native resolvers such as Doctrine's EntityValueResolver run
 * first, then the resolved object can be submitted through the form.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class MapRequestToForm
{
    /**
     * @param class-string|null         $formType     Symfony form type class. If omitted, it is resolved from the data class when possible.
     * @param string|null               $dataArgument existing controller argument name to use as form data
     * @param array<string, mixed>      $formOptions  options passed to FormFactoryInterface::create()
     * @param array<string>|string|null $acceptFormat Accepted request content formats, e.g. "json" or "form".
     */
    public function __construct(
        public readonly ?string $formType = null,
        public readonly ?string $dataArgument = null,
        public readonly array $formOptions = [],
        public readonly ?bool $clearMissing = null,
        public readonly array|string|null $acceptFormat = ['json', 'form'],
        public readonly int $validationFailedStatusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
    }
}
