<?php

namespace App\Entity\User\Client;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'clients')]
class Client
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private string|null $nom = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private string|null $prenom = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private string|null $email = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private string|null $solde = null;

    #[ORM\Column(name: 'nb_cheques_refuses', type: 'integer', nullable: true)]
    private int|null $nbChequesRefuses = 0;

    #[ORM\Column(name: 'nb_jours_negatifs', type: 'integer', nullable: true)]
    private int|null $nbJoursNegatifs = 0;

    #[ORM\Column(name: 'retraits_eleves', type: 'integer', nullable: true)]
    private int|null $retraitsEleves = 0;

    #[ORM\Column(name: 'date_inscription', type: 'date', nullable: true)]
    private \DateTimeInterface|null $dateInscription = null;

    #[ORM\Column(name: 'dernier_score', type: 'integer', nullable: true)]
    private int|null $dernierScore = null;

    #[ORM\Column(name: 'niveau_risque', type: 'string', nullable: true)]
    private string|null $niveauRisque = 'MOYEN';

    #[ORM\Column(type: 'string', nullable: true)]
    private string|null $privilege = 'STANDARD';

    public function getId(): int
    {
        return $this->id;
    }

    public function getNom(): string|null
    {
        return $this->nom;
    }

    public function setNom(string|null $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getPrenom(): string|null
    {
        return $this->prenom;
    }

    public function setPrenom(string|null $prenom): static
    {
        $this->prenom = $prenom;
        return $this;
    }

    public function getEmail(): string|null
    {
        return $this->email;
    }

    public function setEmail(string|null $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getSolde(): string|null
    {
        return $this->solde;
    }

    public function setSolde(string|null $solde): static
    {
        $this->solde = $solde;
        return $this;
    }

    public function getNbChequesRefuses(): int|null
    {
        return $this->nbChequesRefuses;
    }

    public function setNbChequesRefuses(int|null $nbChequesRefuses): static
    {
        $this->nbChequesRefuses = $nbChequesRefuses;
        return $this;
    }

    public function getNbJoursNegatifs(): int|null
    {
        return $this->nbJoursNegatifs;
    }

    public function setNbJoursNegatifs(int|null $nbJoursNegatifs): static
    {
        $this->nbJoursNegatifs = $nbJoursNegatifs;
        return $this;
    }

    public function getRetraitsEleves(): int|null
    {
        return $this->retraitsEleves;
    }

    public function setRetraitsEleves(int|null $retraitsEleves): static
    {
        $this->retraitsEleves = $retraitsEleves;
        return $this;
    }

    public function getDateInscription(): \DateTimeInterface|null
    {
        return $this->dateInscription;
    }

    public function setDateInscription(\DateTimeInterface|null $dateInscription): static
    {
        $this->dateInscription = $dateInscription;
        return $this;
    }

    public function getDernierScore(): int|null
    {
        return $this->dernierScore;
    }

    public function setDernierScore(int|null $dernierScore): static
    {
        $this->dernierScore = $dernierScore;
        return $this;
    }

    public function getNiveauRisque(): string|null
    {
        return $this->niveauRisque;
    }

    public function setNiveauRisque(string|null $niveauRisque): static
    {
        $this->niveauRisque = $niveauRisque;
        return $this;
    }

    public function getPrivilege(): string|null
    {
        return $this->privilege;
    }

    public function setPrivilege(string|null $privilege): static
    {
        $this->privilege = $privilege;
        return $this;
    }
}
