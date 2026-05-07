<?php

namespace App\Service;

use App\Entity\Product\Product;

class ProductManager
{
    public function validate(Product $product): bool
    {
        if (trim($product->getCategory()) === '') {
            throw new \InvalidArgumentException('La categorie est obligatoire.');
        }

        if (!in_array($product->getCategory(), Product::getAllowedCategories(), true)) {
            throw new \InvalidArgumentException('La categorie selectionnee est invalide.');
        }

        if ($product->getPrice() <= 0) {
            throw new \InvalidArgumentException('Le prix doit etre strictement positif.');
        }

        if (mb_strlen(trim($product->getDescription())) < 4) {
            throw new \InvalidArgumentException('La description doit contenir au moins 4 caracteres.');
        }

        return true;
    }

    public function canBeSubscribed(Product $product): bool
    {
        return $product->getPrice() > 0
            && trim($product->getDescription()) !== ''
            && in_array($product->getCategory(), Product::getAllowedCategories(), true);
    }
}
