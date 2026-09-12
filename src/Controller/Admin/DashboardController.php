<?php

namespace App\Controller\Admin;

use App\Entity\BlogPost;
use App\Repository\BlogPostRepository;
use App\Repository\MediaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'dashboard')]
    public function index(BlogPostRepository $postRepository, MediaRepository $mediaRepository): Response
    {
        $totalPosts = $postRepository->count([]);
        $draftPosts = $postRepository->count(['status' => BlogPost::STATUS_DRAFT]);
        $totalMedia = $mediaRepository->count([]);

        return $this->render('admin/dashboard.html.twig', [
            'totalPosts' => $totalPosts,
            'draftPosts' => $draftPosts,
            'totalMedia' => $totalMedia,
        ]);
    }
}
