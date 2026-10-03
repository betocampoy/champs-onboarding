<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Entity;

use BetoCampoy\Champs\Onboarding\Enum\StepPosition;
use BetoCampoy\Champs\Onboarding\Repository\TourStepRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TourStepRepository::class)]
#[ORM\Table(name: 'champs_onboarding_step')]
#[ORM\Index(name: 'idx_champs_onboarding_step_tour_position', columns: ['tour_id', 'position'])]
class TourStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tour::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tour $tour;

    /** Ordem do passo dentro do tour (0, 1, 2...). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 0;

    /**
     * Valor do atributo data-champs-tour no HTML (ex.: "btn-importar").
     * Null = passo sem âncora (popover centralizado na tela, ex.: boas-vindas).
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $anchor = null;

    #[ORM\Column(length: 150)]
    private string $title;

    /** Texto do popover (aceita HTML simples). */
    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column(length: 10, enumType: StepPosition::class)]
    private StepPosition $placement = StepPosition::AUTO;

    /**
     * Rota onde o passo acontece. Null = mesma rota do passo anterior.
     * Permite tours que atravessam várias páginas.
     */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $route = null;

    /** Link opcional de ajuda (vídeo, artigo da base de conhecimento etc.). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $helpUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $helpLabel = null;

    /** Se true, o usuário precisa clicar no elemento destacado para avançar. */
    #[ORM\Column]
    private bool $advanceOnClick = false;

    public function __construct(string $title, string $content)
    {
        $this->title = $title;
        $this->content = $content;
    }

    public function getId(): ?int { return $this->id; }

    public function getTour(): Tour { return $this->tour; }
    public function setTour(Tour $tour): static { $this->tour = $tour; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }

    public function getAnchor(): ?string { return $this->anchor; }
    public function setAnchor(?string $anchor): static { $this->anchor = $anchor; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getContent(): string { return $this->content; }
    public function setContent(string $content): static { $this->content = $content; return $this; }

    public function getPlacement(): StepPosition { return $this->placement; }
    public function setPlacement(StepPosition $placement): static { $this->placement = $placement; return $this; }

    public function getRoute(): ?string { return $this->route; }
    public function setRoute(?string $route): static { $this->route = $route; return $this; }

    public function getHelpUrl(): ?string { return $this->helpUrl; }
    public function setHelpUrl(?string $helpUrl): static { $this->helpUrl = $helpUrl; return $this; }

    public function getHelpLabel(): ?string { return $this->helpLabel; }
    public function setHelpLabel(?string $helpLabel): static { $this->helpLabel = $helpLabel; return $this; }

    public function isAdvanceOnClick(): bool { return $this->advanceOnClick; }
    public function setAdvanceOnClick(bool $advanceOnClick): static { $this->advanceOnClick = $advanceOnClick; return $this; }

    /** Formato consumido pelo módulo Onboarding.js do champs-core-js. */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'anchor' => $this->anchor,
            'title' => $this->title,
            'content' => $this->content,
            'placement' => $this->placement->value,
            'route' => $this->route,
            'helpUrl' => $this->helpUrl,
            'helpLabel' => $this->helpLabel,
            'advanceOnClick' => $this->advanceOnClick,
        ];
    }
}
