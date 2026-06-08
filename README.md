# Request To Form Bundle

Request To Form Bundle submits the current Symfony request to a Symfony Form directly from a controller argument.

Symfony already provides attributes such as `#[MapRequestPayload]` to map request data into typed objects like DTOs. This bundle provides a similar controller experience for applications that use Symfony Forms to submit and validate request data.

With `#[MapRequestToForm]`, the request payload is submitted to a form. If the form is valid, the controller receives the mapped form data or the submitted form itself. If the form is invalid, an exception is thrown before the controller is called.

```php
use App\Entity\Post;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/posts', methods: ['POST'])]
public function create(
    #[MapRequestToForm]
    Post $post,
    EntityManagerInterface $entityManager,
): JsonResponse {
    $entityManager->persist($post);
    $entityManager->flush();

    return $this->json($post);
}
```

Here, `$post` is already the submitted and validated form data. The controller does not need to decode the request, create the form, submit it, check validity, or extract the data manually.

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
    #[MapRequestToForm(PostType::class)]
    Post $post,
): JsonResponse {
    // ...
}
```

Automatic inference works when the form type exposes a `data_class` that can be inspected. If your form type needs runtime options, pass `formType` explicitly and provide `formOptions`, or use the `RequestToFormMapper` service.

If the form can return `null` data, the controller argument should be nullable:

```php
public function create(
    #[MapRequestToForm]
    ?Post $post,
): JsonResponse {
    // ...
}
```

## Existing Data

When another Symfony resolver has already resolved the controller argument, the bundle submits the request into that object.

This is useful when submitting the request into existing data:

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

`MapRequestToForm` can also be combined with other argument attributes, such as Doctrine's `MapEntity`:

```php
use App\Entity\Post;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/posts/{slug}', methods: ['PUT'])]
public function update(
    #[MapRequestToForm]
    #[MapEntity(mapping: ['slug' => 'slug'])]
    Post $post,
): JsonResponse {
    // $post is resolved by MapEntity, then submitted through the form.

    return $this->json($post);
}
```

For `PATCH` and `GET` query requests, missing fields are kept by default. For other methods, missing fields are cleared by default. You can override this behavior with `clearMissing`.

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
    #[MapRequestToForm(PostType::class)]
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
    #[MapRequestToForm(TextType::class)]
    string $title,
): JsonResponse {
    // $title is the submitted string.

    return $this->json(['title' => $title]);
}
```

## Supported Request Formats

By default, `json`, `form`, and `query` request formats are accepted:

```php
#[MapRequestToForm(acceptFormat: ['json', 'form', 'query'])]
```

The supported formats are:

- `json`: submits the decoded JSON request body.
- `form`: submits request parameters and uploaded files, including `multipart/form-data`.
- `query`: submits query parameters from `GET` requests.

You can also limit an action to one format:

```php
#[MapRequestToForm(acceptFormat: 'json')]
```

Example using the `query` format:

```php
use App\Form\PostQueryType;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/posts', methods: ['GET'])]
public function index(
    #[MapRequestToForm(PostQueryType::class)]
    array $query,
): JsonResponse {
    return $this->json($query);
}
```

## Options

```php
#[MapRequestToForm(
    formType: PostType::class,
    dataArgument: 'post',
    formOptions: ['validation_groups' => ['Default', 'publish']],
    clearMissing: false,
    acceptFormat: ['json', 'form', 'query'],
    validationFailedStatusCode: 422,
)]
```

The same options are available on the mapper service where they make sense.

| Option | Available On | Description |
| --- | --- | --- |
| `formType` | attribute, mapper | Symfony form type class. Required when it cannot be inferred. |
| `dataArgument` | attribute | Name of another controller argument to use as the form data. |
| `data` | mapper | Initial form data. Use it to submit into an existing object. |
| `formOptions` | attribute, mapper | Options passed to `FormFactoryInterface::create()`. |
| `clearMissing` | attribute, mapper | Value passed to `FormInterface::submit()`. Defaults to `false` for `PATCH` and `GET` query requests, `true` otherwise. |
| `acceptFormat` | attribute, mapper | Accepted request formats: `json`, `form`, `query`, or a list of them. |
| `throwOnInvalid` | mapper | Set to `false` to receive an invalid form instead of throwing. |
| `validationFailedStatusCode` | attribute, mapper | HTTP status code used when validation fails. Default is `422`. |

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
