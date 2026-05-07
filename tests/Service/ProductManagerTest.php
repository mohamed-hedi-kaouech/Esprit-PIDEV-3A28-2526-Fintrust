<?php

namespace App\Tests\Service;

use App\Entity\Product\Product;
use App\Service\ProductManager;
use PHPUnit\Framework\TestCase;

class ProductManagerTest extends TestCase
{
    public function testValidProduct(): void
    {
        $product = (new Product())
            ->setCategory('COMPTE_COURANT')
            ->setPrice(25.5)
            ->setDescription('Compte bancaire standard');

        $manager = new ProductManager();

        self::assertTrue($manager->validate($product));
    }

    public function testProductWithoutCategory(): void
    {
        $product = (new Product())
            ->setCategory('')
            ->setPrice(25.5)
            ->setDescription('Compte bancaire standard');

        $manager = new ProductManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La categorie est obligatoire.');

        $manager->validate($product);
    }

    public function testProductWithInvalidCategory(): void
    {
        $product = (new Product())
            ->setCategory('CRYPTO_UNKNOWN')
            ->setPrice(25.5)
            ->setDescription('Produit non reconnu');

        $manager = new ProductManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La categorie selectionnee est invalide.');

        $manager->validate($product);
    }

    public function testProductWithInvalidPrice(): void
    {
        $product = (new Product())
            ->setCategory('COMPTE_COURANT')
            ->setPrice(0.0)
            ->setDescription('Compte bancaire standard');

        $manager = new ProductManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le prix doit etre strictement positif.');

        $manager->validate($product);
    }

    public function testCanBeSubscribedReturnsTrueForValidProduct(): void
    {
        $product = (new Product())
            ->setCategory('CARTE_DEBIT')
            ->setPrice(15.0)
            ->setDescription('Carte de paiement');

        $manager = new ProductManager();

        self::assertTrue($manager->canBeSubscribed($product));
    }
}
