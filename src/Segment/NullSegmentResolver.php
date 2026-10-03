<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Segment;

use Symfony\Component\Security\Core\User\UserInterface;

/** Padrão: sem segmentação. */
final class NullSegmentResolver implements UserSegmentResolverInterface
{
    public function resolve(UserInterface $user): ?string
    {
        return null;
    }

    public function label(string $segment): string
    {
        return $segment;
    }
}
