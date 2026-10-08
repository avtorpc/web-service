<?php
declare(strict_types=1);
namespace App\Controller;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;

final class LogoutController
{
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        $csrf = $session->get('registration_csrf');
        if (!is_string($csrf) || !hash_equals($csrf, (string) $request->request->get('_csrf_token'))) {
            return new Response('Обновите страницу и повторите выход.', 403, ['Cache-Control' => 'no-store']);
        }
        // Destroy the session containing tokens; the browser never receives them.
        $session->invalidate();
        return new RedirectResponse('/', 303, ['Cache-Control' => 'no-store']);
    }
}
