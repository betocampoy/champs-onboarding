<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Enum;

/**
 * Quando o tour é disparado automaticamente.
 */
enum TourTrigger: string
{
    /** Primeiro acesso do usuário à rota inicial do tour. */
    case FIRST_ACCESS = 'first_access';

    /** Feature nova: dispara para todos que ainda não viram, a partir de publishedAt. */
    case NEW_FEATURE = 'new_feature';

    /** Só abre quando o usuário pede (botão "?"). */
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::FIRST_ACCESS => 'Primeiro acesso',
            self::NEW_FEATURE => 'Nova funcionalidade',
            self::MANUAL => 'Manual',
        };
    }
}
