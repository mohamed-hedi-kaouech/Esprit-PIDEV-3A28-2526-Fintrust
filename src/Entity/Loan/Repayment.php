<?php

namespace App\Entity\Loan;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'Repayment')]
class Repayment
{
    #[ORM\Id]
    #[ORM\Column(name: 'repayId', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $repayId;

    #[ORM\Column(name: 'month', type: 'integer')]
    private int $month;

    #[ORM\Column(name: 'startingBalance', type: 'decimal', precision: 10, scale: 2)]
    private string $startingBalance;

    #[ORM\Column(name: 'monthlyPayment', type: 'decimal', precision: 10, scale: 2)]
    private string $monthlyPayment;

    #[ORM\Column(name: 'capitalPart', type: 'decimal', precision: 10, scale: 2)]
    private string $capitalPart;

    #[ORM\Column(name: 'interestPart', type: 'decimal', precision: 10, scale: 2)]
    private string $interestPart;

    #[ORM\Column(name: 'remainingBalance', type: 'decimal', precision: 10, scale: 2)]
    private string $remainingBalance;

    #[ORM\Column(name: 'status', type: 'string', length: 20)]
    private string $status;

    #[ORM\ManyToOne(targetEntity: Loan::class, inversedBy: "repayments")]
    #[ORM\JoinColumn(name: 'loanId', referencedColumnName: 'loanId', onDelete: 'CASCADE')]
    private Loan $loan;

    public function getRepayId(): int
    {
        return $this->repayId;
    }

    public function getLoanId(): int
    {
        return $this->loan->getLoanId();
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function setMonth(int $month): static
    {
        $this->month = $month;
        return $this;
    }

    public function getStartingBalance(): string
    {
        return $this->startingBalance;
    }

    public function setStartingBalance(string $startingBalance): static
    {
        $this->startingBalance = $startingBalance;
        return $this;
    }

    public function getMonthlyPayment(): string
    {
        return $this->monthlyPayment;
    }

    public function setMonthlyPayment(string $monthlyPayment): static
    {
        $this->monthlyPayment = $monthlyPayment;
        return $this;
    }

    public function getCapitalPart(): string
    {
        return $this->capitalPart;
    }

    public function setCapitalPart(string $capitalPart): static
    {
        $this->capitalPart = $capitalPart;
        return $this;
    }

    public function getInterestPart(): string
    {
        return $this->interestPart;
    }

    public function setInterestPart(string $interestPart): static
    {
        $this->interestPart = $interestPart;
        return $this;
    }

    public function getRemainingBalance(): string
    {
        return $this->remainingBalance;
    }

    public function setRemainingBalance(string $remainingBalance): static
    {
        $this->remainingBalance = $remainingBalance;
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

    public function getLoan(): Loan
    {
        return $this->loan;
    }

    public function setLoan(Loan $loan): static
    {
        $this->loan = $loan;
        return $this;
    }
}