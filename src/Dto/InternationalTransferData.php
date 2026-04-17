<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class InternationalTransferData
{
    #[Assert\NotNull(message: 'Le wallet source est obligatoire.')]
    #[Assert\Positive(message: 'Le wallet source selectionne est invalide.')]
    private ?int $sourceWalletId = null;

    #[Assert\NotNull(message: 'Le montant est obligatoire.')]
    #[Assert\Positive(message: 'Le montant doit etre strictement superieur a zero.')]
    private ?float $amount = null;

    #[Assert\NotBlank(message: 'La devise source est obligatoire.')]
    #[Assert\Length(
        min: 3,
        max: 3,
        exactMessage: 'La devise source doit contenir exactement {{ limit }} caracteres.'
    )]
    private ?string $sourceCurrency = null;

    #[Assert\NotBlank(message: 'La devise cible est obligatoire.')]
    #[Assert\Length(
        min: 3,
        max: 3,
        exactMessage: 'La devise cible doit contenir exactement {{ limit }} caracteres.'
    )]
    private ?string $targetCurrency = null;

    #[Assert\NotBlank(message: 'Le beneficiaire est obligatoire.')]
    #[Assert\Length(
        max: 120,
        maxMessage: 'Le beneficiaire ne doit pas depasser 120 caracteres.'
    )]
    private ?string $beneficiary = null;

    #[Assert\Length(
        max: 255,
        maxMessage: 'La reference ne doit pas depasser 255 caracteres.'
    )]
    private ?string $reference = null;

    public function getSourceWalletId(): ?int
    {
        return $this->sourceWalletId;
    }

    public function setSourceWalletId(?int $sourceWalletId): static
    {
        $this->sourceWalletId = $sourceWalletId;

        return $this;
    }

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function setAmount(?float $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getSourceCurrency(): ?string
    {
        return $this->sourceCurrency;
    }

    public function setSourceCurrency(?string $sourceCurrency): static
    {
        $this->sourceCurrency = $sourceCurrency;

        return $this;
    }

    public function getTargetCurrency(): ?string
    {
        return $this->targetCurrency;
    }

    public function setTargetCurrency(?string $targetCurrency): static
    {
        $this->targetCurrency = $targetCurrency;

        return $this;
    }

    public function getBeneficiary(): ?string
    {
        return $this->beneficiary;
    }

    public function setBeneficiary(?string $beneficiary): static
    {
        $this->beneficiary = $beneficiary;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }
}
