<?php

namespace App\Entity\User\Client;

use App\Entity\User\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'kyc')]
#[UniqueEntity(fields: ['cin'], message: 'Un dossier KYC existe deja avec ce CIN.')]
class Kyc
{
    public const STATUT_EN_ATTENTE = 'EN_ATTENTE';
    public const STATUT_APPROUVE = 'APPROUVE';
    public const STATUT_REFUSE = 'REFUSE';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(type: 'string', length: 8, unique: true)]
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Le CIN est obligatoire.'),
        new Assert\Regex(
            pattern: '/^\d{8}$/',
            message: 'Le CIN doit contenir exactement 8 chiffres.'
        ),
    ])]
    private string $cin;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'L adresse complete est obligatoire.')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'L adresse ne peut pas depasser {{ limit }} caracteres.'
    )]
    private string $adresse;

    #[ORM\Column(name: 'date_naissance', type: 'date')]
    #[Assert\NotNull(message: 'La date de naissance est obligatoire.')]
    #[Assert\LessThanOrEqual(
        value: '-18 years',
        message: 'Vous devez avoir au moins 18 ans.'
    )]
    private ?\DateTimeInterface $dateNaissance = null;

    #[ORM\Column(name: 'signature_path', type: 'string', length: 255, nullable: true)]
    private ?string $signaturePath = null;

    #[ORM\Column(name: 'signature_uploaded_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $signatureUploadedAt = null;

    #[ORM\Column(type: 'string')]
    private string $statut = self::STATUT_EN_ATTENTE;

    #[ORM\Column(name: 'commentaire_admin', type: 'text', nullable: true)]
    private ?string $commentaireAdmin = null;

    #[ORM\Column(name: 'date_submission', type: 'datetime')]
    private \DateTimeInterface $dateSubmission;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private User $user;

    #[ORM\OneToMany(targetEntity: KycFile::class, mappedBy: 'kyc')]
    private Collection $files;

    public function __construct()
    {
        $this->files = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCin(): string
    {
        return $this->cin;
    }

    public function setCin(string $cin): static
    {
        $this->cin = preg_replace('/\s+/', '', trim($cin)) ?? trim($cin);

        return $this;
    }

    public function getAdresse(): string
    {
        return $this->adresse;
    }

    public function setAdresse(string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getDateNaissance(): ?\DateTimeInterface
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeInterface $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;

        return $this;
    }

    public function getSignaturePath(): ?string
    {
        return $this->signaturePath;
    }

    public function setSignaturePath(?string $signaturePath): static
    {
        $this->signaturePath = $signaturePath;

        return $this;
    }

    public function getSignatureUploadedAt(): ?\DateTimeInterface
    {
        return $this->signatureUploadedAt;
    }

    public function setSignatureUploadedAt(?\DateTimeInterface $signatureUploadedAt): static
    {
        $this->signatureUploadedAt = $signatureUploadedAt;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getCommentaireAdmin(): ?string
    {
        return $this->commentaireAdmin;
    }

    public function setCommentaireAdmin(?string $commentaireAdmin): static
    {
        $this->commentaireAdmin = $commentaireAdmin;

        return $this;
    }

    public function getDateSubmission(): \DateTimeInterface
    {
        return $this->dateSubmission;
    }

    public function setDateSubmission(\DateTimeInterface $dateSubmission): static
    {
        $this->dateSubmission = $dateSubmission;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(KycFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setKyc($this);
        }

        return $this;
    }

    public function removeFile(KycFile $file): static
    {
        if ($this->files->removeElement($file) && $file->getKyc() === $this) {
            $file->setKyc(null);
        }

        return $this;
    }
}
