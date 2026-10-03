<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Entity;

use BetoCampoy\Champs\Onboarding\Enum\ProgressStatus;
use BetoCampoy\Champs\Onboarding\Repository\TourProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Progresso de um usuário em um tour.
 *
 * O usuário é guardado pelo userIdentifier (UserInterface::getUserIdentifier()),
 * sem chave estrangeira: o bundle não depende da entidade User do projeto.
 *
 * Em tours monitorados a linha nasce como PENDING (carga inicial ou cadastro
 * do usuário) e passa a IN_PROGRESS quando o usuário abre o tour.
 */
#[ORM\Entity(repositoryClass: TourProgressRepository::class)]
#[ORM\Table(name: 'champs_onboarding_progress')]
#[ORM\UniqueConstraint(name: 'uniq_champs_onboarding_progress_user_tour', columns: ['user_identifier', 'tour_id'])]
#[ORM\Index(name: 'idx_champs_onboarding_progress_status', columns: ['tour_id', 'status'])]
#[ORM\Index(name: 'idx_champs_onboarding_progress_segment', columns: ['tour_id', 'segment'])]
class TourProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'user_identifier', length: 180)]
    private string $userIdentifier;

    #[ORM\ManyToOne(targetEntity: Tour::class)]
    #[ORM\JoinColumn(name: 'tour_id', nullable: false, onDelete: 'CASCADE')]
    private Tour $tour;

    /** Segmento do usuário para as estatísticas (UserSegmentResolverInterface), ex.: "tenant:42". */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $segment = null;

    /** Posição do passo atual (ou do último visto, se encerrado). */
    #[ORM\Column(name: 'current_step', type: Types::SMALLINT)]
    private int $currentStep = 0;

    #[ORM\Column(length: 20, enumType: ProgressStatus::class)]
    private ProgressStatus $status = ProgressStatus::PENDING;

    /** Quantas vezes o usuário abriu este tour (inclui reaberturas pelo botão "?"). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $views = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'started_at', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'last_seen_at', nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @param bool $started false cria a linha como PENDING (tour monitorado)
     */
    public function __construct(string $userIdentifier, Tour $tour, bool $started = true)
    {
        $this->userIdentifier = $userIdentifier;
        $this->tour = $tour;
        $this->createdAt = new \DateTimeImmutable();

        if ($started) {
            $this->begin();
        }
    }

    public static function pending(string $userIdentifier, Tour $tour): self
    {
        return new self($userIdentifier, $tour, false);
    }

    /** PENDING → IN_PROGRESS (primeira vez que o usuário abre o tour). */
    public function begin(): void
    {
        if ($this->status !== ProgressStatus::PENDING) {
            return;
        }

        $now = new \DateTimeImmutable();
        $this->status = ProgressStatus::IN_PROGRESS;
        $this->startedAt = $now;
        $this->lastSeenAt = $now;
        $this->views = 1;
    }

    public function advanceTo(int $step): void
    {
        $this->begin();
        $this->currentStep = max(0, $step);
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function complete(): void
    {
        $this->begin();
        $this->finish(ProgressStatus::COMPLETED);
    }

    public function skip(int $atStep): void
    {
        $this->begin();
        $this->currentStep = max(0, $atStep);
        $this->finish(ProgressStatus::SKIPPED);
    }

    /** Reabre o tour do início (botão "?"). */
    public function restart(): void
    {
        if ($this->status === ProgressStatus::PENDING) {
            $this->begin();
            return;
        }

        $this->currentStep = 0;
        $this->status = ProgressStatus::IN_PROGRESS;
        $this->finishedAt = null;
        $this->views++;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    private function finish(ProgressStatus $status): void
    {
        $this->status = $status;
        $this->finishedAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->finishedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getUserIdentifier(): string { return $this->userIdentifier; }
    public function getTour(): Tour { return $this->tour; }
    public function getSegment(): ?string { return $this->segment; }
    public function setSegment(?string $segment): static { $this->segment = $segment; return $this; }
    public function getCurrentStep(): int { return $this->currentStep; }
    public function getStatus(): ProgressStatus { return $this->status; }
    public function getViews(): int { return $this->views; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getLastSeenAt(): ?\DateTimeImmutable { return $this->lastSeenAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }

    public function isPending(): bool
    {
        return $this->status === ProgressStatus::PENDING;
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }
}
