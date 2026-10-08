(() => {
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
  async function poll(){
   try{const task=await request('data');if(task.status!==page.dataset.taskStatus||(task.attempt?.status||'in_progress')!==page.dataset.attemptStatus){location.reload();return;}message.textContent='';}
   catch(error){message.textContent=error.message;}
   setTimeout(poll,4000);
  }
  setTimeout(poll,3000);
 }
})();
