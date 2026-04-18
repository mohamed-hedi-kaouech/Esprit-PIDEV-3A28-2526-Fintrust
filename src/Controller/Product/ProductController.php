<?php

namespace App\Controller\Product;

use App\Entity\Product\Product;
use App\Form\Admin\ProductForm;
use App\Repository\Product\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


final class ProductController extends AbstractController
{

    #[Route('/Product/List', name: 'product_list')]
    public function list(Request $request, ProductRepository $repo): Response
    {
        $products = $repo->findFiltered(
            $request->query->get('search', ''),
            $request->query->get('category', ''),
            $request->query->get('sort', '')
        );

        return $this->render('html/Product/Admin/ProductList.html.twig', [
            'products' => $products,
        ]);
    }

    #[Route('/deleteProduct/{id}', name: 'product_delete', methods: ['POST'])]
    public function delete($id, ProductRepository $repository, EntityManagerInterface $em): Response{
        $product = $repository->find($id);
        if (!$product) {
            throw $this->createNotFoundException('Product not found');
        }
        $em->remove($product);
        $em->flush();
        return $this->redirectToRoute('product_list', [
            'swal' => 'success',
            'msg'  => 'Produit supprimé avec succès',
        ]);
    }

    #[Route('/EditProduct', name: 'EditProduct', methods: ['GET', 'POST'])]
    public function EditProduct(
        Request $request,
        ProductRepository $repository,
        EntityManagerInterface $em
    ): Response {

        $id = $request->query->get('id');
        $product = $repository->find($id);

        if (!$product) {
            throw $this->createNotFoundException('Product not found');
        }

        // Pre-fill the form with existing product data
        $form = $this->createForm(ProductForm::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validateProductData($product);
            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->addFlash('error', $err);
                }
                return $this->render('html/Product/Admin/ProductEdit.html.twig', [
                    'old' => $request->request->all(),'form' => $form->createView()
                ]);
            }
            $em->flush();

            return $this->redirectToRoute('product_list', [
                'swal' => 'success',
                'msg'  => 'Produit modifié avec succès',
            ]);
        }

        return $this->render('html/Product/Admin/ProductEdit.html.twig', [
            'product' => $product,
            'form'    => $form->createView(),
            'categories' => $this->getProductCategories(),
            'formData' => $product,
        ]);
    }


    #[Route('/CreateProduct', name: 'CreateProduct', methods: ['GET','POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $product = new Product();
        $form    = $this->createForm(ProductForm::class, $product);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $errors = $this->validateProductData($product);

            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->addFlash('error', $err);

                }

                return $this->render('html/Product/Admin/ProductCreate.html.twig', [
                    'form' => $form->createView()
                ]);
            }

            $product->setCreatedAt(new \DateTime());

            $em->persist($product);
            $em->flush();

            return $this->redirectToRoute('product_list', [
                'swal' => 'success',
                'msg'  => 'Produit créé avec succès',
            ]);
        }

        return $this->render('html/Product/Admin/ProductCreate.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    // 🔥 Reusable validation


    private function validateProductData(Product $product): array
    {
        $errors = [];

        if (!$product->getCategory()) {
            $errors[] = 'La catégorie est obligatoire';
        }

        if (!is_numeric($product->getPrice()) || $product->getPrice() < 0) {
            $errors[] = 'Le prix doit être un nombre positif';
        }

        if (!$product->getDescription() || strlen($product->getDescription()) < 4) {
            $errors[] = 'La description doit contenir au moins 4 caractères';
        }

        return $errors;
    }


    // 🔥 Reusable hydration
    private function hydrateProduct(Product $product, Request $request): void
    {
        $product->setCategory($request->request->get('category'));
        $product->setPrice((float)$request->request->get('price'));
        $product->setDescription(trim($request->request->get('description')));

    }

    private function getProductCategories(): array{
        return [
            'COMPTE_COURANT',
            'COMPTE_EPARGNE',
            'COMPTE_PREMIUM',
            'COMPTE_JEUNE',
            'COMPTE_ENTREPRISE',
            'CARTE_DEBIT',
            'CARTE_CREDIT',
            'CARTE_PREMIUM',
            'CARTE_VIRTUELLE',
            'EPARGNE_CLASSIQUE',
            'EPARGNE_LOGEMENT',
            'DEPOT_A_TERME',
            'PLACEMENT_INVESTISSEMENT',
            'ASSURANCE_VIE',
            'ASSURANCE_HABITATION',
            'ASSURANCE_VOYAGE',
        ];
    }
}