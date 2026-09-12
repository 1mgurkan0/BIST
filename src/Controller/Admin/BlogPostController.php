<?php

namespace App\Controller\Admin;

use App\Entity\BlogPost;
use App\Form\BlogPostType;
use App\Repository\BlogPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use App\Repository\MediaRepository;

#[Route('/admin/yazilar', name: 'admin_blog_post_')]
#[IsGranted('ROLE_ADMIN')]
class BlogPostController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(BlogPostRepository $repository): Response
    {
        $posts = $repository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/blog_post/index.html.twig', [
            'posts' => $posts,
        ]);
    }

    #[Route('/yeni', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger, MediaRepository $mediaRepository): Response
    {
        $post = new BlogPost();
        $form = $this->createForm(BlogPostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applySlug($post, $slugger);

            if ($post->getStatus() === BlogPost::STATUS_PUBLISHED && !$post->getPublishedAt()) {
                $post->setPublishedAt(new \DateTimeImmutable());
            }

            $em->persist($post);
            $em->flush();

            $this->addFlash('success', 'Yazı oluşturuldu.');

            return $this->redirectToRoute('admin_blog_post_index');
        }

        return $this->render('admin/blog_post/form.html.twig', [
            'form' => $form,
            'post' => $post,
            'is_new' => true,
            'mediaItems' => $mediaRepository->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/{id}/duzenle', name: 'edit', requirements: ['id' => '\d+'])]
    public function edit(BlogPost $post, Request $request, EntityManagerInterface $em, SluggerInterface $slugger, MediaRepository $mediaRepository): Response
    {
        $form = $this->createForm(BlogPostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applySlug($post, $slugger);
            $post->setUpdatedAt(new \DateTimeImmutable());

            if ($post->getStatus() === BlogPost::STATUS_PUBLISHED && !$post->getPublishedAt()) {
                $post->setPublishedAt(new \DateTimeImmutable());
            }

            $em->flush();

            $this->addFlash('success', 'Yazı güncellendi.');

            return $this->redirectToRoute('admin_blog_post_index');
        }

        return $this->render('admin/blog_post/form.html.twig', [
            'form' => $form,
            'post' => $post,
            'is_new' => false,
            'mediaItems' => $mediaRepository->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/{id}/sil', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(BlogPost $post, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete-post-' . $post->getId(), $request->request->get('_token'))) {
            $em->remove($post);
            $em->flush();
            $this->addFlash('success', 'Yazı silindi.');
        }

        return $this->redirectToRoute('admin_blog_post_index');
    }

    private function applySlug(BlogPost $post, SluggerInterface $slugger): void
    {
        if (empty($post->getSlug())) {
            $post->setSlug((string) $slugger->slug($post->getTitle())->lower());
        } else {
            $post->setSlug((string) $slugger->slug($post->getSlug())->lower());
        }
    }
}