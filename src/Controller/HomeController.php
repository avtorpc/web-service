<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('prototype/index.html.twig');
    }

    // Explicit whitelist: never resolve arbitrary user input as a template path.
    #[Route('/{page}.html', name: 'prototype_page', requirements: ['page' => 'index|vacancy|vacancy-create|resume|resume-owner|user|employer'], methods: ['GET'])]
    public function prototype(string $page): Response
    {
        return $this->render('prototype/'.$page.'.html.twig');
    }
}
