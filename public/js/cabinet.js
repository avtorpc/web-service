(() => {
 const csrf=document.querySelector('meta[name="registration-csrf"]')?.content;
 async function request(action,payload,query=''){
  const response=await fetch('/cabinet/'+action+query,{method:payload?'POST':'GET',headers:payload?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:payload?JSON.stringify(payload):undefined,credentials:'same-origin'});
  const result=await response.json();
  if(response.status===401){location.assign('/?login=1');throw new Error('Войдите в аккаунт.');}
  if(!response.ok||!result.success)throw new Error(result.error?.message||'Не удалось выполнить действие. Попробуйте позже.');
  return result.data;
 }
 function status(element,message,error=false){element.textContent=message;element.dataset.error=String(error);}
 const profile=document.querySelector('#profileForm');
 profile?.addEventListener('submit',async event=>{
  event.preventDefault();const button=profile.querySelector('button');const message=document.querySelector('#profileStatus');button.disabled=true;
  try{await request('profile',Object.fromEntries(new FormData(profile)));status(message,'Профиль сохранён.');}
  catch(error){status(message,error.message,true);}finally{button.disabled=false;}
 });
 const form=document.querySelector('#assessmentForm');if(!form)return;
 const initial=JSON.parse(document.querySelector('#assessmentData').textContent);let selected=initial.preferences?.competencyIds||[];let saved=initial.preferences||{};
 const generate=document.querySelector('#getAssignment');let generating=false;
 function selection(){return {specializationId:Number(document.querySelector('#assessmentSpecialization').value),gradeId:Number(document.querySelector('#assessmentGrade').value),competencyIds:[...container.querySelectorAll('input:checked')].map(input=>Number(input.value)),technologyIds:[...form.querySelectorAll('input[name=technology]:checked')].map(input=>Number(input.value))};}
 function canonical(value){return JSON.stringify({specializationId:value.specializationId,gradeId:value.gradeId,competencyIds:[...(value.competencyIds||[])].sort((a,b)=>a-b),technologyIds:[...(value.technologyIds||[])].sort((a,b)=>a-b)});}
 function generationState(){generate.disabled=generating||loading||!saved.specializationId||canonical(selection())!==canonical(saved);}
 const container=document.querySelector('#assessmentCompetencies');const message=document.querySelector('#assessmentStatus');const submit=form.querySelector('button[type=submit]');let loading=false;let sequence=0;
 function render(items){
  container.replaceChildren();
  if(!items.length){const note=document.createElement('p');note.textContent=document.querySelector('#assessmentSpecialization').value?'Для этой специализации пока нет доступных проверок.':'Выберите специализацию, чтобы увидеть компетенции.';container.append(note);return;}
  for(const item of items){
   const card=document.createElement('div');card.className='competency-card';const label=document.createElement('label');label.className='cabinet-check';const input=document.createElement('input');input.type='checkbox';input.name='competency';input.value=item.id;input.checked=selected.includes(item.id);const name=document.createElement('span');name.textContent=item.name;label.append(input,name);card.append(label);
   const description=document.createElement('small');description.textContent=item.description;card.append(description);const list=document.createElement('ul');for(const criterion of item.criteria){const li=document.createElement('li');li.textContent=criterion.name;list.append(li);}card.append(list);container.append(card);
  }
 }
 render(initial.options?.competencies||[]);
 generationState();form.addEventListener('change',generationState);
 document.querySelector('#assessmentSpecialization').addEventListener('change',async event=>{
  const current=++sequence;loading=true;submit.disabled=true;generate.disabled=true;selected=[];container.replaceChildren();status(message,'Загружаем доступные компетенции…');
  try{const data=event.target.value?await request('options',null,'?specializationId='+encodeURIComponent(event.target.value)):{competencies:[]};if(current!==sequence)return;render(data.competencies);status(message,'');}
  catch(error){if(current===sequence)status(message,error.message,true);}finally{if(current===sequence){loading=false;submit.disabled=false;generationState();}}
 });
 container.addEventListener('change',event=>{const checked=[...container.querySelectorAll('input:checked')];if(checked.length>3){event.target.checked=false;status(message,'Можно выбрать не более трёх компетенций.',true);}else status(message,'');});
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(loading)return;const competencyIds=[...container.querySelectorAll('input:checked')].map(input=>Number(input.value));
  if(competencyIds.length<1||competencyIds.length>3){status(message,'Выберите от одной до трёх компетенций.',true);return;}
  const payload=selection();submit.disabled=true;generate.disabled=true;
  try{saved=await request('preferences',payload);selected=competencyIds;status(message,'Выбор тестирования сохранён. Нажмите «Получить тестовое задание».');}catch(error){status(message,error.message,true);}finally{submit.disabled=false;generationState();}
 });
 generate.addEventListener('click',async()=>{
  if(generate.disabled||generating)return;generating=true;generationState();submit.disabled=true;status(message,'Создаём задание…');
  try{
   let requestKey=sessionStorage.getItem('assessmentRequestKey');if(!requestKey){requestKey=window.MestoUUID();sessionStorage.setItem('assessmentRequestKey',requestKey);}
   const task=await request('tests',{requestKey});sessionStorage.removeItem('assessmentRequestKey');location.assign(task.redirect);
  }catch(error){status(message,error.message,true);}finally{generating=false;submit.disabled=false;generationState();}
 });
})();
