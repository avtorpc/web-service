<?php

declare(strict_types=1);
namespace App\ApiClient;
use Symfony\Contracts\HttpClient\{HttpClientInterface,ResponseInterface};
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
final class ChatClient
{
 public function __construct(private HttpClientInterface $http,private RequestStack $requests,private CatalogClient $catalog,
 #[Autowire('%env(NODE_SERVICE_URL)%')]private string $baseUrl,#[Autowire('%env(CHAT_TOKEN_SECRET)%')]private string $secret){}
 public function credentials():array {
  $session=$this->requests->getSession();$access=$session->get('auth_tokens')['accessToken']??null;
  if(!is_string($access))throw new UnauthorizedHttpException('Bearer','Войдите в аккаунт.');
  $workspace=$this->catalog->request('GET','workspace')->toArray()['data'];
  $access=$session->get('auth_tokens')['accessToken'];
  $parts=explode('.',$access);$claims=json_decode(base64_decode(strtr($parts[1]??'','-_','+/'))?:'{}',true);
  $exp=min(time()+120,(int)($claims['exp']??0));if($exp<=time())throw new UnauthorizedHttpException('Bearer','Войдите в аккаунт.');
  $id=$workspace['profile']['user_uuid'];$role=$workspace['role'];
  $encode=fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
  $head=$encode(json_encode(['alg'=>'HS256','typ'=>'JWT'],JSON_THROW_ON_ERROR));$body=$encode(json_encode(['iss'=>'web-service','aud'=>'node-chat','sub'=>$id,'role'=>$role,'exp'=>$exp],JSON_THROW_ON_ERROR));
  if(strlen($this->secret)<32)throw new \RuntimeException('Chat secret is not configured');
  return ['token'=>$head.'.'.$body.'.'.$encode(hash_hmac('sha256',$head.'.'.$body,$this->secret,true)),'userId'=>$id,'role'=>$role,'expiresAt'=>$exp];
 }
 public function request(string $method,string $path,?array $body=null,array $query=[]):ResponseInterface {
  $options=['headers'=>['Authorization'=>'Bearer '.$this->credentials()['token']],'timeout'=>5,'max_duration'=>15,'max_redirects'=>0];
  if($method==='POST')$options['headers']['X-Catalog-Token']=$this->requests->getSession()->get('auth_tokens')['accessToken'];
  if($body!==null)$options['json']=$body;if($query)$options['query']=$query;
  return $this->http->request($method,rtrim($this->baseUrl,'/').'/'.$path,$options);
 }
}
