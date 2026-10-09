(() => {
 const csrf=document.querySelector('meta[name="registration-csrf"]')?.content;var resumeList=null,resumeEditInput=null;
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
 const resumeForm=document.querySelector('#resumeForm');if(resumeForm){const params=new URLSearchParams(location.search);['title','specialty','city','experience','salary','about','skills'].forEach(name=>{const value=params.get(name);if(value!==null){const field=resumeForm.elements[name];if(field)field.value=value;}});resumeForm.addEventListener('submit',async event=>{event.preventDefault();const button=resumeForm.querySelector('button'),message=document.querySelector('#resumeStatus');button.disabled=true;try{const values=Object.fromEntries(new FormData(resumeForm));values.content={specialty:values.specialty,city:values.city,experience:values.experience,salary:values.salary,about:values.about,skills:String(values.skills||'').split(',').map(v=>v.trim()).filter(Boolean),competencyIds:[...document.querySelectorAll('#assessmentCompetencies input:checked')].map(input=>Number(input.value))};delete values.specialty;delete values.city;delete values.experience;delete values.salary;delete values.about;delete values.skills;await request('resumes',values);message.textContent='Резюме сохранено.';history.replaceState({},'',location.pathname+'#resumesSection');setTimeout(()=>location.reload(),300);}catch(error){message.textContent=error.message;message.dataset.error='true';}finally{button.disabled=false;}});}
 const resumeUpload=document.createElement('div');resumeUpload.className='resume-upload';const resumeUploadTitle=document.createElement('h3');resumeUploadTitle.textContent='Добавить PDF вариант';const resumeFile=document.createElement('input');resumeFile.type='file';resumeFile.accept='application/pdf,.pdf';resumeFile.hidden=true;const resumeUploadButton=document.createElement('button');resumeUploadButton.type='button';resumeUploadButton.className='outline-button';resumeUploadButton.textContent='Загрузить PDF';const resumeUploadStatus=document.createElement('small');resumeUploadStatus.textContent='Выберите резюме кнопкой «Редактировать», затем нажмите кнопку.';resumeUpload.append(resumeUploadTitle,resumeFile,resumeUploadButton,resumeUploadStatus);document.querySelector('#resumeForm')?.before(resumeUpload);resumeUploadButton.addEventListener('click',()=>{if(!resumeEditInput.value){resumeUploadStatus.textContent='Сначала выберите резюме кнопкой «Редактировать».';return;}resumeFile.click();});resumeFile.addEventListener('change',async()=>{const id=resumeEditInput.value;const file=resumeFile.files[0];if(!id||!file)return;resumeUploadButton.disabled=true;resumeUploadStatus.textContent='Загрузка PDF…';const data=new FormData();data.append('file',file,file.name);try{const response=await fetch(`/cabinet/resumes/${id}/pdf`,{method:'POST',headers:{'X-CSRF-Token':csrf},body:data,credentials:'same-origin'});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.error?.message||'Не удалось загрузить PDF.');resumeUploadStatus.textContent='PDF прикреплён.';const saveButton=document.querySelector('#resumeForm button[type=submit]');if(saveButton)saveButton.textContent='Сохранить изменения';}catch(error){resumeUploadStatus.textContent=error.message;}finally{resumeUploadButton.disabled=false;resumeFile.value='';}});
 if(resumeList){fetch('/cabinet/resumes',{credentials:'same-origin'}).then(response=>response.json()).then(result=>{(result.data||[]).forEach((resume,index)=>{if(!resume.pdf)return;const card=resumeList.querySelectorAll('.resume-card')[index];if(!card)return;const link=document.createElement('a');link.className='resume-pdf-link';link.href='#';link.textContent='Открыть PDF: '+resume.pdf.name;link.addEventListener('click',event=>{event.preventDefault();alert('PDF сохранён в локальном хранилище. Для открытия добавим защищённую выдачу файла следующим шагом.');});card.append(link);});}).catch(()=>{});}
 const form=document.querySelector('#assessmentForm');if(!form)return;
 resumeList=document.querySelector('#resumeList');resumeEditInput=document.createElement('input');resumeEditInput.type='hidden';resumeEditInput.name='id';document.querySelector('#resumeForm')?.append(resumeEditInput);
 if(resumeList){fetch('/cabinet/resumes',{credentials:'same-origin'}).then(response=>response.json()).then(result=>{(result.data||[]).forEach((resume,index)=>{if(index===0&&!resumeEditInput.value)resumeEditInput.value=resume.id;const card=resumeList.querySelectorAll('.resume-card')[index];if(!card)return;const button=document.createElement('button');button.type='button';button.className='outline-button';button.textContent='Редактировать';button.addEventListener('click',()=>{const form=document.querySelector('#resumeForm');form.elements.title.value=resume.title;Object.entries(resume.content||{}).forEach(([name,value])=>{const field=form.elements[name];if(field)field.value=Array.isArray(value)?value.join(', '):value||''});resumeEditInput.value=resume.id;resumeUploadStatus.textContent=resume.pdf?'Можно заменить прикреплённый PDF новым файлом.':'Теперь выберите PDF-файл для этого резюме.';const submit=form.querySelector('button[type=submit]');submit.textContent='Сохранить изменения';submit.before(button);form.scrollIntoView({behavior:'smooth',block:'center'});});card.append(button);});}).catch(()=>{});}
 if(resumeList){fetch('/cabinet/resumes',{credentials:'same-origin'}).then(response=>response.json()).then(result=>{(result.data||[]).forEach((resume,index)=>{if(!resume.pdf)return;const card=resumeList.querySelectorAll('.resume-card')[index];if(!card||card.querySelector('.resume-pdf-link'))return;const link=document.createElement('a');link.className='resume-pdf-link';link.href=`/cabinet/resumes/${resume.id}/pdf`;link.target='_blank';link.rel='noopener';link.textContent='Открыть прикреплённый PDF';card.append(link);});}).catch(()=>{});}
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
   let requestKey=sessionStorage.getItem('assessmentRequestKey');if(!requestKey){requestKey=crypto.randomUUID();sessionStorage.setItem('assessmentRequestKey',requestKey);}
   const task=await request('tests',{requestKey});sessionStorage.removeItem('assessmentRequestKey');location.assign(task.redirect);
  }catch(error){status(message,error.message,true);}finally{generating=false;submit.disabled=false;generationState();}
 });
})();
