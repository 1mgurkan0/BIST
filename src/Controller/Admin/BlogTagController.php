<?php

namespace App\Controller\Admin;

use App\Entity\BlogTag;
use App\Repository\BlogTagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/etiketler', name: 'admin_blog_tag_')]
#[IsGranted('ROLE_ADMIN')]
class BlogTagController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(BlogTagRepository $repository): Response
    {
        return $this->render('admin/blog_tag/index.html.twig', [
            'tags' => $repository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/ekle', name: 'new', methods: ['POST'])]
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $name = trim((string) $request->request->get('name'));

        if ($name !== '') {
            $tag = new BlogTag();
            $tag->setName($name);
            $tag->setSlug((string) $slugger->slug($name)->lower());

            $em->persist($tag);
            $em->flush();

            $this->addFlash('success', 'Etiket eklendi.');
        }

        return $this->redirectToRoute('admin_blog_tag_index');
    }

    #[Route('/{id}/sil', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(BlogTag $tag, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete-tag-' . $tag->getId(), $request->request->get('_token'))) {
            $em->remove($tag);
            $em->flush();
            $this->addFlash('success', 'Etiket silindi.');
        }

        return $this->redirectToRoute('admin_blog_tag_index');
    }
}