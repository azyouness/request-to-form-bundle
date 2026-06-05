<?php

declare(strict_types=1);

namespace AzYouness\RequestToFormBundle\Tests;

use AzYouness\RequestToFormBundle\ArgumentTypeMatcher;
use AzYouness\RequestToFormBundle\Attribute\MapRequestToForm;
use AzYouness\RequestToFormBundle\DataClassFormTypeResolver;
use AzYouness\RequestToFormBundle\EventListener\RequestToFormArgumentListener;
use AzYouness\RequestToFormBundle\PendingRequestToFormArgument;
use AzYouness\RequestToFormBundle\RequestToFormMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRegistryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\ResolvedFormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RequestToFormArgumentListenerTest extends TestCase
{
    #[Test]
    public function mapsPendingArgumentIntoFormDataUsingArgumentType(): void
    {
        $event = $this->createControllerArgumentsEvent(
            [new ListenerTestRequestFormController(), 'createProduct'],
            [new PendingRequestToFormArgument()]
        );

        $this->createListener()($event);

        $product = $event->getArguments()[0];

        $this->assertInstanceOf(ListenerTestProduct::class, $product);
        $this->assertSame('Mapped product', $product->getName());
    }

    #[Test]
    public function mapsExistingArgumentIntoSameObject(): void
    {
        $product = new ListenerTestProduct();
        $product->setName('Existing product');
        // Simulates an edit action where another resolver, e.g. Doctrine EntityValueResolver,
        // already resolved the entity before this listener runs.
        $event = $this->createControllerArgumentsEvent(
            [new ListenerTestRequestFormController(), 'editProduct'],
            [$product]
        );

        $this->createListener()($event);

        $mappedProduct = $event->getArguments()[0];

        $this->assertSame($product, $mappedProduct);
        $this->assertSame('Mapped product', $product->getName());
    }

    #[Test]
    public function mapsDataArgumentIntoFormInterface(): void
    {
        $product = new ListenerTestProduct();
        $product->setName('Existing product');
        $event = $this->createControllerArgumentsEvent(
            [new ListenerTestRequestFormController(), 'editForm'],
            [$product, new PendingRequestToFormArgument()]
        );

        $this->createListener()($event);

        $arguments = $event->getArguments();

        $this->assertSame($product, $arguments[0]);
        $this->assertInstanceOf(FormInterface::class, $arguments[1]);
        $this->assertSame($product, $arguments[1]->getData());
        $this->assertSame('Mapped product', $product->getName());
    }

    #[Test]
    public function throwsWhenDataArgumentReferencesSameArgument(): void
    {
        $event = $this->createControllerArgumentsEvent(
            [new ListenerTestRequestFormController(), 'selfReference'],
            [new PendingRequestToFormArgument()]
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The dataArgument option cannot reference the same argument "$product".');

        $this->createListener()($event);
    }

    #[Test]
    public function throwsWhenDataArgumentDoesNotExist(): void
    {
        $event = $this->createControllerArgumentsEvent(
            [new ListenerTestRequestFormController(), 'missingDataArgument'],
            [new PendingRequestToFormArgument()]
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Data argument "$product" was not found.');

        $this->createListener()($event);
    }

    #[Test]
    public function throwsWhenDataArgumentIsNotResolvedYet(): void
    {
        $event = $this->createControllerArgumentsEvent(
            [new ListenerTestRequestFormController(), 'unresolvedDataArgument'],
            [
                new PendingRequestToFormArgument(),
                new PendingRequestToFormArgument(),
            ]
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Argument "$product" is not yet resolved.');

        $this->createListener()($event);
    }

    #[Test]
    public function throwsWhenFormTypeDataClassDoesNotMatchArgumentType(): void
    {
        $event = $this->createControllerArgumentsEvent(
            [new ListenerTestRequestFormController(), 'wrongFormType'],
            [new PendingRequestToFormArgument()]
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf(
            'The form type "%s" uses data_class "%s", but argument "$product" expects "%s".',
            ListenerTestOtherProductType::class,
            ListenerTestOtherProduct::class,
            ListenerTestProduct::class
        ));

        $this->createListener()($event);
    }

    #[Test]
    public function throwsWhenResolvedFormDataDoesNotMatchArgumentType(): void
    {
        $event = $this->createControllerArgumentsEvent(
            controller: [new ListenerTestRequestFormController(), 'scalarMismatch'],
            arguments: [new PendingRequestToFormArgument()],
            content: '"not an int"'
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be of type "int", "string" given.');

        $this->createListener()($event);
    }

    #[Test]
    public function mapsNullFormDataToNullableArgument(): void
    {
        $event = $this->createControllerArgumentsEvent(
            controller: [new ListenerTestRequestFormController(), 'nullableProduct'],
            arguments: [new PendingRequestToFormArgument()],
            content: '{}'
        );

        $this->createListener()($event);

        $this->assertNull($event->getArguments()[0]);
    }

    #[Test]
    public function throwsWhenNullFormDataIsMappedToNonNullableArgument(): void
    {
        $event = $this->createControllerArgumentsEvent(
            controller: [new ListenerTestRequestFormController(), 'nonNullableProductWithNullData'],
            arguments: [new PendingRequestToFormArgument()],
            content: '{}'
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be of type "AzYouness\RequestToFormBundle\Tests\ListenerTestNullableProduct", "null" given.');

        $this->createListener()($event);
    }

    #[Test]
    public function mapsNullFormDataToDefaultArgumentValue(): void
    {
        $event = $this->createControllerArgumentsEvent(
            [new ListenerTestRequestFormController(), 'nullFormDataToDefaultArgumentValue'],
            arguments: [new PendingRequestToFormArgument()],
            content: '{}'
        );

        $this->createListener()($event);
        $post = $event->getArguments()[0];

        $this->assertInstanceOf(ListenerTestNullableProduct::class, $post);
        $this->assertSame('Default name', $post->name);
    }

    #[Test]
    public function throwsWhenMappedArgumentIsVariadic(): void
    {
        $event = $this->createControllerArgumentsEvent(
            [new ListenerTestRequestFormController(), 'variadic'],
            [new PendingRequestToFormArgument()]
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Mapping variadic argument "$products" is not supported.');

        $this->createListener()($event);
    }

    private function createListener(): RequestToFormArgumentListener
    {
        $formTypeResolver = $this->createDataClassFormTypeResolver();
        $formFactory = Forms::createFormFactoryBuilder()
            ->addType(new ListenerTestProductType())
            ->addType(new ListenerTestOtherProductType())
            ->addType(new ListenerTestNullableProductType())
            ->getFormFactory();

        return new RequestToFormArgumentListener(
            new RequestToFormMapper(new RequestStack(), $formFactory, $formTypeResolver),
            $formTypeResolver,
            new ArgumentTypeMatcher()
        );
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function createControllerArgumentsEvent(
        callable $controller,
        array $arguments,
        string $content = '{"name":"Mapped product"}',
    ): ControllerArgumentsEvent {
        $request = new Request(
            server: ['CONTENT_TYPE' => 'application/json', 'REQUEST_METHOD' => 'POST'],
            content: $content
        );

        return new ControllerArgumentsEvent(
            kernel: $this->createStub(HttpKernelInterface::class),
            controller: $controller,
            arguments: $arguments,
            request: $request,
            requestType: HttpKernelInterface::MAIN_REQUEST
        );
    }

    private function createDataClassFormTypeResolver(): DataClassFormTypeResolver
    {
        $formTypeDataClasses = [
            ListenerTestProductType::class => ListenerTestProduct::class,
            ListenerTestOtherProductType::class => ListenerTestOtherProduct::class,
            ListenerTestNullableProductType::class => ListenerTestNullableProduct::class,
        ];

        $formRegistry = $this->createStub(FormRegistryInterface::class);
        $formRegistry
            ->method('getType')
            ->willReturnCallback(
                fn (string $formType): ResolvedFormTypeInterface => $this->createResolvedFormType($formTypeDataClasses[$formType] ?? null)
            );

        return new DataClassFormTypeResolver(
            array_map(
                static fn (string $formType) => new $formType(),
                array_keys($formTypeDataClasses)
            ),
            $formRegistry
        );
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

final class ListenerTestRequestFormController
{
    public function createProduct(
        #[MapRequestToForm]
        ListenerTestProduct $product,
    ): void {
    }

    public function editProduct(
        #[MapRequestToForm]
        ListenerTestProduct $product,
    ): void {
    }

    /**
     * @param FormInterface<ListenerTestProduct> $form
     */
    public function editForm(
        ListenerTestProduct $product,
        #[MapRequestToForm(dataArgument: 'product')]
        FormInterface $form,
    ): void {
    }

    public function selfReference(
        #[MapRequestToForm(dataArgument: 'product')]
        ListenerTestProduct $product,
    ): void {
    }

    /**
     * @param FormInterface<ListenerTestProduct> $form
     */
    public function missingDataArgument(
        #[MapRequestToForm(dataArgument: 'product')]
        FormInterface $form,
    ): void {
    }

    /**
     * @param FormInterface<ListenerTestProduct> $form
     */
    public function unresolvedDataArgument(
        #[MapRequestToForm(dataArgument: 'product')]
        FormInterface $form,
        #[MapRequestToForm]
        ListenerTestProduct $product,
    ): void {
    }

    public function wrongFormType(
        #[MapRequestToForm(formType: ListenerTestOtherProductType::class)]
        ListenerTestProduct $product,
    ): void {
    }

    public function scalarMismatch(
        #[MapRequestToForm(formType: TextType::class)]
        int $value,
    ): void {
    }

    public function nullableProduct(
        #[MapRequestToForm]
        ?ListenerTestNullableProduct $product,
    ): void {
    }

    public function nonNullableProductWithNullData(
        #[MapRequestToForm]
        ListenerTestNullableProduct $product,
    ): void {
    }

    public function nullFormDataToDefaultArgumentValue(
        #[MapRequestToForm]
        ListenerTestNullableProduct $product = new ListenerTestNullableProduct(name: 'Default name'),
    ): void {
    }

    public function variadic(#[MapRequestToForm] ListenerTestProduct ...$products): void
    {
    }
}

final class ListenerTestProduct
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

final class ListenerTestOtherProduct
{
}

final class ListenerTestNullableProduct
{
    public function __construct(public ?string $name = null)
    {
    }
}

/**
 * @extends AbstractType<ListenerTestProduct>
 */
final class ListenerTestProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', ListenerTestProduct::class);
    }
}

/**
 * @extends AbstractType<ListenerTestOtherProduct>
 */
final class ListenerTestOtherProductType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', ListenerTestOtherProduct::class);
    }
}

/**
 * @extends AbstractType<ListenerTestNullableProduct>
 */
final class ListenerTestNullableProductType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', ListenerTestNullableProduct::class);
        $resolver->setDefault('empty_data', static fn (): null => null);
    }
}
