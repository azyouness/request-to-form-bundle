<?php

namespace AzYouness\RequestToFormBundle;

use AzYouness\RequestToFormBundle\Exception\FormValidationFailedException;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Util\FormUtil;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * Submits an HTTP request payload to a Symfony form.
 *
 * This service keeps form handling reusable outside controller attributes.
 * The #[MapRequestToForm] listener also delegates to it, so request decoding,
 * clear-missing behavior, and validation failure handling stay consistent.
 */
final readonly class RequestToFormMapper
{
    private const SUPPORTED_FORMATS = ['json', 'form'];

    public function __construct(
        private RequestStack $requestStack,
        private FormFactoryInterface $formFactory,
        private DataClassFormTypeResolver $formTypeResolver,
    ) {
    }

    /**
     * @param array<string, mixed>      $formOptions
     * @param array<string>|string|null $acceptFormat
     *
     * @return FormInterface<mixed>
     */
    public function handle(
        Request $request,
        string $formType,
        mixed $data = null,
        array $formOptions = [],
        ?bool $clearMissing = null,
        array|string|null $acceptFormat = ['json', 'form'],
        bool $throwOnInvalid = true,
        int $validationFailedStatusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ): FormInterface {
        $acceptedFormats = $this->resolveAcceptedFormats($acceptFormat);
        $format = $this->resolveRequestFormat($request, $acceptedFormats);
        $this->assertValidFormType($formType);

        $form = $this->formFactory->create($formType, $data, $formOptions);

        $this->submitRequest($form, $request, $clearMissing, $format);

        if ($throwOnInvalid && (!$form->isSubmitted() || !$form->isValid())) {
            throw HttpException::fromStatusCode($validationFailedStatusCode, 'Form validation failed.', new FormValidationFailedException($form));
        }

        return $form;
    }

    /**
     * @param array<string, mixed>      $formOptions
     * @param array<string>|string|null $acceptFormat
     *
     * @return FormInterface<mixed>
     */
    public function handleCurrentRequest(
        mixed $data = null,
        ?string $formType = null,
        array $formOptions = [],
        ?bool $clearMissing = null,
        array|string|null $acceptFormat = self::SUPPORTED_FORMATS,
        bool $throwOnInvalid = true,
        int $validationFailedStatusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ): FormInterface {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request instanceof Request) {
            throw new \LogicException('No current request is available. Pass Request explicitly to handle().');
        }

        return $this->handle(
            request: $request,
            formType: $formType ?? $this->resolveFormType($data),
            data: $data,
            formOptions: $formOptions,
            clearMissing: $clearMissing,
            acceptFormat: $acceptFormat,
            throwOnInvalid: $throwOnInvalid,
            validationFailedStatusCode: $validationFailedStatusCode,
        );
    }

    /**
     * @param array<string>|string|null $acceptFormat
     *
     * @return array<string>
     */
    private function resolveAcceptedFormats(array|string|null $acceptFormat): array
    {
        $acceptedFormats = null === $acceptFormat ? self::SUPPORTED_FORMATS : (array) $acceptFormat;
        $unsupportedFormats = array_diff($acceptedFormats, self::SUPPORTED_FORMATS);

        if ([] !== $unsupportedFormats) {
            $exceptionMessage = sprintf(
                'Unsupported accepted format "%s". Supported formats are "%s".',
                implode('", "', $unsupportedFormats),
                implode('", "', self::SUPPORTED_FORMATS)
            );
            throw new \LogicException($exceptionMessage);
        }

        return $acceptedFormats;
    }

    /**
     * @param array<string> $acceptedFormats
     */
    private function resolveRequestFormat(Request $request, array $acceptedFormats): string
    {
        $format = $request->getContentTypeFormat();

        if (!in_array($format, $acceptedFormats, true)) {
            $exceptionMessage = sprintf(
                'Unsupported format, expects "%s", but "%s" given.',
                implode('", "', $acceptedFormats),
                $format ?? 'null'
            );
            throw new UnsupportedMediaTypeHttpException($exceptionMessage);
        }

        return $format;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function submitRequest(
        FormInterface $form,
        Request $request,
        ?bool $clearMissing,
        string $format,
    ): void {
        $clearMissing ??= 'PATCH' !== $request->getMethod();

        if ('json' === $format) {
            $form->submit($this->resolveJsonPayload($request), $clearMissing);

            return;
        }

        if ('form' === $format) {
            $form->submit($this->resolveFormPayload($form, $request), $clearMissing);

            return;
        }

        throw new UnsupportedMediaTypeHttpException('Unsupported format.');
    }

    private function resolveJsonPayload(Request $request): mixed
    {
        try {
            // Do not use Request::toArray(): it rejects valid scalar JSON,
            // while root forms may submit scalar values such as a TextType string.
            return json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Request payload contains invalid "json" data.', $exception);
        }
    }

    /**
     * Mirrors the relevant part of Symfony's HttpFoundationRequestHandler.
     *
     * We cannot call FormInterface::handleRequest() directly because this mapper
     * must control the $clearMissing argument passed to FormInterface::submit().
     *
     * @param FormInterface<mixed> $form
     */
    private function resolveFormPayload(FormInterface $form, Request $request): mixed
    {
        $params = $request->request->all();
        $files = $request->files->all();
        $name = $form->getName();

        // Support payloads grouped under the form name, e.g. post[title].
        $isNamedForm = '' !== $name;
        $requestContainsFormName = array_key_exists($name, $params) || array_key_exists($name, $files);

        if ($isNamedForm && $requestContainsFormName) {
            $default = $form->getConfig()->getCompound() ? [] : null;
            $params = $params[$name] ?? $default;
            $files = $files[$name] ?? $default;
        }

        if (is_array($params) && is_array($files)) {
            return FormUtil::mergeParamsAndFiles($params, $files);
        }

        return $params ?: $files;
    }

    /**
     * @phpstan-assert class-string<FormTypeInterface<mixed>> $formType
     */
    private function assertValidFormType(string $formType): void
    {
        if (is_a($formType, FormTypeInterface::class, true)) {
            return;
        }

        throw new \LogicException(sprintf('Form type "%s" must be a valid Symfony form type class.', $formType));
    }

    /**
     * @return class-string<FormTypeInterface<mixed>>
     */
    private function resolveFormType(mixed $data): string
    {
        if (!is_object($data)) {
            throw new \LogicException('The form type cannot be resolved without an object. Pass $formType explicitly.');
        }

        return $this->formTypeResolver->resolveFormType($data::class);
    }
}
