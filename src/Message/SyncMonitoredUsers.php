<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Message;

/**
 * Pede a ressincronização dos usuários em todos os tours monitorados.
 * O projeto despacha quando muda algo que afeta a elegibilidade mas que o
 * bundle não enxerga no User (ex.: o tenant contratou ou cancelou um módulo).
 * $segment limita aos usuários desse segmento (UserSegmentResolverInterface).
 * Roteie para um transport assíncrono no messenger.yaml do projeto.
 */
final class SyncMonitoredUsers
{
    public function __construct(public readonly ?string $segment = null)
    {
    }
}
