<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Entity;

use BetoCampoy\Champs\Onboarding\Enum\TourTrigger;
use BetoCampoy\Champs\Onboarding\Repository\TourRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TourRepository::class)]
#[ORM\Table(name: 'champs_onboarding_tour')]
#[ORM\UniqueConstraint(name: 'uniq_champs_onboarding_tour_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class Tour
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identificador estável, usado no botão "?" e nos logs (ex.: "importacao-planilha"). */
    #[ORM\Column(length: 100)]
    private string $slug;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Nome da rota Symfony onde o tour começa (ex.: "app_encomenda_index"). */
    #[ORM\Column(length: 150)]
    private string $startRoute;

    /** Coluna "trigger_type": TRIGGER é palavra reservada no MySQL. */
    #[ORM\Column(name: 'trigger_type', length: 30, enumType: TourTrigger::class)]
    private TourTrigger $trigger = TourTrigger::FIRST_ACCESS;

    /** Role exigida para ver o tour (null = qualquer usuário logado). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $requiredRole = null;

    #[ORM\Column]
    private bool $active = false;

    /**
     * Obrigatório: o usuário não pode pular e, enquanto não concluir,
     * é redirecionado para o tour ao navegar pelo sistema.
     */
    #[ORM\Column]
    private bool $mandatory = false;

    /**
     * Monitorado: cria uma linha "não iniciado" para cada usuário elegível
     * (carga inicial + cadastro/alteração de usuários), permitindo medir
     * quem nunca abriu o tour.
     */
    #[ORM\Column]
    private bool $monitored = false;

    /** Prioridade quando mais de um tour casa com a mesma rota (maior primeiro). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $priority = 0;

    /** Para NEW_FEATURE: a partir de quando o tour passa a ser exibido. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var Collection<int, TourStep> */
    #[ORM\OneToMany(targetEntity: TourStep::class, mappedBy: 'tour', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $steps;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $slug, string $name, string $startRoute)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->startRoute = $startRoute;
        $this->steps = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getStartRoute(): string { return $this->startRoute; }
    public function setStartRoute(string $startRoute): static { $this->startRoute = $startRoute; return $this; }

    public function getTrigger(): TourTrigger { return $this->trigger; }
    public function setTrigger(TourTrigger $trigger): static { $this->trigger = $trigger; return $this; }

    public function getRequiredRole(): ?string { return $this->requiredRole; }
    public function setRequiredRole(?string $requiredRole): static { $this->requiredRole = $requiredRole; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }

    public function isMandatory(): bool { return $this->mandatory; }
    public function setMandatory(bool $mandatory): static { $this->mandatory = $mandatory; return $this; }

    public function isMonitored(): bool { return $this->monitored; }
    public function setMonitored(bool $monitored): static { $this->monitored = $monitored; return $this; }

    public function getPriority(): int { return $this->priority; }
    public function setPriority(int $priority): static { $this->priority = $priority; return $this; }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, TourStep> */
    public function getSteps(): Collection { return $this->steps; }

    /** Acrescenta o passo no fim do tour (posição = quantidade atual de passos). */
    public function addStep(TourStep $step): static
    {
        if (!$this->steps->contains($step)) {
            $step->setPosition($this->steps->count());
            $this->steps->add($step);
            $step->setTour($this);
        }

        return $this;
    }

    public function removeStep(TourStep $step): static
    {
        $this->steps->removeElement($step);

        return $this;
    }

    public function countSteps(): int
    {
        return $this->steps->count();
    }

    public function getStepAt(int $position): ?TourStep
    {
        foreach ($this->steps as $step) {
            if ($step->getPosition() === $position) {
                return $step;
            }
        }

        return null;
    }

    public function getLastPosition(): int
    {
        $last = 0;
        foreach ($this->steps as $step) {
            $last = max($last, $step->getPosition());
        }

        return $last;
    }

    /**
     * Rota efetiva de cada passo: a do próprio passo ou, se nula,
     * a do passo anterior (o primeiro herda a startRoute).
     *
     * @return array<int, string> [posição => rota]
     */
    public function getEffectiveRoutes(): array
    {
        $routes = [];
        $current = $this->startRoute;

        foreach ($this->steps as $step) {
            $current = $step->getRoute() ?? $current;
            $routes[$step->getPosition()] = $current;
        }

        return $routes;
    }

    /** Todas as rotas por onde o tour passa (usado para liberar navegação no tour obrigatório). */
    public function getAllRoutes(): array
    {
        return array_values(array_unique([$this->startRoute, ...array_values($this->getEffectiveRoutes())]));
    }
}
