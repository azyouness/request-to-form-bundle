# Request To Form Bundle

Request To Form Bundle submits the current Symfony request to a Symfony Form directly from a controller argument.

Symfony already provides attributes such as `#[MapRequestPayload]` to map request data into typed objects like DTOs. This bundle provides a similar controller experience for applications that use Symfony Forms as the request contract.

With `#[MapRequestToForm]`, the request payload is submitted to a form. If the form is valid, the controller receives the mapped form data or the submitted form itself.

```php
use App\Entity\Post;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/posts', methods: ['POST'])]
public function create(
    #[MapRequestToForm]
    Post $post,
): JsonResponse {
    $this->blogApplicationService->create($post);

    return $this->json($this->transformer->toDetail($post));
}
```

Here, `$post` is already the submitted and validated form data. The controller does not need to decode the request, create the form, submit it, check validity, or extract the data manually. If the form is invalid, an exception is thrown before the controller is called.

## Requirements

- PHP `>=8.2`
- Symfony `^7.4 || ^8.0`

## Installation

```bash
composer require azyouness/request-to-form-bundle
```

Register the bundle manually if Symfony Flex did not do it automatically:

```php
// config/bundles.php
return [
    // ...
    AzYouness\RequestToFormBundle\RequestToFormBundle::class => ['all' => true],
];
```

## Basic Usage

Add `#[MapRequestToForm]` to a controller argument.

```php
use App\Entity\Post;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/posts', methods: ['POST'])]
public function create(
    #[MapRequestToForm]
    Post $post,
): JsonResponse {
    // $post is the submitted and validated form data.

    return $this->json($post);
}
```

The form type can be inferred when exactly one registered form type uses the controller argument class as its `data_class`.

If the form type cannot be inferred or multiple form types use the same `data_class`, pass it explicitly:

```php
use App\Form\PostType;

public function create(
    #[MapRequestToForm(formType: PostType::class)]
    Post $post,
): JsonResponse {
    // ...
}
```

## Existing Data

When another Symfony resolver has already resolved the controller argument, the bundle submits the request into that object.

This is useful when updating existing data:

```php
use App\Entity\Post;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/posts/{id<\d+>}', methods: ['PUT'])]
public function update(
    #[MapRequestToForm]
    Post $post,
): JsonResponse {
    // $post is first resolved from the {id} route parameter by Symfony's EntityValueResolver,
    // then submitted to the form with the current request data.

    return $this->json($post);
}
```

For `PATCH` requests, missing fields are kept by default. For other methods, missing fields are cleared by default. You can override this behavior with `clearMissing`.

```php
public function update(
    #[MapRequestToForm(clearMissing: false)]
    Post $post,
): JsonResponse {
    // ...
}
```

## Receiving The Form

If the controller argument type is `FormInterface`, the controller receives the submitted form.

```php
use App\Form\PostType;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

public function create(
    #[MapRequestToForm(formType: PostType::class)]
    FormInterface $form,
): JsonResponse {
    $post = $form->getData();

    return $this->json($post);
}
```

This is useful when the controller needs access to the form object, not only its data.

You can also use another controller argument as the form data:

```php
use App\Entity\Post;
use App\Form\PostType;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

public function update(
    Post $post,
    #[MapRequestToForm(formType: PostType::class, dataArgument: 'post')]
    FormInterface $form,
): JsonResponse {
    // The form is submitted with $post as its initial data,
    // so $post is updated with the current request data.

    return $this->json($form->getData());
}
```

## Root Scalar Forms

Root scalar form types are supported when the controller argument type matches the submitted form data.

```php
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\JsonResponse;

public function rename(
    #[MapRequestToForm(formType: TextType::class)]
    string $title,
): JsonResponse {
    // $title is the submitted string.

    return $this->json(['title' => $title]);
}
```

## Supported Request Formats

By default, both `json` and `form` request formats are accepted:

```php
#[MapRequestToForm(acceptFormat: ['json', 'form'])]
```

Limit an action to JSON only:

```php
#[MapRequestToForm(acceptFormat: 'json')]
```

## Options Reference

```php
#[MapRequestToForm(
    formType: PostType::class,
    dataArgument: 'post',
    formOptions: ['validation_groups' => ['Default', 'publish']],
    clearMissing: false,
    acceptFormat: ['json', 'form'],
    validationFailedStatusCode: 422,
)]
```

The same options are available on the mapper service where they make sense.

| Option                       | Attribute                           | `handle()` | `handleCurrentRequest()`            | Description                                                                                            |
| ---------------------------- | ----------------------------------- | ---------- | ----------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `request`                    | current controller request          | required   | current request from `RequestStack` | Request submitted to the form.                                                                         |
| `formType`                   | optional                            | required   | optional                            | Symfony form type class. Optional when it can be inferred from the data class.                         |
| `data`                       | resolved argument or `dataArgument` | optional   | optional                            | Initial form data. Use it to submit into an existing object.                                           |
| `dataArgument`               | supported                           | no         | no                                  | Name of another controller argument to use as the form data.                                           |
| `formOptions`                | supported                           | supported  | supported                           | Options passed to `FormFactoryInterface::create()`.                                                    |
| `clearMissing`               | supported                           | supported  | supported                           | Value passed to `FormInterface::submit()`. If omitted, `PATCH` uses `false`; other methods use `true`. |
| `acceptFormat`               | supported                           | supported  | supported                           | Accepted request formats. Supported values are `json` and `form`.                                      |
| `throwOnInvalid`             | always enabled                      | supported  | supported                           | Set to `false` with the mapper to receive an invalid form instead of throwing.                         |
| `validationFailedStatusCode` | supported                           | supported  | supported                           | HTTP status code used when validation fails. Default is `422`.                                         |

## Mapper Service

Use `RequestToFormMapper` directly when you need more control than the attribute gives you.

A common use case is preparing the form data before submitting the current request:

```php
use App\Entity\Post;
use AzYouness\RequestToFormBundle\RequestToFormMapper;
use Symfony\Component\HttpFoundation\JsonResponse;

public function create(RequestToFormMapper $mapper): JsonResponse
{
    $post = new Post();
    $post->setAuthor($this->getUser());

    $mapper->handleCurrentRequest($post);

    // $post is now the submitted and validated form data.

    return $this->json($post);
}
```

You can also pass the request explicitly:

```php
use App\Form\PostType;
use AzYouness\RequestToFormBundle\RequestToFormMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

public function create(Request $request, RequestToFormMapper $mapper): JsonResponse
{
    $form = $mapper->handle(
        request: $request,
        formType: PostType::class,
    );

    return $this->json($form->getData());
}
```

The mapper throws when the form is invalid by default, like the attribute.

Disable this behavior when you want to handle the invalid form yourself:

```php
$form = $mapper->handle(
    request: $request,
    formType: PostType::class,
    data: $post,
    throwOnInvalid: false,
);

if (!$form->isValid()) {
    // ...
}
```

## Validation Failures

When validation fails, the bundle throws an HTTP exception with status code `422` by default.

The previous exception is `FormValidationFailedException`, which gives access to the invalid form.

```php
use AzYouness\RequestToFormBundle\Exception\FormValidationFailedException;

$previous = $exception->getPrevious();

if ($previous instanceof FormValidationFailedException) {
    $form = $previous->getForm();
}
```
