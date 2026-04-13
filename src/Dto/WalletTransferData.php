<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class WalletTransferData
{
    #[Assert\NotBlank(message: 'Le destinataire est obligatoire.')]
    #[Assert\Length(
        max: 100,
        maxMessage: 'Le destinataire ne doit pas depasser 100 caracteres.'
    )]
    private ?string $recipient = null;

    #[Assert\NotNull(message: 'Le montant est obligatoire.')]
    #[Assert\Positive(message: 'Le montant doit etre superieur a zero.')]
    private ?float $amount = null;

    #[Assert\Length(
        max: 255,
        maxMessage: 'Le libelle ne doit pas depasser 255 caracteres.'
    )]
    private ?string $label = null;

    public function getRecipient(): ?string
    {
        return $this->recipient;
    }

    public function setRecipient(?string $recipient): static
    {
        $this->recipient = $recipient;

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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }
}
