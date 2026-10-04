<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Admin;

use Symfony\Component\Finder\Finder;

/**
 * Âncoras data-champs-tour="..." já usadas nos templates do projeto,
 * para sugerir no cadastro do passo (o admin não precisa adivinhar o nome).
 * Valores dinâmicos ({{ ... }}) são ignorados.
 */
final class AnchorCatalog
{
    /** @param list<string> $paths */
    public function __construct(private readonly array $paths)
    {
    }

    /** @return array<string, list<string>> [âncora => templates onde aparece] */
    public function all(): array
    {
        $dirs = array_values(array_filter($this->paths, 'is_dir'));
        if ($dirs === []) {
            return [];
        }

        $anchors = [];
        foreach ((new Finder())->files()->in($dirs)->name('*.twig') as $file) {
            if (!preg_match_all('/data-champs-tour=["\']([A-Za-z0-9_.:-]+)["\']/', $file->getContents(), $m)) {
                continue;
            }
            foreach (array_unique($m[1]) as $anchor) {
                $anchors[$anchor][] = $file->getRelativePathname();
            }
        }

        ksort($anchors);

        return $anchors;
    }
}
