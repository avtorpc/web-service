(() => {
 const errorPage=document.querySelector('#taskError'),retryButton=document.querySelector('#retryTaskPage'),retryMessage=document.querySelector('#retryTaskMessage');
 retryButton?.addEventListener('click',async()=>{
  retryButton.disabled=true;retryMessage.textContent='Запускаем повторную генерацию…';retryMessage.dataset.error='false';
  try{const response=await fetch(`/cabinet/tests/${errorPage.dataset.taskId}/retry-generation`,{method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name="registration-csrf"]').content},credentials:'same-origin'});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.error?.message||'Не удалось запустить повторную генерацию.');retryMessage.textContent='Повторная генерация запущена. Открываем страницу подготовки…';setTimeout(()=>location.reload(),500);}
  catch(error){retryButton.disabled=false;retryMessage.textContent=error.message;retryMessage.dataset.error='true';}
 });
 const page=document.querySelector('#taskPage');if(!page)return;
 const id=page.dataset.taskId,csrf=document.querySelector('meta[name="registration-csrf"]').content,message=document.querySelector('#taskMessage');
 let busy=false;
 async function request(action,payload){
  const response=await fetch(`/cabinet/tests/${id}/${action}`,{method:payload?'POST':'GET',headers:payload?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:payload?JSON.stringify(payload):undefined,credentials:'same-origin'});
  if(response.status===401){location.assign('/?login=1');throw new Error('Войдите в аккаунт.');}
  const result=await response.json();if(!response.ok||!result.success)throw new Error(result.error?.message||'Не удалось выполнить действие.');return result.data;
 }
 async function mutate(action,reload=false){
  if(busy)return;busy=true;page.querySelectorAll('button').forEach(b=>b.disabled=true);message.textContent='Сохраняем…';
  try{await request(action,['answer','submit'].includes(action)?{text:document.querySelector('#candidateAnswer').value}:{});if(reload)location.reload();else message.textContent='Ответ сохранён. Можно вернуться к заданию из кабинета.';}
  catch(error){message.textContent=error.message;message.dataset.error='true';}
  finally{busy=false;page.querySelectorAll('button').forEach(b=>b.disabled=false);}
 }
 document.querySelector('#saveAnswer')?.addEventListener('click',()=>mutate('answer'));
 document.querySelector('#taskAnswerForm')?.addEventListener('submit',event=>{event.preventDefault();mutate('submit',true);});
 document.querySelector('#retryGeneration')?.addEventListener('click',()=>mutate('retry-generation',true));
 document.querySelector('#retryEvaluation')?.addEventListener('click',()=>mutate('retry-evaluation',true));
 if(page.dataset.taskStatus==='generating'||page.dataset.attemptStatus==='evaluating'){
  const progressBar=document.querySelector('#taskProgressBar'),progressValue=document.querySelector('#taskProgressValue'),progressMessage=document.querySelector('#taskProgressMessage');
  const startedAt=Date.parse(page.dataset.taskCreated||'')||Date.now(),estimatedDuration=90000;
  function renderProgress(){
   if(!progressBar)return;
   const elapsed=Date.now()-startedAt,percent=Math.min(90,Math.max(10,Math.round(10+(elapsed/estimatedDuration)*80)));
   progressBar.style.width=`${percent}%`;progressValue.textContent=`${percent}%`;
   if(progressMessage)progressMessage.textContent=percent<45?'Формируем условия и критерии…':percent<75?'GigaChat подготавливает содержание задания…':'Проверяем и сохраняем результат…';
  }
  renderProgress();
  const progressTimer=setInterval(renderProgress,1000);
  async function poll(){
   try{const task=await request('data');if(task.status!==page.dataset.taskStatus||(task.attempt?.status||'in_progress')!==page.dataset.attemptStatus){clearInterval(progressTimer);if(task.status==='ready'&&progressBar){progressBar.style.width='100%';progressValue.textContent='100%';if(progressMessage)progressMessage.textContent='Задание готово. Открываем…';setTimeout(()=>location.reload(),500);}else location.reload();return;}message.textContent='';}
   catch(error){message.textContent=error.message;}
   setTimeout(poll,4000);
  }
  setTimeout(poll,3000);
 }
})();
