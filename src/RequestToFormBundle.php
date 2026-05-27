<?php

namespace AzYouness\RequestToFormBundle;

use AzYouness\RequestToFormBundle\ArgumentResolver\RequestToFormValueResolver;
use AzYouness\RequestToFormBundle\EventListener\RequestToFormArgumentListener;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestToFormBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->defaults()
                ->autowire()
                ->private()

            ->set(ArgumentTypeMatcher::class)
            ->set(DataClassFormTypeResolver::class)
            ->set(RequestToFormMapper::class)

            ->set(RequestToFormValueResolver::class)
                ->tag('controller.argument_value_resolver')

            ->set(RequestToFormArgumentListener::class)
                ->tag('kernel.event_listener', [
                    'event' => KernelEvents::CONTROLLER_ARGUMENTS,
                    'method' => '__invoke',
                    'priority' => -10,
                ])
        ;
    }
}
