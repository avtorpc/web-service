<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Session\AuthSession;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$session = new Session(new MockArraySessionStorage());
$auth = new AuthSession();
check(!$auth->isAuthenticated($session), 'Guest marked authenticated');
$session->set('registration_request', 'confirmed-but-not-logged-in');
check(!$auth->isAuthenticated($session), 'Registration implies login');
$session->set('auth_tokens', ['accessToken' => 'header.'.base64_encode(json_encode(['exp' => time()+300])).'.signature']);
check($auth->isAuthenticated($session), 'Active server token ignored');
$session->set('auth_email', 'test@example.invalid');
$session->set('auth_tokens', ['accessToken' => 'header.'.base64_encode(json_encode(['exp' => time()-1])).'.signature']);
check(!$auth->isAuthenticated($session), 'Expired session accepted');
check(!$session->has('auth_tokens') && !$session->has('auth_email'), 'Expired credentials retained');
$session->set('auth_tokens', ['accessToken' => 'invalid']);
check(!$auth->isAuthenticated($session), 'Malformed token accepted');
echo "Guest, registration without login, active token and expired session checks passed\n";
