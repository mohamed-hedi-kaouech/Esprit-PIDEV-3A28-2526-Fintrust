<?php

namespace App\Entity\User\Admin;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'admin_task_history')]
class AdminTaskHistory
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(name: 'task_id', type: 'integer')]
    private int $taskId;

    #[ORM\Column(name: 'actor_admin_id', type: 'integer')]
    private int $actorAdminId;

    #[ORM\Column(name: 'action', type: 'string', length: 40)]
    private string $action;

    #[ORM\Column(name: 'from_status', type: 'string', length: 20, nullable: true)]
    private string|null $fromStatus = null;

    #[ORM\Column(name: 'to_status', type: 'string', length: 20, nullable: true)]
    private string|null $toStatus = null;

    #[ORM\Column(name: 'note', type: 'string', length: 255, nullable: true)]
    private string|null $note = null;

    #[ORM\Column(name: 'stars_earned', type: 'integer')]
    private int $starsEarned = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    // FIX: added inversedBy: 'history' to match AdminTask#history OneToMany
    #[ORM\ManyToOne(targetEntity: \App\Entity\User\Admin\AdminTask::class, inversedBy: 'history')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private AdminTask $task;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'actor_admin_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private User $actorAdmin;

    public function getId(): int
    {
        return $this->id;
    }

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function setTaskId(int $taskId): static
    {
        $this->taskId = $taskId;
        return $this;
    }

    public function getActorAdminId(): int
    {
        return $this->actorAdminId;
    }

    public function setActorAdminId(int $actorAdminId): static
    {
        $this->actorAdminId = $actorAdminId;
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getFromStatus(): string|null
    {
        return $this->fromStatus;
    }

    public function setFromStatus(string|null $fromStatus): static
    {
        $this->fromStatus = $fromStatus;
        return $this;
    }

    public function getToStatus(): string|null
    {
        return $this->toStatus;
    }

    public function setToStatus(string|null $toStatus): static
    {
        $this->toStatus = $toStatus;
        return $this;
    }

    public function getNote(): string|null
    {
        return $this->note;
    }

    public function setNote(string|null $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function getStarsEarned(): int
    {
        return $this->starsEarned;
    }

    public function setStarsEarned(int $starsEarned): static
    {
        $this->starsEarned = $starsEarned;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getTask(): AdminTask
    {
        return $this->task;
    }

    public function setTask(AdminTask $task): static
    {
        $this->task = $task;
        return $this;
    }

    public function getActorAdmin(): User
    {
        return $this->actorAdmin;
    }

    public function setActorAdmin(User $actorAdmin): static
    {
        $this->actorAdmin = $actorAdmin;
        return $this;
    }
}
