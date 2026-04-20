<?php

namespace App\Dto;

class InternationalTransferData
{
    private ?int $sourceWalletId = null;
    private ?float $amount = null;
    private ?string $sourceCurrency = null;
    private ?string $targetCurrency = null;
    private ?string $beneficiary = null;
    private ?string $reference = null;

    public function getSourceWalletId(): ?int
    {
        return $this->sourceWalletId;
    }

    public function setSourceWalletId(?int $sourceWalletId): self
    {
        $this->sourceWalletId = $sourceWalletId;

        return $this;
    }

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function setAmount(?float $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getSourceCurrency(): ?string
    {
        return $this->sourceCurrency;
    }

    public function setSourceCurrency(?string $sourceCurrency): self
    {
        $this->sourceCurrency = $sourceCurrency;

        return $this;
    }

    public function getTargetCurrency(): ?string
    {
        return $this->targetCurrency;
    }

    public function setTargetCurrency(?string $targetCurrency): self
    {
        $this->targetCurrency = $targetCurrency;

        return $this;
    }

    public function getBeneficiary(): ?string
    {
        return $this->beneficiary;
    }

    public function setBeneficiary(?string $beneficiary): self
    {
        $this->beneficiary = $beneficiary;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }
}
