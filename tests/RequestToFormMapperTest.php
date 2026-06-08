<?php

declare(strict_types=1);

namespace AzYouness\RequestToFormBundle\Tests;

use AzYouness\RequestToFormBundle\DataClassFormTypeResolver;
use AzYouness\RequestToFormBundle\Exception\FormValidationFailedException;
use AzYouness\RequestToFormBundle\RequestToFormMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormRegistryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\ResolvedFormTypeInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

final class RequestToFormMapperTest extends TestCase
{
    #[Test]
    public function handlesJsonObjectPayload(): void
    {
        $form = $this->createMapper()->handle(
            request: $this->createJsonRequest('{"name":"JSON product"}'),
            formType: MapperTestProductType::class,
        );

        $product = $form->getData();

        $this->assertInstanceOf(MapperTestProduct::class, $product);
        $this->assertSame('JSON product', $product->getName());
    }

    #[Test]
    public function handlesJsonScalarPayload(): void
    {
        $form = $this->createMapper()->handle(
            request: $this->createJsonRequest('"John"'),
            formType: TextType::class,
        );

        $this->assertSame('John', $form->getData());
    }

    #[Test]
    public function handlesJsonArrayPayload(): void
    {
        $form = $this->createMapper()->handle(
            request: $this->createJsonRequest('{"keyword":"symfony","status":"published"}'),
            formType: MapperSearchType::class,
        );

        $this->assertSame([
            'keyword' => 'symfony',
            'status' => 'published',
        ], $form->getData());
    }

    #[Test]
    public function handlesFormPayload(): void
    {
        $request = new Request(
            request: ['name' => 'Form product'],
            server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'REQUEST_METHOD' => 'POST']
        );

        $form = $this->createMapper()->handle($request, MapperTestProductType::class);

        $product = $form->getData();

