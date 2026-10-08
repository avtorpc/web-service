<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private \App\Session\AuthSession $authSession) {}
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request, 'index');
    }

    // Explicit whitelist: never resolve arbitrary user input as a template path.
    #[Route('/{page}.html', name: 'prototype_page', requirements: ['page' => 'index|vacancy|vacancy-create|resume|resume-owner|user|employer'], methods: ['GET'])]
    public function prototype(Request $request, string $page): Response
    {
        return $this->page($request, $page);
    }
    private function page(Request $request, string $page): Response
    {
        $session = $request->getSession();
        if (!$session->has('registration_csrf')) $session->set('registration_csrf', bin2hex(random_bytes(32)));
        $response = $this->render('prototype/'.$page.'.html.twig', ['registration_csrf' => $session->get('registration_csrf'), 'authenticated' => $this->authSession->isAuthenticated($session), 'auth_email' => $session->get('auth_email'), 'auth_expires_at' => $this->authSession->expiresAt($session)]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
