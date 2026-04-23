<?php

namespace App\Entity\User\Client;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'game_sessions')]
class GameSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private string $id;

    #[ORM\Column(type: 'string')]
    private string $context;

    #[ORM\Column(name: 'game_type', type: 'string', length: 20)]
    private string $gameType;

    #[ORM\Column(name: 'started_at', type: 'datetime')]
    private \DateTimeInterface $startedAt;

    #[ORM\Column(name: 'ended_at', type: 'datetime', nullable: true)]
    private \DateTimeInterface|null $endedAt = null;

    #[ORM\Column(name: 'duration_ms', type: 'bigint', nullable: true)]
    private int|null $durationMs = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private int|null $score = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private int|null $moves = null;

    #[ORM\Column(name: 'is_valid', type: 'boolean')]
    private bool $isValid = false;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private User $user;

    public function getId(): string
    {
        return $this->id;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function setContext(string $context): static
    {
        $this->context = $context;
        return $this;
    }

    public function getGameType(): string
    {
        return $this->gameType;
    }

    public function setGameType(string $gameType): static
    {
        $this->gameType = $gameType;
        return $this;
    }

    public function getStartedAt(): \DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeInterface $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getEndedAt(): \DateTimeInterface|null
    {
        return $this->endedAt;
    }

    public function setEndedAt(\DateTimeInterface|null $endedAt): static
    {
        $this->endedAt = $endedAt;
        return $this;
    }

    public function getDurationMs(): int|null
    {
        return $this->durationMs;
    }

    public function setDurationMs(int|null $durationMs): static
    {
        $this->durationMs = $durationMs;
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

    public function getMoves(): int|null
    {
        return $this->moves;
    }

    public function setMoves(int|null $moves): static
    {
        $this->moves = $moves;
        return $this;
    }

    public function getIsValid(): bool
    {
        return $this->isValid;
    }

    public function setIsValid(bool $isValid): static
    {
        $this->isValid = $isValid;
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

    public function isValid(): ?bool
    {
        return $this->isValid;
    }
}
