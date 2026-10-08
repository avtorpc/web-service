<?php
declare(strict_types=1);
namespace App\Controller;
use App\ApiClient\CatalogClient;
use App\Session\AuthSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse,RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
final class AssessmentController extends AbstractController
{
    public function __construct(private CatalogClient $catalog,private AuthSession $auth) {}
    #[Route('/cabinet/tests/{id}',name:'assessment_task',requirements:['id'=>'[1-9][0-9]*'],methods:['GET'])]
    public function page(Request $request,int $id): Response
    {
        $session=$request->getSession();
        if(!$this->auth->isAuthenticated($session)) return new RedirectResponse('/?login=1');
        if(!$session->has('registration_csrf')) $session->set('registration_csrf',bin2hex(random_bytes(32)));
        $task=null;$error=null;$status=200;
        try {
            $response=$this->catalog->request('GET','assessment/tests/'.$id);$status=$response->getStatusCode();
            if($status===401) {$session->remove('auth_tokens');return new RedirectResponse('/?login=1');}
            if($status>=400) $error=$response->toArray(false)['error']['message'] ?? ($status===404?'Задание не найдено.':'Задание недоступно.');
            else $task=$response->toArray()['data'];
        } catch(\Throwable) {$status=503;$error='Не удалось загрузить задание. Попробуйте позже.';}
        $response=$this->render('cabinet/task.html.twig',['task'=>$task,'task_id'=>$id,'task_error'=>$error,
            'authenticated'=>true,'auth_email'=>$session->get('auth_email'),'auth_expires_at'=>$this->auth->expiresAt($session),'registration_csrf'=>$session->get('registration_csrf')]);
        $response->setStatusCode($status);$response->headers->set('Cache-Control','private, no-store');return $response;
    }
    #[Route('/cabinet/tests',methods:['POST'])]
    #[Route('/cabinet/tests/{id}/data',requirements:['id'=>'[1-9][0-9]*'],methods:['GET'])]
    #[Route('/cabinet/tests/{id}/{action}',requirements:['id'=>'[1-9][0-9]*','action'=>'answer|submit|retry-generation|retry-evaluation'],methods:['POST'])]
    public function api(Request $request,?int $id=null,?string $action=null): JsonResponse
    {
        $session=$request->getSession();
        if(!$this->auth->isAuthenticated($session)) return $this->error('Войдите в аккаунт.',401);
        if($request->isMethod('POST')) {
            $csrf=$session->get('registration_csrf');
            if(!is_string($csrf)||!hash_equals($csrf,(string)$request->headers->get('X-CSRF-Token')))return $this->error('Обновите страницу.',403);
        }
        try {
            $path='assessment/tests'.($id!==null?'/'.$id:'').($action!==null?'/'.$action:'');
            $response=$this->catalog->request($request->getMethod(),$path,$request->isMethod('POST')?$request->toArray():null);
            $status=$response->getStatusCode();
            if($status===401)$session->remove('auth_tokens');
            $data=$response->toArray(false);
            if(($data['success']??false) && $id===null)$data['data']['redirect']='/cabinet/tests/'.$data['data']['id'];
            return new JsonResponse($data,$status,['Cache-Control'=>'private, no-store']);
        } catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {return $this->error('Проверьте данные запроса.',$e->getStatusCode());}
        catch(\Throwable) {return $this->error('Не удалось выполнить действие. Попробуйте позже.',503);}
    }
    private function error(string $message,int $status): JsonResponse {return new JsonResponse(['success'=>false,'error'=>['message'=>$message]],$status,['Cache-Control'=>'private, no-store']);}
}
