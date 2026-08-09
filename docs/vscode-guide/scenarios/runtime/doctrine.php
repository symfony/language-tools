<?php

use App\Entity\Product;
use App\Repository\ProductRepository;

function findProducts(ProductRepository $products): void
{
    $products->findBy(['na' => 'Symfony']);
    $products->findBy(['name' => 'Symfony']);
}
