<?php

namespace AzYouness\RequestToFormBundle;

use AzYouness\RequestToFormBundle\Exception\FormValidationFailedException;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * Submits the current HTTP request payload to a Symfony form.
 *
 * This service keeps form handling reusable outside controllers. The attribute
 * resolver also delegates to it, so request decoding, clear-missing behavior,
 * and validation failure handling stay consistent in both usage styles.
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
     * @param class-string              $formType
     * @param array<string, mixed>      $formOptions
     * @param array<string>|string|null $acceptFormat
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
     * @param class-string|null         $formType
     * @param array<string, mixed>      $formOptions
     * @param array<string>|string|null $acceptFormat
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

    private function submitRequest(
        FormInterface $form,
        Request $request,
        ?bool $clearMissing,
        string $format,
    ): void {
        $clearMissing ??= 'PATCH' !== $request->getMethod();

        if ('json' === $format) {
            try {
                // Do not use Request::toArray(): it rejects valid scalar JSON,
                // while root forms may submit scalar values such as a TextType string.
                $payload = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new BadRequestHttpException('Request payload contains invalid "json" data.', $exception);
            }

            $form->submit($payload, $clearMissing);

            return;
        }

        if ('form' === $format) {
            $form->submit($request->request->all() + $request->files->all(), $clearMissing);

            return;
        }

        throw new UnsupportedMediaTypeHttpException('Unsupported format.');
    }

    /**
     * @param class-string $formType
     */
    private function assertValidFormType(string $formType): void
    {
        if (is_a($formType, FormTypeInterface::class, true)) {
            return;
        }

        throw new \LogicException(sprintf('Form type "%s" must be a valid Symfony form type class.', $formType));
    }

    private function resolveFormType(mixed $data): string
    {
        if (!is_object($data)) {
            throw new \LogicException('The form type cannot be resolved without an object. Pass $formType explicitly.');
        }

        return $this->formTypeResolver->resolveFormType($data::class);
    }
}
