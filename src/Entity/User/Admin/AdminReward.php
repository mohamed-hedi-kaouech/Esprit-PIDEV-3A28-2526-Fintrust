<?php

namespace App\Entity\User\Admin;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'admin_rewards')]
class AdminReward
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(name: 'admin_id', type: 'integer')]
    private int $adminId;

    #[ORM\Column(name: 'total_stars', type: 'integer')]
    private int $totalStars = 0;

    #[ORM\Column(name: 'total_points', type: 'integer')]
    private int $totalPoints = 0;

    #[ORM\Column(name: 'streak_days', type: 'integer')]
    private int $streakDays = 0;

    #[ORM\Column(name: 'last_completion_date', type: 'date', nullable: true)]
    private \DateTimeInterface|null $lastCompletionDate = null;

    #[ORM\Column(name: 'task_finisher_badge', type: 'boolean')]
    private bool $taskFinisherBadge = false;

    #[ORM\Column(name: 'updated_at', type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'admin_id', referencedColumnName: 'id')]
    private User $admin;

    public function getId(): int
    {
        return $this->id;
    }

    public function getAdminId(): int
    {
        return $this->adminId;
    }

    public function setAdminId(int $adminId): static
    {
        $this->adminId = $adminId;
        return $this;
    }

    public function getTotalStars(): int
    {
        return $this->totalStars;
    }

    public function setTotalStars(int $totalStars): static
    {
        $this->totalStars = $totalStars;
        return $this;
    }

    public function getTotalPoints(): int
    {
        return $this->totalPoints;
    }

    public function setTotalPoints(int $totalPoints): static
    {
        $this->totalPoints = $totalPoints;
        return $this;
    }

    public function getStreakDays(): int
    {
        return $this->streakDays;
    }

    public function setStreakDays(int $streakDays): static
    {
        $this->streakDays = $streakDays;
        return $this;
    }

    public function getLastCompletionDate(): \DateTimeInterface|null
    {
        return $this->lastCompletionDate;
    }

    public function setLastCompletionDate(\DateTimeInterface|null $lastCompletionDate): static
    {
        $this->lastCompletionDate = $lastCompletionDate;
        return $this;
    }

    public function getTaskFinisherBadge(): bool
    {
        return $this->taskFinisherBadge;
    }

    public function setTaskFinisherBadge(bool $taskFinisherBadge): static
    {
        $this->taskFinisherBadge = $taskFinisherBadge;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getAdmin(): User
    {
        return $this->admin;
    }

    public function setAdmin(User $admin): static
    {
        $this->admin = $admin;
        return $this;
    }

    public function isTaskFinisherBadge(): ?bool
    {
        return $this->taskFinisherBadge;
    }
}
