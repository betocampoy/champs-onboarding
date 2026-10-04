<?php

declare(strict_types=1);

namespace BetoCampoy\Champs\Onboarding\Entity;

use BetoCampoy\Champs\Onboarding\Enum\StepPosition;
use BetoCampoy\Champs\Onboarding\Repository\TourStepRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

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
     * Elemento destacado no passo. Duas formas (mesma regra do Onboarding.js):
     * - nome simples (ex.: "btn-importar") = valor do atributo data-champs-tour;
     * - seletor CSS (começa com # . [ ou tem espaço, >, =, :, (...) ex.: "#btn-exportar",
     *   'a[href="/app/clientes/grupos"]', '[name="cliente[nome]"]': dispensa mexer no template.
     * Null = passo sem âncora (card centralizado, ex.: boas-vindas).
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $anchor = null;

    /** Nome simples de data-champs-tour (não é seletor CSS). */
    public const ANCHOR_NAME_PATTERN = '/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/';

    public static function isSelectorAnchor(?string $anchor): bool
    {
        return $anchor !== null && $anchor !== '' && !preg_match(self::ANCHOR_NAME_PATTERN, $anchor);
    }

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $title;

    /** Texto do passo. O front exibe como texto (escapado); quebras de linha viram <br>. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
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
    #[Assert\Length(max: 500)]
    #[Assert\Url(requireTld: false)]
    private ?string $helpUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
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
