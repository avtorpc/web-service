<?php
declare(strict_types=1);
namespace App\Controller;
use App\ApiClient\VerificationClient;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
final class RegistrationController {
 public function __construct(private VerificationClient $verification,private HttpClientInterface $http,
  #[Autowire('%env(AUTH_SERVICE_URL)%')] private string $authUrl,
  #[Autowire('%env(WEB_SERVICE_INTERNAL_TOKEN)%')] private string $internalToken, private \Psr\Log\LoggerInterface $logger, private \App\Session\AuthSession $authSession) {}
 #[Route('/registration/{action}',requirements:['action'=>'start|verify|resend|cancel|status|login'],methods:['POST'])]
 public function __invoke(Request $request,string $action): JsonResponse {
  $session=$request->getSession();$csrf=$session->get('registration_csrf');
  if(!is_string($csrf)||!hash_equals($csrf,(string)$request->headers->get('X-CSRF-Token')))return new JsonResponse(['success'=>false,'error'=>['code'=>'CSRF','message'=>'Обновите страницу и повторите запрос.']],403);
  if($this->authSession->isAuthenticated($session))return new JsonResponse(['success'=>false,'error'=>['code'=>'ALREADY_AUTHENTICATED','message'=>'Вы уже вошли в аккаунт. Для другого аккаунта сначала выйдите.']],409,['Cache-Control'=>'no-store']);
  try{
   $p=$request->toArray();
   if($action==='login'){
    $response=$this->http->request('POST',rtrim($this->authUrl,'/').'/sign-in',['headers'=>['X-Web-Client-IP'=>$request->getClientIp(),'X-Web-Service-Token'=>$this->internalToken],'json'=>['login'=>$p['email']??null,'password'=>$p['password']??null,'userAgent'=>$request->headers->get('User-Agent')],'timeout'=>5,'max_duration'=>10,'max_redirects'=>0]);
   }else{
    $id=$session->get('registration_request');
    if($action==='start'&&is_string($id)){
     $existing=$this->verification->status(['requestId'=>$id])->toArray(false);
     // A completed registration must not bypass email uniqueness for a new form submission.
     // Pending requests still go through verification, which checks their email and role.
     if(in_array($existing['status']??null,['ready','conflict','expired','cancelled'],true))$id=null;
    }
    if($action==='start'){
     if(!is_string($id)){$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);$id=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);$session->set('registration_request',$id);}
     $p['requestId']=$id;
    }
    if($action!=='start'){
     if(!is_string($id))return new JsonResponse(['success'=>false,'error'=>['code'=>'NO_REQUEST','message'=>'Начните регистрацию.']],400);
     $p=['requestId'=>$id,'verificationCode'=>$p['verificationCode']??null];
    }
    $response=match($action){'start'=>$this->verification->register($p),'verify'=>$this->verification->verifyEmail($p),'resend'=>$this->verification->resendEmail($p),'cancel'=>$this->verification->cancel($p),'status'=>$this->verification->status($p)};
   }
   $status=$response->getStatusCode();$body=$response->toArray(false);
   if($status<300&&($body['success']??false)){
    if($action==='start')$session->set('registration_request',$body['requestId']);
    if($action==='cancel')$session->remove('registration_request');
    if($action==='login'){
     // Tokens remain on the server, never in browser storage or JavaScript responses.
     $session->migrate(true);$session->set('auth_tokens',$body['data']);$session->set('auth_email',strtolower(trim((string)($p['email']??''))));$session->remove('registration_request');
     $body=['success'=>true,'message'=>'Вход выполнен.','redirect'=>'/cabinet'];
    }
   }
   return new JsonResponse($body,$status,['Cache-Control'=>'no-store']);
  }catch(\Throwable $e){$this->logger->error('Registration request failed', ['exceptionClass'=>$e::class]);return new JsonResponse(['success'=>false,'error'=>['code'=>'SERVICE_UNAVAILABLE','message'=>'Сервис временно недоступен. Повторите запрос позже.']],503,['Cache-Control'=>'no-store']);}
 }
}
