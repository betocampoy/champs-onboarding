<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\EventListener;

use BetoCampoy\Champs\Onboarding\Entity\Tour;
use BetoCampoy\Champs\Onboarding\Manager\MonitoringManager;
use BetoCampoy\Champs\Onboarding\Message\SyncMonitoredTour;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Quando um tour vira monitorado (ou muda algo que afeta quem é elegível),
 * agenda a sincronização. O dispatch só acontece depois do flush, para o
 * handler já encontrar o tour gravado.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
final class TourMonitoringListener
{
    /** Campos do Tour que mudam quem deve ter linha PENDING. */
    private const WATCHED = ['monitored', 'active', 'requiredRole'];

    /** @var array<int, true> */
    private array $queue = [];

    public function __construct(
        private readonly MonitoringManager $monitoring,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $tour = $args->getObject();

        if ($tour instanceof Tour && $tour->isMonitored()) {
            $this->queue[(int) $tour->getId()] = true;
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $tour = $args->getObject();

        if (!$tour instanceof Tour) {
            return;
        }

        foreach (self::WATCHED as $field) {
            if ($args->hasChangedField($field)) {
                $this->queue[(int) $tour->getId()] = true;
                return;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->queue === [] || !$this->monitoring->isEnabled()) {
            $this->queue = [];
            return;
        }

        $ids = array_keys($this->queue);
        $this->queue = [];

        foreach ($ids as $id) {
            $this->bus->dispatch(new SyncMonitoredTour($id));
        }
    }
}
