(() => {
 const form=document.getElementById('applicationForm');if(!form)return;
 form.onsubmit=async event=>{event.preventDefault();const button=form.querySelector('button'),status=document.getElementById('applicationStatus');button.disabled=true;status.textContent='Сохраняем отклик…';const fields=new FormData(form);
 try{const r=await fetch('/cabinet/chat-api/apply',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="registration-csrf"]').content},body:JSON.stringify(Object.fromEntries(fields))});const data=await r.json();if(r.status===401){location.assign('/?login=1');return;}if(!r.ok||!data.success)throw Error(data.error?.message||'Не удалось сохранить отклик.');location.assign('/cabinet/chats?application='+data.data.id);}
 catch(e){status.textContent=e.message;}finally{button.disabled=false;}};
})();
