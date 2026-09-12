<?php

namespace App\Controller\Admin;

use App\Entity\BlogCategory;
use App\Repository\BlogCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/kategoriler', name: 'admin_blog_category_')]
#[IsGranted('ROLE_ADMIN')]
class BlogCategoryController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(BlogCategoryRepository $repository): Response
    {
        return $this->render('admin/blog_category/index.html.twig', [
            'categories' => $repository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/ekle', name: 'new', methods: ['POST'])]
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $name = trim((string) $request->request->get('name'));

        if ($name !== '') {
            $category = new BlogCategory();
            $category->setName($name);
            $category->setSlug((string) $slugger->slug($name)->lower());

            $em->persist($category);
            $em->flush();

            $this->addFlash('success', 'Kategori eklendi.');
        }

        return $this->redirectToRoute('admin_blog_category_index');
    }

    #[Route('/{id}/sil', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(BlogCategory $category, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete-category-' . $category->getId(), $request->request->get('_token'))) {
            $em->remove($category);
            $em->flush();
            $this->addFlash('success', 'Kategori silindi.');
        }

        return $this->redirectToRoute('admin_blog_category_index');
    }
}