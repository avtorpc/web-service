<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private \App\Session\AuthSession $authSession, private \App\ApiClient\CatalogClient $catalog) {}
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
        $data = []; $status = 200;
        if ($page === 'index' || $page === 'vacancy') {
            $data = ['catalog_error'=>null,'public_vacancy'=>null];
            $records = [];
            try {
                if ($page === 'index') $records = $this->catalog->publicRequest('public/vacancies')->toArray()['data'];
                else {
                    $id = $request->query->get('id', '');
                    if (!is_string($id) || !preg_match('/^[0-9a-f-]{36}$/Di', $id)) { $status = 404; }
                    else {
                        $result = $this->catalog->publicRequest('public/vacancies/'.$id);
                        if ($result->getStatusCode() === 404) $status = 404;
                        else $data['public_vacancy'] = $result->toArray()['data'];
                    }
                }
            } catch (\Throwable) { $data['catalog_error'] = 'Не удалось загрузить вакансии. Обновите страницу позже.'; $status = 503; }
            $data['catalog_json'] = json_encode(['vacancies'=>$records,'error'=>$data['catalog_error']], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
        }
        $response = $this->render('prototype/'.$page.'.html.twig', $data + ['registration_csrf' => $session->get('registration_csrf'), 'authenticated' => $this->authSession->isAuthenticated($session), 'auth_email' => $session->get('auth_email'), 'auth_expires_at' => $this->authSession->expiresAt($session)]);
        $response->setStatusCode($status);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
