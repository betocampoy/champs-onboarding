<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Bundle de onboarding guiado.
 *
 * Configuração no projeto (config/packages/champs_onboarding.yaml):
 *
 *   champs_onboarding:
 *       admin_role: ROLE_ADMIN
 *       mandatory:
 *           enabled: true
 *           exempt_routes: [app_logout]
 *           exempt_route_prefixes: ['_']
 *           cache_seconds: 300
 *       monitoring:
 *           user_class: App\Entity\User
 *           identifier_property: email
 *           watch_fields: [roles]
 *           batch_size: 500
 *
 * Quem pode ver cada tour: Eligibility\TourEligibilityCheckerInterface.
 * Segmento das estatísticas: Segment\UserSegmentResolverInterface.
 * Os dois têm implementação padrão e podem ser trocados por alias no projeto.
 */
final class ChampsOnboardingBundle extends AbstractBundle
{
    protected string $extensionAlias = 'champs_onboarding';

    /**
     * Raiz do pacote (onde ficam config/ e templates/).
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Roda antes do RoutingControllerPass (prioridade 0).
        $container->addCompilerPass(new DependencyInjection\ExcludeFromRoutingControllersPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('admin_role')
                    ->defaultValue('ROLE_ADMIN')
                    ->info('Role exigida para acessar o CRUD e o dashboard.')
                ->end()
                ->arrayNode('admin')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout')
                            ->defaultValue('base.html.twig')
                            ->info('Template que as telas de administração estendem. Precisa ter o bloco "content".')
                        ->end()
                        ->scalarNode('form_theme')
                            ->defaultValue('@ChampsFrontend/form/champs_theme.html.twig')
                            ->info('Tema dos formulários das telas de administração.')
                        ->end()
                        ->arrayNode('route_path_prefixes')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Só oferece como rota de tour as rotas cujo path começa com um destes prefixos (ex.: ["/app"]). Vazio = todas.')
                        ->end()
                        ->arrayNode('anchor_paths')
                            ->scalarPrototype()->end()
                            ->defaultValue(['%kernel.project_dir%/templates'])
                            ->info('Pastas varridas para sugerir as âncoras data-champs-tour já usadas.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('mandatory')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Redireciona para tours obrigatórios pendentes.')
                        ->end()
                        ->arrayNode('exempt_routes')
                            ->scalarPrototype()->end()
                            ->defaultValue(['app_logout'])
                            ->info('Rotas que nunca são bloqueadas (logout, termos de uso etc.).')
                        ->end()
                        ->arrayNode('exempt_route_prefixes')
                            ->scalarPrototype()->end()
                            ->defaultValue(['_'])
                            ->info('Prefixos de rota liberados. "_" cobre _wdt, _profiler e _error.')
                        ->end()
                        ->integerNode('cache_seconds')
                            ->defaultValue(300)
                            ->min(0)
                            ->info('Por quanto tempo a sessão lembra que não há tour obrigatório pendente.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('monitoring')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('user_class')
                            ->defaultNull()
                            ->info('Entidade User do projeto. Sem ela, tours monitorados ficam desligados.')
                        ->end()
                        ->scalarNode('identifier_property')
                            ->defaultNull()
                            ->info('Propriedade que gera o getUserIdentifier() (ex.: email). Permite acompanhar troca de e-mail.')
                        ->end()
                        ->arrayNode('watch_fields')
                            ->scalarPrototype()->end()
                            ->defaultValue(['roles'])
                            ->info('Campos, associações ou coleções do User que mudam quem ele pode ver ou o segmento dele; mudança em qualquer um ressincroniza o usuário.')
                        ->end()
                        ->integerNode('batch_size')
                            ->defaultValue(500)
                            ->min(1)
                            ->info('Linhas inseridas por lote na carga inicial.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('champs_onboarding.admin_role', $config['admin_role'])
            ->set('champs_onboarding.admin.layout', $config['admin']['layout'])
            ->set('champs_onboarding.admin.form_theme', $config['admin']['form_theme'])
            ->set('champs_onboarding.admin.route_path_prefixes', $config['admin']['route_path_prefixes'])
            ->set('champs_onboarding.admin.anchor_paths', $config['admin']['anchor_paths'])
            ->set('champs_onboarding.mandatory.enabled', $config['mandatory']['enabled'])
            ->set('champs_onboarding.mandatory.exempt_routes', $config['mandatory']['exempt_routes'])
            ->set('champs_onboarding.mandatory.exempt_route_prefixes', $config['mandatory']['exempt_route_prefixes'])
            ->set('champs_onboarding.mandatory.cache_seconds', $config['mandatory']['cache_seconds'])
            ->set('champs_onboarding.monitoring.user_class', $config['monitoring']['user_class'])
            ->set('champs_onboarding.monitoring.identifier_property', $config['monitoring']['identifier_property'])
            ->set('champs_onboarding.monitoring.watch_fields', $config['monitoring']['watch_fields'])
            ->set('champs_onboarding.monitoring.batch_size', $config['monitoring']['batch_size']);

        $container->import('../config/services.php');
    }

    /**
     * Registra o mapeamento Doctrine das entidades do bundle,
     * sem exigir nenhuma configuração no projeto consumidor.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'ChampsOnboarding' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__ . '/Entity',
                        'prefix' => 'BetoCampoy\\Champs\\Onboarding\\Entity',
                        'alias' => 'ChampsOnboarding',
                    ],
                ],
            ],
        ]);
    }
}
