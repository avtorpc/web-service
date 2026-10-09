<?php

declare(strict_types=1);
namespace App\ApiClient;
use Symfony\Contracts\HttpClient\{HttpClientInterface,ResponseInterface};
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
final class CatalogClient
{
 public function __construct(private HttpClientInterface $http,private RequestStack $requests,
  #[Autowire('%env(CATALOG_SERVICE_URL)%')] private string $baseUrl) {}
 public function request(string $method,string $path,?array $body=null,array $query=[]):ResponseInterface {
  $session=$this->requests->getSession();$token=$session->get('auth_tokens')['accessToken']??null;
  if(!is_string($token))throw new UnauthorizedHttpException('Bearer','Войдите в аккаунт.');
  $options=['headers'=>['Authorization'=>'Bearer '.$token],'timeout'=>5,'max_duration'=>15,'max_redirects'=>0];
  if($body!==null)$options['json']=$body;
  if($query)$options['query']=$query;
  return $this->http->request($method,rtrim($this->baseUrl,'/').'/'.$path,$options);
 }
 public function upload(string $path,UploadedFile $file):ResponseInterface { $session=$this->requests->getSession();$token=$session->get('auth_tokens')['accessToken']??null;if(!is_string($token))throw new UnauthorizedHttpException('Bearer','Войдите в аккаунт.');return $this->http->request('POST',rtrim($this->baseUrl,'/').'/'.$path,['headers'=>['Authorization'=>'Bearer '.$token],'body'=>['file'=>fopen($file->getPathname(),'r')],'timeout'=>30,'max_duration'=>45]); }
 public function download(string $path):ResponseInterface { $session=$this->requests->getSession();$token=$session->get('auth_tokens')['accessToken']??null;if(!is_string($token))throw new UnauthorizedHttpException('Bearer','Войдите в аккаунт.');return $this->http->request('GET',rtrim($this->baseUrl,'/').'/'.$path,['headers'=>['Authorization'=>'Bearer '.$token],'timeout'=>30,'max_duration'=>45]); }
}
