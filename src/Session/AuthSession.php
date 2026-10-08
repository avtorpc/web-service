<?php
declare(strict_types=1);
namespace App\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class AuthSession
{
    public function expiresAt(SessionInterface $session): int
    {
        $tokens = $session->get('auth_tokens');
        if (!is_array($tokens) || !is_string($tokens['accessToken'] ?? null)) return 0;
        // Only inspect the token received from auth-service and kept in our server session.
        // This does not authenticate any token supplied by the browser.
        $parts = explode('.', $tokens['accessToken']);
        $claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')) ?: '{}', true);
        return is_array($claims) ? (int) ($claims['exp'] ?? 0) : 0;
    }

    public function isAuthenticated(SessionInterface $session): bool
    {
        if ($this->expiresAt($session) > time()) return true;
        $session->remove('auth_tokens');
        $session->remove('auth_email');
        return false;
    }
}
