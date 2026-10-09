<?php

declare(strict_types=1);
namespace App\Controller;
use App\ApiClient\{ChatClient,CatalogClient};
use App\Session\AuthSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
final class ChatController extends AbstractController
{
 public function __construct(private ChatClient $chat,private CatalogClient $catalog,private AuthSession $auth){}
 #[Route('/cabinet/chats',methods:['GET'])]
 public function page(Request $r):Response {
  $s=$r->getSession();if(!$this->auth->isAuthenticated($s))return $this->redirect('/?login=1');
  if(!$s->has('registration_csrf'))$s->set('registration_csrf',bin2hex(random_bytes(32)));
  $role=null;$error=null;try{$role=$this->catalog->request('GET','workspace')->toArray()['data']['role'];if($role==='employer'&&$r->query->has('vacancy')){$id=$r->query->get('vacancy');if(!is_string($id)||!preg_match('/^[0-9a-f-]{36}$/Di',$id))throw new \RuntimeException();$this->catalog->request('GET','vacancies/'.$id)->toArray();}}catch(\Throwable){$error='Не удалось открыть переписку. Попробуйте позже.';}
  $response=$this->render('cabinet/chats.html.twig',['role'=>$role,'chat_error'=>$error,'authenticated'=>true,'auth_email'=>$s->get('auth_email'),'auth_expires_at'=>$this->auth->expiresAt($s),'registration_csrf'=>$s->get('registration_csrf')]);$response->headers->set('Cache-Control','private, no-store');return $response;
 }
 #[Route('/cabinet/chat-api/{path}',requirements:['path'=>'bootstrap|list|open|apply|apply-chat|context/[0-9a-f-]{36}|application/[0-9a-f-]{36}|conversations/[0-9a-f-]{36}/(messages|read|favorite)'],methods:['GET','POST'])]
 public function api(Request $r,string $path):JsonResponse {
  $s=$r->getSession();if(!$this->auth->isAuthenticated($s))return $this->result(['success'=>false,'error'=>['message'=>'Войдите в аккаунт.']],401);
  $mutating=$path==='open'||$path==='apply'||$path==='apply-chat'||str_starts_with($path,'application/')||str_ends_with($path,'/read')||str_ends_with($path,'/favorite')||($r->isMethod('POST')&&str_ends_with($path,'/messages'));
  if($r->getMethod()!==($mutating?'POST':'GET'))return $this->result(['success'=>false],405);
  if($mutating){$csrf=$s->get('registration_csrf');if(!is_string($csrf)||!hash_equals($csrf,(string)$r->headers->get('X-CSRF-Token')))return $this->result(['success'=>false,'error'=>['message'=>'Обновите страницу.']],403);}
  try {
   if($path==='bootstrap')return $this->result(['success'=>true,'data'=>$this->chat->credentials()]);
   if($path==='list'){
    $apps=[];$page=1;do{$data=$this->catalog->request('GET','applications',query:['page'=>$page])->toArray()['data'];$apps=array_merge($apps,$data['items']);$page++;}while($page<=$data['pages']);
    $conversations=$this->chat->request('GET','conversations')->toArray()['data'];$byApp=array_column($conversations,null,'application_id');
    foreach($apps as &$app)$app['conversation']=$byApp[$app['id']]??null;unset($app);
    usort($apps,fn($a,$b)=>strcmp($b['conversation']['updated_at']??$b['updated_at'],$a['conversation']['updated_at']??$a['updated_at']));
    return $this->result(['success'=>true,'data'=>$apps]);
   }
   if($path==='apply-chat'){
    $application=$this->catalog->request('POST','applications/start-chat',$r->toArray());if($application->getStatusCode()!==200)return $this->result($application->toArray(false),$application->getStatusCode());
    $app=$application->toArray()['data'];$conversation=$this->chat->request('POST','conversations',['applicationId'=>$app['id']]);if($conversation->getStatusCode()>=400)return $this->result($conversation->toArray(false),$conversation->getStatusCode());
    return $this->result(['success'=>true,'data'=>['applicationId'=>$app['id'],'conversationId'=>$conversation->toArray()['data']['id'],'redirect'=>'/cabinet/chats?application='.$app['id']]]);
   }
   if($path==='apply'){$response=$this->catalog->request('POST','applications',$r->toArray());return $this->result($response->toArray(false),$response->getStatusCode());}
   if(str_starts_with($path,'context/'))$response=$this->catalog->request('GET','applications/'.substr($path,8).'/chat-context');
   elseif(str_starts_with($path,'application/'))$response=$this->catalog->request('PATCH','applications/'.substr($path,12),$r->toArray());
   else $response=$this->chat->request($r->getMethod(),$path==='open'?'conversations':$path,$mutating?$r->toArray():null,$mutating?[]:['after'=>$r->query->get('after','0')]);
   return $this->result($response->toArray(false),$response->getStatusCode());
  }catch(HttpExceptionInterface $e){return $this->result(['success'=>false,'error'=>['message'=>$e->getMessage()]],$e->getStatusCode());}
  catch(\Throwable){return $this->result(['success'=>false,'error'=>['message'=>'Чат временно недоступен. Введённый текст сохранён в форме.']],503);}
 }
 private function result(array $data,int $status=200):JsonResponse{return new JsonResponse($data,$status,['Cache-Control'=>'private, no-store']);}
}
