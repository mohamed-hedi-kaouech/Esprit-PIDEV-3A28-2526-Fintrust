<?php

namespace App\Entity\User\Client;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'kyc_files')]
class KycFile
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(name: 'file_name', type: 'string', length: 255)]
    private string $fileName;

    #[ORM\Column(name: 'file_path', type: 'string', length: 255)]
    private string $filePath;

    #[ORM\Column(name: 'file_type', type: 'string', length: 20)]
    private string $fileType;

    #[ORM\Column(name: 'file_size', type: 'bigint')]
    private int $fileSize;

    #[ORM\Column(name: 'updated_at', type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\Client\Kyc::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'kyc_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Kyc $kyc = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getFileType(): string
    {
        return $this->fileType;
    }

    public function setFileType(string $fileType): static
    {
        $this->fileType = $fileType;

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): static
    {
        $this->fileSize = $fileSize;

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

    public function getKyc(): ?Kyc
    {
        return $this->kyc;
    }

    public function setKyc(?Kyc $kyc): static
    {
        $this->kyc = $kyc;

        return $this;
    }
}
