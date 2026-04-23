<?php

namespace App\Entity\User;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_badges')]
class UserBadge
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(name: 'badge_code', type: 'string', length: 80)]
    private string $badgeCode;

    #[ORM\Column(name: 'badge_label', type: 'string', length: 160)]
    private string $badgeLabel;

    #[ORM\Column(name: 'awarded_at', type: 'datetime')]
    private \DateTimeInterface $awardedAt;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private User $user;

    public function getId(): int
    {
        return $this->id;
    }

    public function getBadgeCode(): string
    {
        return $this->badgeCode;
    }

    public function setBadgeCode(string $badgeCode): static
    {
        $this->badgeCode = $badgeCode;
        return $this;
    }

    public function getBadgeLabel(): string
    {
        return $this->badgeLabel;
    }

    public function setBadgeLabel(string $badgeLabel): static
    {
        $this->badgeLabel = $badgeLabel;
        return $this;
    }

    public function getAwardedAt(): \DateTimeInterface
    {
        return $this->awardedAt;
    }

    public function setAwardedAt(\DateTimeInterface $awardedAt): static
    {
        $this->awardedAt = $awardedAt;
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