        $this->assertInstanceOf(MapperTestProduct::class, $product);
        $this->assertSame('Form product', $product->getName());
    }

    #[Test]
    public function handlesGetQueryPayload(): void
    {
        $form = $this->createMapper()->handle(
            request: $this->createQueryRequest(['name' => 'Query product']),
            formType: MapperTestProductType::class,
        );

        $product = $form->getData();

        $this->assertInstanceOf(MapperTestProduct::class, $product);
        $this->assertSame('Query product', $product->getName());
    }

    #[Test]
    public function handlesNamedFormPayloadWithUploadedFiles(): void
    {
        $uploadedFile = $this->createUploadedFile();
        $request = new Request(
            request: ['profile' => ['title' => 'Named form']],
            files: ['profile' => ['upload' => $uploadedFile]],
            server: ['CONTENT_TYPE' => 'multipart/form-data', 'REQUEST_METHOD' => 'POST']
        );

        $form = $this->createMapper()->handle($request, MapperUploadType::class);
        $data = $form->getData();

        $this->assertInstanceOf(MapperUploadData::class, $data);
        $this->assertSame('Named form', $data->getTitle());
        $this->assertSame($uploadedFile, $data->getUpload());
    }

    #[Test]
    public function throwsOnInvalidJsonPayload(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Request payload contains invalid "json" data.');

        $this->createMapper()->handle(
            request: $this->createJsonRequest('{invalid-json'),
            formType: MapperTestProductType::class,
        );
    }

    #[Test]
    public function throwsOnUnsupportedRequestFormat(): void
    {
        $request = new Request(
            server: ['CONTENT_TYPE' => 'application/xml', 'REQUEST_METHOD' => 'POST'],
            content: '<name>XML product</name>'
        );

        $this->expectException(UnsupportedMediaTypeHttpException::class);
        $this->expectExceptionMessage('Unsupported format');

        $this->createMapper()->handle($request, MapperTestProductType::class);
    }

    #[Test]
    public function throwsWhenQueryFormatIsNotAccepted(): void
    {
        $this->expectException(UnsupportedMediaTypeHttpException::class);
        $this->expectExceptionMessage('Unsupported format');

        $this->createMapper()->handle(
            request: $this->createQueryRequest(['name' => 'Query product']),
            formType: MapperTestProductType::class,
            acceptFormat: 'json',
        );
    }

    #[Test]
    public function throwsOnUnsupportedAcceptedFormat(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsupported accepted format "xml".');

        $this->createMapper()->handle(
            request: $this->createJsonRequest('{"name":"JSON product"}'),
            formType: MapperTestProductType::class,
            acceptFormat: 'xml',
        );
    }

    #[Test]
    public function throwsOnInvalidFormType(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Form type "hello" must be a valid Symfony form type class.');

        $this->createMapper()->handle(
            request: $this->createJsonRequest('{"name":"JSON product"}'),
            formType: 'hello',
        );
    }

    #[Test]
    public function keepsMissingFieldsOnPatchByDefault(): void
    {
        $product = new MapperTestProduct();
        $product->setName('Existing product');

        $this->createMapper()->handle(
            request: $this->createJsonRequest('{}', 'PATCH'),
            formType: MapperTestProductType::class,
            data: $product,
        );

        $this->assertSame('Existing product', $product->getName());
    }

    #[Test]
    public function keepsMissingFieldsOnQueryByDefault(): void
    {
        $product = new MapperTestProduct();
        $product->setName('Existing product');

        $this->createMapper()->handle(
            request: $this->createQueryRequest([]),
            formType: MapperTestProductType::class,
            data: $product,
        );

        $this->assertSame('Existing product', $product->getName());
    }

    #[Test]
    public function clearsMissingFieldsOnPutByDefault(): void
    {
        $product = new MapperTestProduct();
        $product->setName('Existing product');

        $this->createMapper()->handle(
            request: $this->createJsonRequest('{}', 'PUT'),
            formType: MapperTestProductType::class,
            data: $product,
        );

        $this->assertNull($product->getName());
    }

    #[Test]
    public function usesRequestBodyInsteadOfQueryPayloadForPostRequests(): void
    {
        $request = $this->createJsonRequest('{"name":"JSON product"}');
        $request->query->set('name', 'Query product');

        $form = $this->createMapper()->handle(
            request: $request,
            formType: MapperTestProductType::class,
        );

        $product = $form->getData();

        $this->assertInstanceOf(MapperTestProduct::class, $product);
        $this->assertSame('JSON product', $product->getName());
    }

    #[Test]
    public function handlesCurrentRequestAndResolvesFormTypeFromObject(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->createJsonRequest('{"name":"Current request product"}'));
        $mapper = $this->createMapper($requestStack);
        $product = new MapperTestProduct();

        $form = $mapper->handleCurrentRequest($product);

        $this->assertSame($product, $form->getData());
        $this->assertSame('Current request product', $product->getName());
    }

    #[Test]
    public function throwsWhenFormIsInvalid(): void
    {
        try {
            $this->createMapper()->handle(
                request: $this->createJsonRequest('{"name":""}'),
                formType: MapperValidatedProductType::class,
            );
        } catch (HttpException $exception) {
            $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->getStatusCode());
            $previous = $exception->getPrevious();
            $this->assertInstanceOf(FormValidationFailedException::class, $previous);
            $this->assertFalse($previous->getForm()->isValid());

            return;
        }

        $this->fail('Expected invalid form to throw an HTTP exception.');
    }

    #[Test]
    public function returnsInvalidFormWhenThrowOnInvalidIsFalse(): void
    {
        $form = $this->createMapper()->handle(
            request: $this->createJsonRequest('{"name":""}'),
            formType: MapperValidatedProductType::class,
            throwOnInvalid: false,
        );

        $this->assertTrue($form->isSubmitted());
        $this->assertFalse($form->isValid());
    }

    private function createMapper(?RequestStack $requestStack = null): RequestToFormMapper
    {
        $formFactory = Forms::createFormFactoryBuilder()
            ->addType(new MapperTestProductType())
            ->addType(new MapperValidatedProductType())
            ->addType(new MapperUploadType())
            ->addType(new MapperSearchType())
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();

        return new RequestToFormMapper(
            $requestStack ?? new RequestStack(),
            $formFactory,
            $this->createDataClassFormTypeResolver()
        );
    }

    private function createJsonRequest(string $content, string $method = 'POST'): Request
    {
        return new Request(
            server: ['CONTENT_TYPE' => 'application/json', 'REQUEST_METHOD' => $method],
            content: $content
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function createQueryRequest(array $query): Request
    {
        return new Request(
            query: $query,
            server: ['REQUEST_METHOD' => 'GET']
        );
    }

    private function createDataClassFormTypeResolver(): DataClassFormTypeResolver
    {
        $formRegistry = $this->createStub(FormRegistryInterface::class);
        $formRegistry
            ->method('getType')
            ->willReturnCallback(
                fn (string $formType): ResolvedFormTypeInterface => $this->createResolvedFormType(match ($formType) {
                    MapperTestProductType::class => MapperTestProduct::class,
                    MapperValidatedProductType::class => MapperValidatedProduct::class,
                    MapperUploadType::class => MapperUploadData::class,
                    default => null,
                })
            );

        return new DataClassFormTypeResolver([new MapperTestProductType(), new MapperValidatedProductType(), new MapperUploadType()], $formRegistry);
    }

    private function createUploadedFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'request-to-form-upload-');
        file_put_contents($path, 'upload content');
        register_shutdown_function(static function () use ($path): void {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        return new UploadedFile($path, 'upload.txt', 'text/plain', null, true);
    }

    /**
     * @param class-string|null $dataClass
     */
    private function createResolvedFormType(?string $dataClass): ResolvedFormTypeInterface
    {
        $optionsResolver = new OptionsResolver();

        if (null !== $dataClass) {
            $optionsResolver->setDefault('data_class', $dataClass);
        }

        $resolvedType = $this->createStub(ResolvedFormTypeInterface::class);
        $resolvedType->method('getOptionsResolver')->willReturn($optionsResolver);

        return $resolvedType;
    }
}

final class MapperTestProduct
{
    private ?string $name = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }
}

final class MapperValidatedProduct
{
    private ?string $name = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }
}

final class MapperUploadData
{
    private ?string $title = null;

    private ?UploadedFile $upload = null;

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getUpload(): ?UploadedFile
    {
        return $this->upload;
    }

    public function setUpload(?UploadedFile $upload): void
    {
        $this->upload = $upload;
    }
}

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class MapperSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('keyword', TextType::class)
            ->add('status', TextType::class)
        ;
    }
}

/**
 * @extends AbstractType<MapperTestProduct>
 */
final class MapperTestProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', MapperTestProduct::class);
    }
}

/**
 * @extends AbstractType<MapperValidatedProduct>
 */
final class MapperValidatedProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'constraints' => [new NotBlank()],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', MapperValidatedProduct::class);
    }
}

/**
 * @extends AbstractType<MapperUploadData>
 */
final class MapperUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class)
            ->add('upload', FileType::class, [
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', MapperUploadData::class);
    }

    public function getBlockPrefix(): string
    {
        return 'profile';
    }
}
