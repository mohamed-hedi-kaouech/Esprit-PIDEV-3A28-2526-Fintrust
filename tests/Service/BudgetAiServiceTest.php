<?php

namespace App\Tests\Service;

use App\Entity\Categorie\Categorie;
use App\Entity\Categorie\Item;
use App\Repository\CategorieRepository;
use App\Repository\ItemRepository;
use App\Service\BudgetAiService;
use PHPUnit\Framework\TestCase;

class BudgetAiServiceTest extends TestCase
{
    public function testAnswerReturnsMessageWhenNoBudgetDataExists(): void
    {
        $categorieRepository = $this->createMock(CategorieRepository::class);
        $itemRepository = $this->createMock(ItemRepository::class);

        $categorieRepository->method('findAll')->willReturn([]);

        $service = new BudgetAiService($categorieRepository, $itemRepository);

        self::assertSame(
            'Aucune donnee budgetaire trouvee. Creez d abord des categories et des depenses.',
            $service->answer('budget total')
        );
    }

    public function testAnswerReturnsTotalBudgetSummary(): void
    {
        [$categorieRepository, $itemRepository] = $this->createBudgetRepositories();
        $service = new BudgetAiService($categorieRepository, $itemRepository);

        $answer = $service->answer('Quel est le budget total ?');

        self::assertStringContainsString('Le budget total alloue est de **1 500.000 TND**', $answer);
        self::assertStringContainsString('2 categorie(s)', $answer);
    }

    public function testAnswerDetectsBudgetOverflowCategory(): void
    {
        [$categorieRepository, $itemRepository] = $this->createBudgetRepositories();
        $service = new BudgetAiService($categorieRepository, $itemRepository);

        $answer = $service->answer('Y a-t-il un depassement de budget ?');

        self::assertStringContainsString('Categories en depassement', $answer);
        self::assertStringContainsString('Transport', $answer);
    }

    public function testAnswerReturnsAlertForHighUsageCategory(): void
    {
        [$categorieRepository, $itemRepository] = $this->createBudgetRepositories();
        $service = new BudgetAiService($categorieRepository, $itemRepository);

        $answer = $service->answer('Montre moi les alertes budgetaires');

        self::assertStringContainsString('Categories a surveiller', $answer);
        self::assertStringContainsString('Transport', $answer);
        self::assertStringContainsString('120%', $answer);
    }

    public function testAnswerReturnsCategoryDetailsWhenCategoryNameIsMentioned(): void
    {
        [$categorieRepository, $itemRepository] = $this->createBudgetRepositories();
        $service = new BudgetAiService($categorieRepository, $itemRepository);

        $answer = $service->answer('Donne moi le detail de Transport');

        self::assertStringContainsString('Categorie **Transport**', $answer);
        self::assertStringContainsString('Budget : 500.000 TND', $answer);
        self::assertStringContainsString('Depense : 600.000 TND', $answer);
        self::assertStringContainsString('Utilisation : 120%', $answer);
    }

    /**
     * @return array{0: CategorieRepository, 1: ItemRepository}
     */
    private function createBudgetRepositories(): array
    {
        $transport = (new Categorie())
            ->setNomCategorie('Transport')
            ->setBudgetPrevu(500.0)
            ->setSeuilAlerte(80.0);

        $food = (new Categorie())
            ->setNomCategorie('Food')
            ->setBudgetPrevu(1000.0)
            ->setSeuilAlerte(80.0);

        $transportItems = [
            (new Item())->setLibelle('Taxi')->setMontant(300.0)->setCategorie($transport),
            (new Item())->setLibelle('Fuel')->setMontant(300.0)->setCategorie($transport),
        ];

        $foodItems = [
            (new Item())->setLibelle('Groceries')->setMontant(250.0)->setCategorie($food),
            (new Item())->setLibelle('Restaurant')->setMontant(150.0)->setCategorie($food),
        ];

        $categorieRepository = $this->createMock(CategorieRepository::class);
        $categorieRepository->method('findAll')->willReturn([$transport, $food]);

        $itemRepository = $this->createMock(ItemRepository::class);
        $itemRepository
            ->method('findBy')
            ->willReturnCallback(static function (array $criteria) use ($transport, $food, $transportItems, $foodItems): array {
                if (($criteria['categorie'] ?? null) === $transport) {
                    return $transportItems;
                }

                if (($criteria['categorie'] ?? null) === $food) {
                    return $foodItems;
                }

                return [];
            });

        return [$categorieRepository, $itemRepository];
    }
}
