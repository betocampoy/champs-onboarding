<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Enum;

enum ProgressStatus: string
{
    /** Linha pré-criada para tour monitorado; o usuário ainda não abriu. */
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case SKIPPED = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Não iniciado',
            self::IN_PROGRESS => 'Em andamento',
            self::COMPLETED => 'Concluído',
            self::SKIPPED => 'Pulado',
        };
    }

    /** Status que encerram o tour (não dispara de novo automaticamente). */
    public function isFinished(): bool
    {
        return $this === self::COMPLETED || $this === self::SKIPPED;
    }

    public function isStarted(): bool
    {
        return $this !== self::PENDING;
    }
}
