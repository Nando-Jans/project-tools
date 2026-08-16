<?php

declare(strict_types=1);

namespace NandoJans\ProjectTools\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class NandoJansProjectToolsExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if ('prod' !== $container->getParameter('kernel.environment') || !$container->hasExtension('monolog')) {
            return;
        }

        $container->prependExtensionConfig('monolog', [
            'handlers' => [
                'project_tools_prod' => [
                    'type' => 'rotating_file',
                    'path' => '%kernel.logs_dir%/%kernel.environment%.log',
                    'level' => 'info',
                    'max_files' => 14,
                ],
                'project_tools_security' => [
                    'type' => 'rotating_file',
                    'path' => '%kernel.logs_dir%/security.log',
                    'level' => 'info',
                    'max_files' => 14,
                    'channels' => ['security'],
                    'bubble' => false,
                ],
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config'),
        );
        $loader->load('services.yaml');
    }
}
