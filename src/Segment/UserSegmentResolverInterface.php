<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Segment;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Segmento do usuário para as estatísticas (ex.: o tenant, a unidade, o plano).
 * Os tours continuam globais; o segmento só é gravado na linha de progresso
 * para poder filtrar e agrupar o dashboard.
 *
 * Opcional: sem implementação no projeto, todas as linhas ficam sem segmento.
 *
 *   # config/services.yaml
 *   BetoCampoy\Champs\Onboarding\Segment\UserSegmentResolverInterface:
 *       alias: App\Onboarding\TenantSegmentResolver
 *
 * O segmento é atualizado quando o usuário usa o tour, quando muda um dos
 * campos de monitoring.watch_fields e em cada champs:onboarding:sync.
 */
interface UserSegmentResolverInterface
{
    /** Chave curta e estável (até 100 caracteres), ou null. Ex.: "tenant:42". */
    public function resolve(UserInterface $user): ?string;

    /** Nome legível do segmento para o dashboard. Ex.: "Transportadora Exemplo". */
    public function label(string $segment): string;
}
