<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Enum;

/**
 * Posição do popover em relação ao elemento destacado
 * (mesmos valores aceitos pelo Popover do Bootstrap 5).
 */
enum StepPosition: string
{
    case TOP = 'top';
    case BOTTOM = 'bottom';
    case LEFT = 'left';
    case RIGHT = 'right';
    case AUTO = 'auto';
}
