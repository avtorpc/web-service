<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(__DIR__.'/../templates'),['autoescape'=>'html','strict_variables'=>true]);
$request=Symfony\Component\HttpFoundation\Request::create('/cabinet/tests/1');$request->attributes->set('_route','assessment_task');
$twig->addGlobal('app',(object)['request'=>$request]);$twig->addFunction(new Twig\TwigFunction('path',fn(string $route)=>$route==='logout'?'/logout':'/'));
$context=['task_id'=>1,'task_error'=>null,'authenticated'=>true,'auth_email'=>'fixture@example.invalid','auth_expires_at'=>time()+600,'registration_csrf'=>'test-csrf'];
$task=['id'=>1,'status'=>'ready','created_at'=>'2026-10-08T00:00:00Z','selection'=>['specialization_name'=>'Backend','declared_grade_name'=>'Junior'],
 'assignment'=>['title'=>'<script>alert(1)</script>','text'=>'<script>alert(2)</script>','deliverables'=>['Код'],'constraints'=>[]],
 'criteria'=>[['name'=>'Критерий','competency_name'=>'API','checks'=>[['condition'=>'<img src=x onerror=alert(3)>','expected_evidence'=>['Код']]]]],'attempt'=>null];
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$html=$twig->render('cabinet/task.html.twig',$context+['task'=>$task]);
check(!str_contains($html,'<script>alert(')&&!str_contains($html,'<img src=x'),'Model text rendered as HTML');
check(str_contains($html,'aria-readonly="true"')&&str_contains($html,'id="candidateAnswer"'),'Condition/answer fields missing');
check(str_contains($html,'Отправить на оценку'),'Ready task cannot be submitted');
$task['status']='generating';$html=$twig->render('cabinet/task.html.twig',$context+['task'=>$task]);
check(str_contains($html,'id="taskWaiting"')&&!str_contains($html,'id="candidateAnswer"'),'Generating task exposes answer form');$task['status']='ready';
$task['attempt']=['status'=>'evaluating','answer'=>['text'=>'<script>candidate</script>']];
$html=$twig->render('cabinet/task.html.twig',$context+['task'=>$task]);
check(str_contains($html,'id="candidateAnswer" rows="16" maxlength="30000" readonly'),'Submitted answer is editable');
check(!str_contains($html,'<script>candidate')&&!str_contains($html,'Отправить на оценку'),'Submission/escaping boundary wrong');
$task['status']='generation_failed';$task['error']='Technical error';$task['attempt']=null;
$html=$twig->render('cabinet/task.html.twig',$context+['task'=>$task]);
check(str_contains($html,'id="retryGeneration"')&&!str_contains($html,'id="candidateAnswer"'),'Failed generation exposes answer form');
echo "PASS: ready/waiting/error template states, immutable conditions and submitted answer, model/candidate HTML escaping\n";
