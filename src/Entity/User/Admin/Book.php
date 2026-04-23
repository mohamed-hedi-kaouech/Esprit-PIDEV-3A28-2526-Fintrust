<?php

namespace App\Entity\User\Admin;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'book')]
class Book
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'string', length: 255)]
    private string $category;

    #[ORM\Column(type: 'boolean')]
    private bool $published;

    #[ORM\Column(name: 'publication_date', type: 'date')]
    private \DateTimeInterface $publicationDate;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\Admin\Author::class, inversedBy:'books')]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
    private Author|null $author = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): static
    {
        $this->published = $published;
        return $this;
    }

    public function getPublicationDate(): \DateTimeInterface
    {
        return $this->publicationDate;
    }

    public function setPublicationDate(\DateTimeInterface $publicationDate): static
    {
        $this->publicationDate = $publicationDate;
        return $this;
    }

    public function getAuthor(): Author|null
    {
        return $this->author;
    }

    public function setAuthor(Author|null $author): static
    {
        $this->author = $author;
        return $this;
    }

    public function isPublished(): ?bool
    {
        return $this->published;
    }
}
