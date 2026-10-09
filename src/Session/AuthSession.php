<?php
declare(strict_types=1);
namespace App\Session;

use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class AuthSession
{
    public function __construct(private HttpClientInterface $http,
        #[Autowire('%env(AUTH_SERVICE_URL)%')] private string $authUrl) {}

    public function expiresAt(SessionInterface $session): int
    {
        $tokens = $session->get('auth_tokens');
        if (!is_array($tokens) || !is_string($tokens['accessToken'] ?? null)) return 0;
        // These claims only inspect a token received from auth-service, stored server-side.
        $parts = explode('.', $tokens['accessToken']);
        $claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')) ?: '{}', true);
        return is_array($claims) ? (int) ($claims['exp'] ?? 0) : 0;
    }

    public function isAuthenticated(SessionInterface $session): bool
    {
        if ($this->expiresAt($session) > time() + 30) return true;
        $tokens = $session->get('auth_tokens');
        $refresh = is_array($tokens) ? ($tokens['refreshToken'] ?? null) : null;
        if (!is_string($refresh) || $refresh === '') return $this->forget($session);
        // Native PHP session locking serializes refreshes across tabs and concurrent requests.
        try {
            $response = $this->http->request('POST', rtrim($this->authUrl, '/').'/refresh', [
                'json' => ['refreshToken' => $refresh], 'timeout' => 5,
                'max_duration' => 10, 'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if (in_array($status, [400, 401, 403], true)) return $this->forget($session);
            if ($status !== 200) throw new \RuntimeException('Refresh unavailable');
            $body = $response->toArray(false);
            if (!($body['success'] ?? false) || !is_string($body['data']['accessToken'] ?? null)
                || !is_string($body['data']['refreshToken'] ?? null)) throw new \RuntimeException('Invalid refresh response');
            $session->set('auth_tokens', $body['data']);
            if ($this->expiresAt($session) <= time()) throw new \RuntimeException('Expired refreshed token');
            return true;
        } catch (\Throwable $e) {
            // A temporary network failure must not erase the user's credentials.
            throw new ServiceUnavailableHttpException(5, 'Не удалось продлить вход. Повторите запрос позже.');
        }
    }

    public function logout(SessionInterface $session): void
    {
        $refresh = $session->get('auth_tokens')['refreshToken'] ?? null;
        if (is_string($refresh)) {
            try {
                $response = $this->http->request('POST', rtrim($this->authUrl, '/').'/logout', [
                    'json' => ['refreshToken' => $refresh], 'timeout' => 5,
                    'max_duration' => 10, 'max_redirects' => 0,
                ]);
                if ($response->getStatusCode() !== 200) throw new \RuntimeException();
            } catch (\Throwable) {
                throw new ServiceUnavailableHttpException(5, 'Не удалось завершить вход. Повторите выход позже.');
            }
        }
        $session->invalidate();
    }

    private function forget(SessionInterface $session): bool
    {
        $session->remove('auth_tokens');
        $session->remove('auth_email');
        return false;
    }
}
