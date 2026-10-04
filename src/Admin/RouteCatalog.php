<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Admin;

use Symfony\Component\Routing\RouterInterface;

/**
 * Rotas que podem ser página de um tour: GET, sem parâmetro obrigatório
 * (o front precisa conseguir gerar a URL para navegar até o passo).
 * Filtrável por prefixo de path (config admin.route_path_prefixes).
 */
final class RouteCatalog
{
    /** @param list<string> $pathPrefixes */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly array $pathPrefixes = [],
    ) {
    }

    /** @return array<string, string> [rótulo => nome da rota], ordenado pelo path */
    public function choices(): array
    {
        $routes = [];

        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            if (str_starts_with($name, '_') || str_starts_with($name, 'champs_onboarding_')) {
                continue;
            }

            $methods = $route->getMethods();
            if ($methods !== [] && !in_array('GET', $methods, true)) {
                continue;
            }

            $path = $route->getPath();
            if ($this->pathPrefixes !== [] && !$this->startsWithAny($path, $this->pathPrefixes)) {
                continue;
            }

            $required = array_diff($route->compile()->getPathVariables(), array_keys($route->getDefaults()));
            if ($required !== []) {
                continue;
            }

            $routes[$path . ' (' . $name . ')'] = $name;
        }

        ksort($routes);

        return $routes;
    }

    public function exists(string $name): bool
    {
        return $this->router->getRouteCollection()->get($name) !== null;
    }

    /** @param list<string> $prefixes */
    private function startsWithAny(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
