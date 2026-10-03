<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\EventListener;

use BetoCampoy\Champs\Onboarding\Manager\MonitoringManager;
use BetoCampoy\Champs\Onboarding\Monitoring\MonitoredUserProvider;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Acompanha a entidade User do projeto (config "monitoring.user_class")
 * e mantém as linhas PENDING em dia:
 *
 *   criou                       → syncUser (cria PENDING nos tours elegíveis)
 *   mudou um dos watch_fields   → syncUser (cria/remove PENDING, atualiza segmento)
 *   mudou identifier            → renameUser (progresso acompanha o usuário)
 *   excluiu                     → removeUser (apaga tudo dele)
 *
 * watch_fields aceita campos, associações to-one e coleções (ManyToMany/OneToMany):
 * tudo o que muda quem o usuário pode ver ou o segmento dele.
 *
 * Tudo é detectado no onFlush (coleções não aparecem no changeset do preUpdate)
 * e executado no postFlush, via DBAL. Uma falha aqui é só registrada no log:
 * nunca impede salvar o usuário.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class UserLifecycleListener
{
    /** @var array<int, UserInterface> */
    private array $toSync = [];

    /** @var list<array{0: string, 1: string}> */
    private array $toRename = [];

    /** @var list<string> */
    private array $toRemove = [];

    /**
     * @param list<string> $watchFields
     */
    public function __construct(
        private readonly MonitoringManager $monitoring,
        private readonly MonitoredUserProvider $users,
        private readonly LoggerInterface $logger,
        private readonly ?string $identifierProperty,
        private readonly array $watchFields,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->users->isEnabled()) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->users->isUser($entity)) {
                $this->toSync[spl_object_id($entity)] = $entity;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$this->users->isUser($entity)) {
                continue;
            }

            $changes = $uow->getEntityChangeSet($entity);

            if ($this->identifierProperty !== null && isset($changes[$this->identifierProperty])) {
                [$old, $new] = array_map('strval', $changes[$this->identifierProperty]);

                if ($old !== '' && $old !== $new) {
                    $this->toRename[] = [$old, $new];
                }
            }

            if (array_intersect_key($changes, array_flip($this->watchFields)) !== []) {
                $this->toSync[spl_object_id($entity)] = $entity;
            }
        }

        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $this->collectionChanged($collection);
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->users->isUser($entity)) {
                $this->toRemove[] = $entity->getUserIdentifier();
                unset($this->toSync[spl_object_id($entity)]);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->toSync === [] && $this->toRename === [] && $this->toRemove === []) {
            return;
        }

        [$sync, $rename, $remove] = [$this->toSync, $this->toRename, $this->toRemove];
        $this->toSync = $this->toRename = $this->toRemove = [];

        try {
            foreach ($rename as [$old, $new]) {
                $this->monitoring->renameUser($old, $new);
            }
            foreach ($remove as $identifier) {
                $this->monitoring->removeUser($identifier);
            }
            foreach ($sync as $user) {
                $this->monitoring->syncUser($user);
            }
        } catch (\Throwable $e) {
            $this->logger->error('champs_onboarding: falha ao atualizar tours monitorados do usuário', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    private function collectionChanged(PersistentCollection $collection): void
    {
        $owner = $collection->getOwner();

        if ($owner !== null
            && $this->users->isUser($owner)
            && in_array($collection->getMapping()->fieldName, $this->watchFields, true)
        ) {
            $this->toSync[spl_object_id($owner)] = $owner;
        }
    }
}
