<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use BetoCampoy\Champs\Onboarding\EventListener\UserLifecycleListener;
use BetoCampoy\Champs\Onboarding\EventSubscriber\MandatoryOnboardingSubscriber;
use BetoCampoy\Champs\Onboarding\Monitoring\MonitoredUserProvider;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->load('BetoCampoy\\Champs\\Onboarding\\', '../src/')
        ->exclude([
            '../src/ChampsOnboardingBundle.php',
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
        ->arg('$rolesProperty', param('champs_onboarding.monitoring.roles_property'));
};
