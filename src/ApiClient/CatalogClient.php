<?php

declare(strict_types=1);
namespace App\ApiClient;
use Symfony\Contracts\HttpClient\{HttpClientInterface,ResponseInterface};
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
final class CatalogClient
{
 public function __construct(private HttpClientInterface $http,private RequestStack $requests,
  #[Autowire('%env(CATALOG_SERVICE_URL)%')] private string $baseUrl, private \App\Session\AuthSession $auth) {}
 public function request(string $method,string $path,?array $body=null,array $query=[]):ResponseInterface {
  $session=$this->requests->getSession();if(!$this->auth->isAuthenticated($session))throw new UnauthorizedHttpException('Bearer','Войдите в аккаунт.');$token=$session->get('auth_tokens')['accessToken']??null;
  if(!is_string($token))throw new UnauthorizedHttpException('Bearer','Войдите в аккаунт.');
  $options=['headers'=>['Authorization'=>'Bearer '.$token],'timeout'=>5,'max_duration'=>15,'max_redirects'=>0];
  if($body!==null)$options['json']=$body;
  if($query)$options['query']=$query;
  return $this->http->request($method,rtrim($this->baseUrl,'/').'/'.$path,$options);
 }
 public function publicRequest(string $path):ResponseInterface {
  if(!preg_match('~^public/vacancies(?:/[0-9a-f-]{36})?$~Di',$path))throw new \InvalidArgumentException('Invalid public catalog path');
  return $this->http->request('GET',rtrim($this->baseUrl,'/').'/'.$path,['timeout'=>5,'max_duration'=>15,'max_redirects'=>0]);
 }
}
