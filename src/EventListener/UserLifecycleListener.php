<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\EventListener;

use BetoCampoy\Champs\Onboarding\Manager\MonitoringManager;
use BetoCampoy\Champs\Onboarding\Monitoring\MonitoredUserProvider;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Acompanha a entidade User do projeto (config "monitoring.user_class")
 * e mantém as linhas PENDING em dia:
 *
 *   criou            → syncUser (cria PENDING nos tours elegíveis)
 *   mudou roles      → syncUser (cria/remove PENDING conforme acesso)
 *   mudou identifier → renameUser (progresso acompanha o usuário)
 *   excluiu          → removeUser (apaga tudo dele)
 *
 * Funciona em qualquer lugar onde o usuário for salvo (telas, comandos,
 * importações). As operações rodam depois do flush, via DBAL, e uma falha
 * aqui é só registrada no log: nunca impede salvar o usuário.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class UserLifecycleListener
{
    /** @var array<int, UserInterface> */
    private array $toSync = [];

    /** @var list<array{0: string, 1: string}> */
    private array $toRename = [];

    /** @var list<string> */
    private array $toRemove = [];

    public function __construct(
        private readonly MonitoringManager $monitoring,
        private readonly MonitoredUserProvider $users,
        private readonly LoggerInterface $logger,
        private readonly ?string $identifierProperty,
        private readonly string $rolesProperty,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $user = $args->getObject();

        if ($this->users->isUser($user)) {
            $this->toSync[spl_object_id($user)] = $user;
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $user = $args->getObject();

        if (!$this->users->isUser($user)) {
            return;
        }

        if ($this->identifierProperty !== null && $args->hasChangedField($this->identifierProperty)) {
            $old = (string) $args->getOldValue($this->identifierProperty);
            $new = (string) $args->getNewValue($this->identifierProperty);

            if ($old !== '' && $old !== $new) {
                $this->toRename[] = [$old, $new];
            }
        }

        if ($args->hasChangedField($this->rolesProperty)) {
            $this->toSync[spl_object_id($user)] = $user;
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $user = $args->getObject();

        if ($this->users->isUser($user)) {
            // Captura antes do remove: depois do flush o objeto pode perder o id.
            $this->toRemove[] = $user->getUserIdentifier();
            unset($this->toSync[spl_object_id($user)]);
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
}
