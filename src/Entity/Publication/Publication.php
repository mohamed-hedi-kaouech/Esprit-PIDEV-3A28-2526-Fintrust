<?php

namespace App\Entity\Publication;

use App\Entity\User\Feedback;
use App\Repository\PublicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PublicationRepository::class)]
#[ORM\Table(name: 'publication')]
class Publication
{
    public const CATEGORY_FINANCE = 'FINANCE';
    public const CATEGORY_ASSURANCE = 'ASSURANCE';
    public const CATEGORY_TECH = 'TECH';
    public const CATEGORY_FINTECH = 'FINTECH';
    public const CATEGORY_EPARGNE = 'EPARGNE';
    public const CATEGORY_INVESTISSEMENT = 'INVESTISSEMENT';
    public const CATEGORY_CREDIT = 'CREDIT';
    public const CATEGORY_CYBERSECURITE = 'CYBERSECURITE';
    public const CATEGORY_CONFORMITE = 'CONFORMITE';
    public const CATEGORY_REGLEMENTATION = 'REGLEMENTATION';

    public const STATUS_BROUILLON = 'BROUILLON';
    public const STATUS_PUBLIE = 'PUBLIE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_publication')]
    private ?int $id = null;

    #[ORM\Column(name: 'titre', type: Types::STRING, length: 255)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    private ?string $titre = null;

    #[ORM\Column(name: 'contenu', type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Le contenu est obligatoire.')]
    private ?string $contenu = null;

    #[ORM\Column(name: 'categorie', type: Types::STRING, length: 100, nullable: true)]
    #[Assert\NotBlank(message: 'La categorie est obligatoire.')]
    #[Assert\Choice(
        choices: [
            self::CATEGORY_FINANCE,
            self::CATEGORY_ASSURANCE,
            self::CATEGORY_TECH,
            self::CATEGORY_FINTECH,
            self::CATEGORY_EPARGNE,
            self::CATEGORY_INVESTISSEMENT,
            self::CATEGORY_CREDIT,
            self::CATEGORY_CYBERSECURITE,
            self::CATEGORY_CONFORMITE,
            self::CATEGORY_REGLEMENTATION,
        ],
        message: 'La categorie choisie est invalide.'
    )]
    private ?string $categorie = null;

    #[ORM\Column(name: 'statut', type: Types::STRING, length: 50)]
    #[Assert\NotBlank(message: 'Le statut est obligatoire.')]
    #[Assert\Choice(
        choices: [self::STATUS_BROUILLON, self::STATUS_PUBLIE],
        message: 'Le statut choisi est invalide.'
    )]
    private ?string $statut = null;

    #[ORM\Column(name: 'est_visible', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $estVisible = true;

    #[ORM\Column(name: 'date_publication', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $datePublication = null;

    #[ORM\OneToMany(targetEntity: Feedback::class, mappedBy: 'publication', cascade: ['remove'], orphanRemoval: true)]
    private Collection $feedbacks;

    public function __construct()
    {
        $this->feedbacks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;
        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): static
    {
        $this->contenu = $contenu;
        return $this;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(?string $categorie): static
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function isEstVisible(): bool
    {
        return $this->estVisible;
    }

    public function setEstVisible(bool $estVisible): static
    {
        $this->estVisible = $estVisible;
        return $this;
    }

    public function getDatePublication(): ?\DateTimeInterface
    {
        return $this->datePublication;
    }

    public function setDatePublication(?\DateTimeInterface $datePublication): static
    {
        $this->datePublication = $datePublication;
        return $this;
    }

    /**
     * @return Collection<int, Feedback>
     */
    public function getFeedbacks(): Collection
    {
        return $this->feedbacks;
    }

    public function addFeedback(Feedback $feedback): static
    {
        if (!$this->feedbacks->contains($feedback)) {
            $this->feedbacks->add($feedback);
            $feedback->setPublication($this);
        }

        return $this;
    }

    public function removeFeedback(Feedback $feedback): static
    {
        if ($this->feedbacks->removeElement($feedback)) {
            // L'entite Feedback est detruite avec la publication, on evite donc
            // de forcer ici un null sur une relation non nullable.
        }

        return $this;
    }
}

