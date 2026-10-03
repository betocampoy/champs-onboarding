<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * No projeto (config/routes/champs_onboarding.yaml):
 *
 *   champs_onboarding:
 *       resource: '@ChampsOnboardingBundle/config/routes.php'
 *       prefix: /onboarding
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('../src/Controller/', 'attribute');
};
