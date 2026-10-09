<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiClient\CatalogClient;
use App\Session\AuthSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;

final class VacancyController extends AbstractController
{
    public function __construct(private CatalogClient $catalog, private AuthSession $auth) {}

    #[Route('/cabinet/vacancies/new', methods: ['GET'])]
    #[Route('/cabinet/vacancies/{id}/edit', methods: ['GET'])]
    public function edit(Request $request, ?string $id = null): Response
    {
        $session = $request->getSession();
        if (!$this->auth->isAuthenticated($session)) return new RedirectResponse('/?login=1', 302, ['Cache-Control'=>'private, no-store']);
        if (!$session->has('registration_csrf')) $session->set('registration_csrf', bin2hex(random_bytes(32)));
        $context = ['authenticated'=>true, 'auth_email'=>$session->get('auth_email'), 'auth_expires_at'=>$this->auth->expiresAt($session), 'registration_csrf'=>$session->get('registration_csrf'), 'vacancy'=>null, 'profile'=>null, 'error'=>null];
        $status = 200;
        try {
            $response = $this->catalog->request('GET', 'workspace');
            if ($response->getStatusCode() === 401) {
                $session->remove('auth_tokens');
                return new RedirectResponse('/?login=1');
            }
            $workspace = $response->toArray()['data'];
            if ($workspace['role'] !== 'employer') throw $this->createAccessDeniedException('Создание вакансий доступно работодателю.');
            $context['profile'] = $workspace['profile'];
            if ($id !== null) {
                $response = $this->catalog->request('GET', 'vacancies/'.rawurlencode($id));
                $status = $response->getStatusCode();
                if ($status !== 200) {
                    $context['error'] = $status === 404 ? 'Вакансия не найдена.' : 'Не удалось открыть вакансию.';
                } else {
                    $context['vacancy'] = $response->toArray()['data'];
                    if ($context['vacancy']['status'] !== 'draft') {
                        $context['error'] = 'Сейчас можно редактировать только черновики.';
                        $status = 409;
                    }
                }
            }
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            throw $e;
        } catch (\Throwable) {
            $context['error'] = 'Вакансии временно недоступны. Попробуйте позже.';
            $status = 503;
        }
        $response = $this->render('cabinet/vacancy.html.twig', $context)->setStatusCode($status);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
