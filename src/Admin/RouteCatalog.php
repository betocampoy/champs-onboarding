<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Admin;

use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Rotas que podem ser página de um tour: GET, filtráveis por prefixo de path
 * (config admin.route_path_prefixes).
 *
 * Rotas com parâmetro obrigatório (ex.: detalhe de um registro, /remessas/{id})
 * também entram: o tour dispara pelo NOME da rota, então aparece quando o usuário
 * abre qualquer registro daquele tipo. O que não dá é o front navegar sozinho até
 * elas (não há URL sem saber qual registro) — para testar/apontar, o tour usa a
 * "URL de exemplo" (Tour::sampleUrl).
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

            $routes[$path . ' (' . $name . ')'] = $name;
        }

        ksort($routes);

        return $routes;
    }

    /** A rota exige parâmetro (ex.: {id}) e por isso não gera URL sozinha. */
    public function needsParameters(string $name): bool
    {
        $route = $this->router->getRouteCollection()->get($name);
        if ($route === null) {
            return false;
        }

        return array_diff($route->compile()->getPathVariables(), array_keys($route->getDefaults())) !== [];
    }

    /** @return array<string, string> [nome da rota => URL] das rotas oferecidas que geram URL sozinhas */
    public function urls(): array
    {
        $urls = [];
        foreach ($this->choices() as $name) {
            if ($this->needsParameters($name)) {
                continue;
            }
            try {
                $urls[$name] = $this->router->generate($name);
            } catch (\Throwable) {
                // rota que não gera URL fica de fora do "Apontar na tela"
            }
        }

        return $urls;
    }

    /** Nome da rota que atende o path da URL (query string ignorada), ou null. */
    public function routeOfUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $context = $this->router->getContext();
        $method = $context->getMethod();
        $context->setMethod('GET');
        try {
            return $this->router->match($path)['_route'] ?? null;
        } catch (RoutingException) {
            return null;
        } finally {
            $context->setMethod($method);
        }
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
