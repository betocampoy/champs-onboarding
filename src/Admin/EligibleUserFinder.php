<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Admin;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Monitoring\MonitoredUserProvider;

/**
 * Sugestões para o "Testar como…" / "Apontar como…": usuários que podem ver
 * o tour (mesma regra do TourEligibilityCheckerInterface), filtrados por um
 * trecho do identificador. Precisa de monitoring.user_class.
 */
final class EligibleUserFinder
{
    public function __construct(private readonly MonitoredUserProvider $users)
    {
    }

    public function isAvailable(): bool
    {
        return $this->users->isEnabled();
    }

    /** @return list<array{identifier: string, segment: ?string}> */
    public function find(Tour $tour, string $query = '', int $limit = 20): array
    {
        if (!$this->users->isEnabled()) {
            return [];
        }

        $query = mb_strtolower(trim($query));
        $found = [];

        foreach ($this->users->iterateUsers() as $user) {
            $id = $user->getUserIdentifier();

            if ($query !== '' && !str_contains(mb_strtolower($id), $query)) {
                continue;
            }
            if (!$this->users->isEligible($user, $tour)) {
                continue;
            }

            $found[] = ['identifier' => $id, 'segment' => $this->users->segmentOf($user)];
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }
}
