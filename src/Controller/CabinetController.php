<?php

declare(strict_types=1);
namespace App\Controller;
use App\ApiClient\CatalogClient;
use App\Session\AuthSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse,RedirectResponse};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
final class CabinetController extends AbstractController
{
 public function __construct(private CatalogClient $catalog,private AuthSession $auth) {}
 #[Route('/cabinet',name:'cabinet',methods:['GET'])]
 public function index(Request $request):Response {
  $session=$request->getSession();
  if(!$this->auth->isAuthenticated($session))return $this->loginRedirect();
  if(!$session->has('registration_csrf'))$session->set('registration_csrf',bin2hex(random_bytes(32)));
  $context=['authenticated'=>true,'auth_email'=>$session->get('auth_email'),'auth_expires_at'=>$this->auth->expiresAt($session),'registration_csrf'=>$session->get('registration_csrf'),'workspace'=>null,'assessment'=>null,'preferences'=>[],'cabinet_error'=>null,'assessment_error'=>null];
  $status=200;
  try{
   $response=$this->catalog->request('GET','workspace',query:['testsPage'=>max(1,$request->query->getInt('testsPage',1))]);
   if($response->getStatusCode()===401){$session->remove('auth_tokens');return $this->loginRedirect();}
   $context['workspace']=$response->toArray()['data'];
   if($context['workspace']['role']==='applicant'){
    $context['preferences']=$context['workspace']['profile']['assessment_preferences']??[];
    try{
     $query=isset($context['preferences']['specializationId'])?['specializationId'=>$context['preferences']['specializationId']]:[];
     $context['assessment']=$this->catalog->request('GET','assessment/options',query:$query)->toArray()['data'];
    }catch(\Throwable){$context['assessment_error']='Не удалось загрузить направления тестирования. Обновите страницу позже.';}
   }
  }catch(\Throwable){$context['cabinet_error']='Личный кабинет временно недоступен. Попробуйте открыть его ещё раз.';$status=503;}
  $context['assessment_json']=json_encode(['options'=>$context['assessment'],'preferences'=>$context['preferences']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
  $response=$this->render('cabinet/index.html.twig',$context);$response->setStatusCode($status);$response->headers->set('Cache-Control','private, no-store');return $response;
 }
 #[Route('/user.html',methods:['GET'],priority:20)]
 #[Route('/employer.html',methods:['GET'],priority:20)]
 public function legacy():Response {return new RedirectResponse('/cabinet',302,['Cache-Control'=>'private, no-store']);}
 #[Route('/cabinet/{action}',requirements:['action'=>'data|options|profile|preferences|resumes'],methods:['GET','POST'])]
 public function api(Request $request,string $action):JsonResponse {
  $session=$request->getSession();
  if(!$this->auth->isAuthenticated($session))return new JsonResponse(['success'=>false,'error'=>['code'=>'AUTH_REQUIRED','message'=>'Войдите в аккаунт.']],401,['Cache-Control'=>'no-store']);
  $mutation=in_array($action,['profile','preferences','resumes'],true)&&$request->getMethod()==='POST';
  if(($mutation&&$request->getMethod()!=='POST')||(!$mutation&&$request->getMethod()!=='GET'))return new JsonResponse(['success'=>false],405,['Allow'=>$mutation?'POST':'GET','Cache-Control'=>'no-store']);
  if($mutation){$csrf=$session->get('registration_csrf');if(!is_string($csrf)||!hash_equals($csrf,(string)$request->headers->get('X-CSRF-Token')))return new JsonResponse(['success'=>false,'error'=>['code'=>'CSRF','message'=>'Обновите страницу.']],403,['Cache-Control'=>'no-store']);}
  try{
   $body=$mutation?$request->toArray():null;
   [$method,$path]=match($action){'data'=>['GET','workspace'],'options'=>['GET','assessment/options'],'profile'=>['PATCH','profile'],'preferences'=>['POST','assessment/preferences'],'resumes'=>[$request->getMethod(),'resumes']};
   $query=$action==='options'&&$request->query->has('specializationId')?['specializationId'=>$request->query->getInt('specializationId')]:[];
   $response=$this->catalog->request($method,$path,$body,$query);$status=$response->getStatusCode();
   if($status===401)$session->remove('auth_tokens');
   return new JsonResponse($response->toArray(false),$status,['Cache-Control'=>'private, no-store']);
  }catch(HttpExceptionInterface $e){return new JsonResponse(['success'=>false,'error'=>['message'=>$e->getMessage()]],$e->getStatusCode(),['Cache-Control'=>'no-store']);}
  catch(\Throwable){return new JsonResponse(['success'=>false,'error'=>['code'=>'SERVICE_UNAVAILABLE','message'=>'Не удалось сохранить данные. Попробуйте позже.']],503,['Cache-Control'=>'no-store']);}
 }
 #[Route('/cabinet/resumes/{id}/pdf',requirements:['id'=>'[0-9a-f-]{36}'],methods:['POST'])]
 public function uploadResumePdf(Request $request,string $id):JsonResponse { $session=$request->getSession();if(!$this->auth->isAuthenticated($session))return new JsonResponse(['success'=>false],401);$csrf=$session->get('registration_csrf');if(!is_string($csrf)||!hash_equals($csrf,(string)$request->headers->get('X-CSRF-Token')))return new JsonResponse(['success'=>false,'error'=>['message'=>'Обновите страницу.']],403);try{$response=$this->catalog->upload('resumes/'.$id.'/pdf',$request->files->get('file'));return new JsonResponse($response->toArray(false),$response->getStatusCode(),['Cache-Control'=>'no-store']);}catch(\Throwable $e){return new JsonResponse(['success'=>false,'error'=>['message'=>$e->getMessage()]],400);}}
 #[Route('/cabinet/resumes/{id}/pdf',requirements:['id'=>'[0-9a-f-]{36}'],methods:['GET'])]
 public function downloadResumePdf(Request $request,string $id):Response {if(!$this->auth->isAuthenticated($request->getSession()))return $this->loginRedirect();try{$payload=$this->catalog->download('resumes/'.$id.'/pdf')->toArray();$data=$payload['data']??[];$content=base64_decode((string)($data['content']??''),true);if($content===false||$content==='')return new Response('PDF не найден.',404,['Content-Type'=>'text/plain; charset=UTF-8']);$response=new Response($content,200,['Content-Type'=>(string)($data['mime']??'application/pdf'),'Content-Disposition'=>'inline; filename="'.addslashes((string)($data['name']??'resume.pdf')).'"','Cache-Control'=>'private, no-store']);return $response;}catch(\Throwable){return new Response('PDF не найден.',404,['Content-Type'=>'text/plain; charset=UTF-8']);}}
 private function loginRedirect():Response {return new RedirectResponse('/?login=1',302,['Cache-Control'=>'private, no-store']);}
}
