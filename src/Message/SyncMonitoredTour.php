<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Message;

/**
 * Pede a (re)sincronização das linhas PENDING de um tour.
 * Roteie para um transport assíncrono no messenger.yaml do projeto.
 */
final class SyncMonitoredTour
{
    public function __construct(public readonly int $tourId)
    {
    }
}
