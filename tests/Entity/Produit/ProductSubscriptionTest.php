<?php

namespace App\Tests\Entity\Produit;

use App\Entity\Product\Product;
use App\Entity\Product\ProductSubscription;
use App\Entity\User\User;
use PHPUnit\Framework\TestCase;

class ProductSubscriptionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Souscription valide
    // -------------------------------------------------------------------------

    public function testValidSubscription()
    {
        $subscription = new ProductSubscription();
        $subscription->setClient(1);
        $subscription->setProduct(42);
        $subscription->setType('MENSUEL');
        $subscription->setStatus('ACTIF');
        $subscription->setSubscriptionDate(new \DateTime('2025-01-01'));
        $subscription->setExpirationDate(new \DateTime('2026-01-01'));

        $this->assertSame(1, $subscription->getClient());
        $this->assertSame(42, $subscription->getProduct());
        $this->assertSame('MENSUEL', $subscription->getType());
        $this->assertSame('ACTIF', $subscription->getStatus());
    }

    // -------------------------------------------------------------------------
    // Règle métier 1 : La date d'expiration doit être postérieure à la date de souscription
    // -------------------------------------------------------------------------

    public function testExpirationDateAfterSubscriptionDate()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("La date d'expiration doit être postérieure à la date de souscription.");

        $subscription = new ProductSubscription();
        $subscription->setSubscriptionDate(new \DateTime('2025-06-01'));
        $subscription->setExpirationDate(new \DateTime('2025-01-01')); // antérieure → exception
    }

    public function testExpirationDateEqualToSubscriptionDateThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("La date d'expiration doit être postérieure à la date de souscription.");

        $subscription = new ProductSubscription();
        $subscription->setSubscriptionDate(new \DateTime('2025-06-01'));
        $subscription->setExpirationDate(new \DateTime('2025-06-01')); // même jour → exception
    }

    // -------------------------------------------------------------------------
    // Règle métier 2 : Le type ne peut pas être vide
    // -------------------------------------------------------------------------

    public function testTypeCannotBeEmpty()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le type de souscription est obligatoire.');

        $subscription = new ProductSubscription();
        $subscription->setType('');
    }

    // -------------------------------------------------------------------------
    // Règle métier 3 : Le statut ne peut pas être vide
    // -------------------------------------------------------------------------

    public function testStatusCannotBeEmpty()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le statut est obligatoire.');

        $subscription = new ProductSubscription();
        $subscription->setStatus('');
    }

    // -------------------------------------------------------------------------
    // Relations : clientUser et productObj
    // -------------------------------------------------------------------------

    public function testSetAndGetClientUser()
    {
        $subscription = new ProductSubscription();
        $user = $this->createMock(User::class);

        $subscription->setClientUser($user);

        $this->assertSame($user, $subscription->getClientUser());
    }

    public function testSetAndGetProductObj()
    {
        $subscription = new ProductSubscription();
        $product = $this->createMock(Product::class);

        $subscription->setProductObj($product);

        $this->assertSame($product, $subscription->getProductObj());
    }

    // -------------------------------------------------------------------------
    // Fluent interface (chaînage des setters)
    // -------------------------------------------------------------------------

    public function testFluentInterface()
    {
        $subscription = new ProductSubscription();
        $start = new \DateTime('2025-01-01');
        $end   = new \DateTime('2026-01-01');

        $result = $subscription
            ->setClient(5)
            ->setProduct(10)
            ->setType('ANNUEL')
            ->setStatus('ACTIF')
            ->setSubscriptionDate($start)
            ->setExpirationDate($end);

        $this->assertSame($subscription, $result);
        $this->assertSame(5, $subscription->getClient());
        $this->assertSame(10, $subscription->getProduct());
        $this->assertSame('ANNUEL', $subscription->getType());
        $this->assertSame('ACTIF', $subscription->getStatus());
        $this->assertSame($start, $subscription->getSubscriptionDate());
        $this->assertSame($end, $subscription->getExpirationDate());
    }
}