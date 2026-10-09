<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Session\AuthSession;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function token(int $expiry): string { return 'header.'.base64_encode(json_encode(['exp'=>$expiry])).'.signature'; }
$session=new Session(new MockArraySessionStorage());$calls=0;
$http=new MockHttpClient(function($method,$url,$options) use(&$calls) {
 $calls++;check($method==='POST' && $url==='http://auth/refresh','Incorrect refresh endpoint');
 check(json_decode($options['body'],true)['refreshToken']===str_repeat('a',128),'Incorrect refresh credential');
 return new MockResponse(json_encode(['success'=>true,'data'=>['accessToken'=>token(time()+300),'refreshToken'=>str_repeat('b',128)]]));
});
$auth=new AuthSession($http,'http://auth');
check(!$auth->isAuthenticated($session),'Guest accepted');
$session->set('registration_request','ready');check(!$auth->isAuthenticated($session),'Registration implies login');
$session->set('auth_tokens',['accessToken'=>token(time()+300),'refreshToken'=>str_repeat('a',128)]);
check($auth->isAuthenticated($session) && $calls===0,'Unnecessary refresh');
$session->set('auth_email','test@example.invalid');$session->set('auth_tokens',['accessToken'=>token(time()-3600),'refreshToken'=>str_repeat('a',128)]);
check($auth->isAuthenticated($session) && $calls===1,'Idle session not renewed');
check($session->get('auth_tokens')['refreshToken']===str_repeat('b',128) && $session->has('auth_email'),'Rotation or identity lost');
check($auth->isAuthenticated($session) && $calls===1,'Sequential request refreshed again');
$failed=new AuthSession(new MockHttpClient(new MockResponse('{}',['http_code'=>503])),'http://auth');
$session->set('auth_tokens',['accessToken'=>token(time()-1),'refreshToken'=>str_repeat('b',128)]);
try{$failed->isAuthenticated($session);throw new RuntimeException('Outage silently accepted');}catch(\Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException){}
check($session->has('auth_tokens') && $session->has('auth_email'),'Outage logged user out');
$revoked=new AuthSession(new MockHttpClient(new MockResponse('{}',['http_code'=>400])),'http://auth');
check(!$revoked->isAuthenticated($session) && !$session->has('auth_email'),'Revoked refresh accepted');
$session->set('auth_tokens',['accessToken'=>'invalid']);check(!$auth->isAuthenticated($session),'Malformed token accepted');
$session->set('auth_tokens',['accessToken'=>token(time()+300),'refreshToken'=>str_repeat('c',128)]);
$logout=new AuthSession(new MockHttpClient(function($method,$url,$options){check($url==='http://auth/logout','Missing server revocation');check(json_decode($options['body'],true)['refreshToken']===str_repeat('c',128),'Wrong logout credential');return new MockResponse('{"success":true}');}),'http://auth');
$logout->logout($session);check(!$session->has('auth_tokens'),'Logout retained credentials');
echo "PASS: idle refresh, rotation, outage retains session, revoked refresh rejected, logout revokes and destroys session\n";
