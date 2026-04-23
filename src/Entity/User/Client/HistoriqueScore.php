<?php

namespace App\Entity\User\Client;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'historique_scores')]
class HistoriqueScore
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(name: 'client_id', type: 'integer', nullable: true)]
    private int|null $clientId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private int|null $score = null;

    #[ORM\Column(name: 'date_calcul', type: 'date', nullable: true)]
    private \DateTimeInterface|null $dateCalcul = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getClientId(): int|null
    {
        return $this->clientId;
    }

    public function setClientId(int|null $clientId): static
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function getScore(): int|null
    {
        return $this->score;
    }

    public function setScore(int|null $score): static
    {
        $this->score = $score;
        return $this;
    }

    public function getDateCalcul(): \DateTimeInterface|null
    {
        return $this->dateCalcul;
    }

    public function setDateCalcul(\DateTimeInterface|null $dateCalcul): static
    {
        $this->dateCalcul = $dateCalcul;
        return $this;
    }
}
