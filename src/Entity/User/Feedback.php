<?php

namespace App\Entity\User;

use App\Entity\Publication\Publication;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'feedback')]
class Feedback
{
    #[ORM\Id]
    #[ORM\Column(name: 'id_feedback', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $idFeedback;

    #[ORM\Column(name: 'id_user', type: 'integer')]
    private int $idUser;

    #[ORM\Column(name: 'commentaire', type: 'text', nullable: true)]
    private string|null $commentaire = null;

    #[ORM\Column(name: 'type_reaction', type: 'string', length: 20, nullable: true)]
    private string|null $typeReaction = null;

    #[ORM\Column(name: 'date_feedback', type: 'datetime', nullable: true)]
    private \DateTimeInterface|null $dateFeedback;

    // FIX: added inversedBy: 'feedbacks' to match Publication#feedbacks OneToMany
    #[ORM\ManyToOne(targetEntity: \App\Entity\Publication\Publication::class, inversedBy: 'feedbacks')]
    #[ORM\JoinColumn(name: 'id_publication', referencedColumnName: 'id_publication', onDelete: 'CASCADE')]
    private Publication $publication;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'id_user', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private User $user;

    public function getIdFeedback(): int
    {
        return $this->idFeedback;
    }

    public function getIdUser(): int
    {
        return $this->idUser;
    }

    public function setIdUser(int $idUser): static
    {
        $this->idUser = $idUser;
        return $this;
    }

    public function getCommentaire(): string|null
    {
        return $this->commentaire;
    }

    public function setCommentaire(string|null $commentaire): static
    {
        $this->commentaire = $commentaire;
        return $this;
    }

    public function getTypeReaction(): string|null
    {
        return $this->typeReaction;
    }

    public function setTypeReaction(string|null $typeReaction): static
    {
        $this->typeReaction = $typeReaction;
        return $this;
    }

    public function getDateFeedback(): \DateTimeInterface|null
    {
        return $this->dateFeedback;
    }

    public function setDateFeedback(\DateTimeInterface|null $dateFeedback): static
    {
        $this->dateFeedback = $dateFeedback;
        return $this;
    }

    public function getPublication(): Publication
    {
        return $this->publication;
    }

    public function setPublication(Publication $publication): static
    {
        $this->publication = $publication;
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
}
