<?php

namespace App\Entity\Loan;

use App\Entity\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\Loan\LoanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;


#[ORM\Entity(repositoryClass: LoanRepository::class)]
#[ORM\Table(name: 'Loan')]
class Loan
{
    #[ORM\Id]
    #[ORM\Column(name: 'loanId', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $loanId;

    #[ORM\Column(name: 'loanType', type: 'string', length: 50)]
    private string $loanType;

    #[ORM\Column(name: 'amount', type: 'decimal', precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(name: 'duration', type: 'integer')]
    private int $duration;

    #[ORM\Column(name: 'interest_rate', type: 'decimal', precision: 5, scale: 2)]
    private string $interestRate;

    #[ORM\Column(name: 'remaining_principal', type: 'decimal', precision: 12, scale: 2)]
    private string $remainingPrincipal;

    #[ORM\Column(name: 'status', type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(name: 'createdAt', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'id_user', type: 'integer', nullable: true)]
    private int|null $idUser = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_user', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private User|null $user = null;

    #[ORM\OneToMany(targetEntity: Repayment::class, mappedBy: 'loan')]
    private Collection $repayments;

    public function __construct()
    {
        $this->repayments = new ArrayCollection();
    }

    public function getLoanId(): int
    {
        return $this->loanId;
    }

    public function getLoanType(): string
    {
        return $this->loanType;
    }

    public function setLoanType(string $loanType): static
    {
        $this->loanType = $loanType;
        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function setDuration(int $duration): static
    {
        $this->duration = $duration;
        return $this;
    }

    public function getInterestRate(): string
    {
        return $this->interestRate;
    }

    public function setInterestRate(string $interestRate): static
    {
        $this->interestRate = $interestRate;
        return $this;
    }

    public function getRemainingPrincipal(): string
    {
        return $this->remainingPrincipal;
    }

    public function setRemainingPrincipal(string $remainingPrincipal): static
    {
        $this->remainingPrincipal = $remainingPrincipal;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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

    public function getIdUser(): int|null
    {
        return $this->idUser;
    }

    public function setIdUser(int|null $idUser): static
    {
        $this->idUser = $idUser;
        return $this;
    }

    public function getUser(): User|null
    {
        return $this->user;
    }

    public function setUser(User|null $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getRepayments(): Collection
    {
        return $this->repayments;
    }

    public function addRepayment(Repayment $repayment): static
    {
        if (!$this->repayments->contains($repayment)) {
            $this->repayments->add($repayment);
        }
        return $this;
    }

    public function removeRepayment(Repayment $repayment): static
    {
        $this->repayments->removeElement($repayment);
        return $this;
    }
}
