<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Exception;

/**
 * Erro de regra do onboarding. O código HTTP sugerido vai em $statusCode
 * para o controller apenas repassar.
 */
final class OnboardingException extends \RuntimeException
{
    private function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }

    public static function tourNotFound(string $slug): self
    {
        return new self(sprintf('Tour "%s" não encontrado ou inativo.', $slug), 404);
    }

    public static function accessDenied(string $slug): self
    {
        return new self(sprintf('Sem permissão para o tour "%s".', $slug), 403);
    }

    public static function cannotSkipMandatory(string $slug): self
    {
        return new self(sprintf('O tour "%s" é obrigatório e não pode ser pulado.', $slug), 422);
    }

    public static function invalidStep(int $step): self
    {
        return new self(sprintf('Passo %d inválido.', $step), 422);
    }

    public static function invalidAction(string $action): self
    {
        return new self(sprintf('Ação "%s" inválida. Use next, complete ou skip.', $action), 422);
    }

    public static function notStarted(string $slug): self
    {
        return new self(sprintf('O tour "%s" não foi iniciado.', $slug), 409);
    }
}
