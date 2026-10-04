<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Entity;

use BetoCampoy\Champs\Onboarding\Enum\TourTrigger;
use BetoCampoy\Champs\Onboarding\Repository\TourRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TourRepository::class)]
#[ORM\Table(name: 'champs_onboarding_tour')]
#[ORM\UniqueConstraint(name: 'uniq_champs_onboarding_tour_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'champs_onboarding.tour.slug_unique')]
class Tour
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identificador estável, usado no botão "?" e nos logs (ex.: "importacao-planilha"). */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'champs_onboarding.tour.slug_format')]
    private string $slug;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Nome da rota Symfony onde o tour começa (ex.: "app_encomenda_index"). */
    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private string $startRoute;

    /** Coluna "trigger_type": TRIGGER é palavra reservada no MySQL. */
    #[ORM\Column(name: 'trigger_type', length: 30, enumType: TourTrigger::class)]
    private TourTrigger $trigger = TourTrigger::FIRST_ACCESS;

    /**
     * Atributo de segurança exigido para ver o tour (null = qualquer usuário logado).
     * Avaliado pelo TourEligibilityCheckerInterface; no padrão, via isGrantedForUser():
     * uma role (ROLE_X, respeita a hierarquia) ou qualquer atributo que um voter
     * do projeto entenda (ex.: "modulo:financeiro"). O Tour vai como subject.
     */
    #[ORM\Column(name: 'required_attribute', length: 150, nullable: true)]
    private ?string $requiredAttribute = null;

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

    /**
     * URL de um registro real da tela inicial (ex.: "/app/remessas/123"), usada só pelo
     * admin para "Testar" e "Apontar na tela" quando a tela exige parâmetro ({id}).
     * O tour em si dispara em qualquer registro daquela tela (pelo nome da rota).
     */
    #[ORM\Column(name: 'sample_url', length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Assert\Regex(pattern: '#^/(?!/)#', message: 'champs_onboarding.tour.sample_url_format')]
    private ?string $sampleUrl = null;

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
    /** Aceita null (select vazio no formulário) como ''; o NotBlank é que barra. */
    public function setStartRoute(?string $startRoute): static { $this->startRoute = (string) $startRoute; return $this; }

    public function getTrigger(): TourTrigger { return $this->trigger; }
    public function setTrigger(TourTrigger $trigger): static { $this->trigger = $trigger; return $this; }

    public function getRequiredAttribute(): ?string { return $this->requiredAttribute; }
    public function setRequiredAttribute(?string $requiredAttribute): static { $this->requiredAttribute = $requiredAttribute; return $this; }

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

    public function getSampleUrl(): ?string { return $this->sampleUrl; }
    public function setSampleUrl(?string $sampleUrl): static { $this->sampleUrl = $sampleUrl !== null && trim($sampleUrl) !== '' ? trim($sampleUrl) : null; return $this; }

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

    /** Remove o passo e renumera os seguintes (posições sempre 0..n-1). */
    public function removeStep(TourStep $step): static
    {
        $this->steps->removeElement($step);
        $this->renumberSteps();

        return $this;
    }

    /** Troca o passo de lugar com o vizinho ($delta = -1 sobe, +1 desce). */
    public function moveStep(TourStep $step, int $delta): static
    {
        $ordered = $this->orderedSteps();
        $from = array_search($step, $ordered, true);
        $to = $from === false ? false : $from + $delta;

        if ($to === false || $to < 0 || $to >= count($ordered)) {
            return $this;
        }

        [$ordered[$from], $ordered[$to]] = [$ordered[$to], $ordered[$from]];
        foreach ($ordered as $position => $item) {
            $item->setPosition($position);
        }

        return $this;
    }

    private function renumberSteps(): void
    {
        foreach ($this->orderedSteps() as $position => $item) {
            $item->setPosition($position);
        }
    }

    /** @return list<TourStep> */
    private function orderedSteps(): array
    {
        $steps = $this->steps->toArray();
        usort($steps, static fn (TourStep $a, TourStep $b) => $a->getPosition() <=> $b->getPosition());

        return array_values($steps);
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

        foreach ($this->orderedSteps() as $step) {
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
