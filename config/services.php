<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use BetoCampoy\Champs\Onboarding\Admin\AnchorCatalog;
use BetoCampoy\Champs\Onboarding\Admin\RouteCatalog;
use BetoCampoy\Champs\Onboarding\Eligibility\AuthorizationEligibilityChecker;
use BetoCampoy\Champs\Onboarding\Eligibility\TourEligibilityCheckerInterface;
use BetoCampoy\Champs\Onboarding\EventListener\UserLifecycleListener;
use BetoCampoy\Champs\Onboarding\EventSubscriber\MandatoryOnboardingSubscriber;
use BetoCampoy\Champs\Onboarding\Monitoring\MonitoredUserProvider;
use BetoCampoy\Champs\Onboarding\Segment\NullSegmentResolver;
use BetoCampoy\Champs\Onboarding\Segment\UserSegmentResolverInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->load('BetoCampoy\\Champs\\Onboarding\\', '../src/')
        ->exclude([
            '../src/ChampsOnboardingBundle.php',
            '../src/DependencyInjection/',
            '../src/Entity/',
            '../src/Enum/',
            '../src/Exception/',
            '../src/Message/',
        ]);

    $services->load('BetoCampoy\\Champs\\Onboarding\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');

    $services->set(MandatoryOnboardingSubscriber::class)
        ->arg('$enabled', param('champs_onboarding.mandatory.enabled'))
        ->arg('$exemptRoutes', param('champs_onboarding.mandatory.exempt_routes'))
        ->arg('$exemptRoutePrefixes', param('champs_onboarding.mandatory.exempt_route_prefixes'))
        ->arg('$cacheSeconds', param('champs_onboarding.mandatory.cache_seconds'));

    $services->set(MonitoredUserProvider::class)
        ->arg('$userClass', param('champs_onboarding.monitoring.user_class'))
        ->arg('$batchSize', param('champs_onboarding.monitoring.batch_size'));

    $services->set(UserLifecycleListener::class)
        ->arg('$identifierProperty', param('champs_onboarding.monitoring.identifier_property'))
        ->arg('$watchFields', param('champs_onboarding.monitoring.watch_fields'));

    $services->set(RouteCatalog::class)
        ->arg('$pathPrefixes', param('champs_onboarding.admin.route_path_prefixes'));

    $services->set(AnchorCatalog::class)
        ->arg('$paths', param('champs_onboarding.admin.anchor_paths'));

    // Pontos de extensão: o projeto troca a implementação apontando o alias
    // para a própria classe no config/services.yaml dele.
    $services->alias(TourEligibilityCheckerInterface::class, AuthorizationEligibilityChecker::class);
    $services->alias(UserSegmentResolverInterface::class, NullSegmentResolver::class);
};
