(() => {
// Authenticated pages are rendered without registration or login forms.
if (!document.querySelector('#authModal')) {
  const logoutForm = document.querySelector('#logoutForm');
  if (logoutForm) {
    const remaining = Number(logoutForm.dataset.sessionExpiresAt) * 1000 - Date.now();
    setTimeout(() => location.reload(), Math.max(0, Math.min(remaining, 2147483647)));
    const employerLink = document.querySelector('#createVacancy');
    if (employerLink) employerLink.href = 'employer.html';
  }
  return;
}
// Registration credentials and codes belong to the server. Browser storage is not authentication.
let pendingVerification = null;
let registrationBusy = false;
let resendDeadline = 0;
let pollTimer = null;
const authCsrf = document.querySelector('meta[name="registration-csrf"]').content;
async function authRequest(action, payload = {}) {
  const response = await fetch('/registration/' + action, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':authCsrf}, body:JSON.stringify(payload)});
  let body; try { body = await response.json(); } catch { throw new Error('Сервис временно недоступен. Повторите позже.'); }
  if (!response.ok || !body.success) { const error = new Error(body.error?.message || 'Не удалось выполнить запрос.'); error.details=body.error || {}; throw error; }
  return body;
}
async function authAction(target, fn) {
  if (registrationBusy) return;
  registrationBusy = true;
  const buttons = [...document.querySelectorAll('#authModal button')];
  buttons.forEach(b=>b.disabled=true);
  try { await fn(); } catch (error) { target.textContent=error.message; if(error.details?.retryAfter)target.textContent+=' Повторите через '+error.details.retryAfter+' сек.'; }
  finally { registrationBusy=false; buttons.forEach(b=>b.disabled=false); updateResend(); }
}
function selectAuthTab(mode, focusTab=false) {
  if (pendingVerification) { showVerification(pendingVerification); return; }
  $('#emailVerification').hidden=true; $('.auth-tabs').hidden=false;
  const register=mode==='register'; $('#loginPanel').hidden=register; $('#registerPanel').hidden=!register;
  $('#loginTab').setAttribute('aria-selected',String(!register)); $('#registerTab').setAttribute('aria-selected',String(register));
  $('#loginTab').tabIndex=register?-1:0; $('#registerTab').tabIndex=register?0:-1;
  $('#authTitle').textContent=register?'Создайте свой аккаунт':'Рады вас видеть'; $('#authStatus').textContent='';
  if(focusTab)$(register?'#registerTab':'#loginTab').focus();
}
function readyToLogin(data) {
  clearTimeout(pollTimer); pendingVerification=null; selectAuthTab('login');
  $('#loginEmail').value=data.email; $('#loginPassword').value=''; $('#registerPassword').value='';
  $('#authStatus').textContent='Регистрация завершена. Введите пароль, который указали при регистрации.';
  $('#loginPassword').focus();
}
function showVerification(data) {
  if(data.status==='ready'){readyToLogin(data);return;}
  if(data.status==='conflict'){readyToLogin(data);$('#authStatus').textContent='Аккаунт с этим email уже существует. Введите его пароль.';return;}
  pendingVerification=data; resendDeadline=Date.now()+data.retryAfter*1000;
  $('#loginPanel').hidden=true; $('#registerPanel').hidden=true; $('.auth-tabs').hidden=true; $('#emailVerification').hidden=false;
  $('#authTitle').textContent=data.status==='creating'?'Создаём аккаунт':'Подтвердите почту';
  $('#verifyEmail').textContent='Письмо с кодом для '+data.email;
  $('#verifyForm').hidden=data.status!=='pending'; $('#changeVerifyEmail').disabled=registrationBusy||data.status==='creating';
  $('#verifyStatus').textContent=data.status==='creating'?'Email подтверждён. Ожидаем создание аккаунта.':data.status==='expired'?'Время регистрации истекло. Начните заново.':data.status==='conflict'?'Email уже занят. Войдите с паролем существующего аккаунта.':'Код действует до '+new Date(data.codeExpiresAt).toLocaleTimeString()+'. Проверьте почту и папку «Спам».';
  updateResend();
  if(data.status==='creating')pollTimer=setTimeout(pollRegistration,2000);
}
async function pollRegistration() {
  if(registrationBusy){pollTimer=setTimeout(pollRegistration,2000);return;}
  try { showVerification(await authRequest('status')); }
  catch(error){$('#verifyStatus').textContent=error.message+' Подтверждение сохранено, повторяем проверку.';pollTimer=setTimeout(pollRegistration,5000);}
}
function updateResend() {
  const b=$('#resendEmailCode'); const seconds=Math.max(0,Math.ceil((resendDeadline-Date.now())/1000));
  const expired=pendingVerification && Date.now()>=Date.parse(pendingVerification.expiresAt);
  b.disabled=registrationBusy||!pendingVerification||expired||pendingVerification.status!=='pending'||pendingVerification.resendsLeft<=0||seconds>0;
  if(expired && pendingVerification.status==='pending')$('#verifyStatus').textContent='Время регистрации истекло. Начните заново.';
  b.textContent=seconds?'Отправить снова через '+seconds+' сек.':pendingVerification?.resendsLeft===0?'Повторные отправки исчерпаны':'Отправить код повторно';
  $('#changeVerifyEmail').disabled=registrationBusy||pendingVerification?.status==='creating';
}
setInterval(updateResend,1000);
$('#openAuth').onclick=()=>{selectAuthTab('login');$('#authModal').showModal();};
$('#closeAuth').onclick=()=>$('#authModal').close();
$('#loginTab').onclick=()=>selectAuthTab('login'); $('#registerTab').onclick=()=>selectAuthTab('register');
['#loginTab','#registerTab'].forEach(id=>$(id).addEventListener('keydown',event=>{
 if(['ArrowLeft','ArrowRight','Home','End'].includes(event.key)){event.preventDefault();selectAuthTab(event.key==='Home'?'login':event.key==='End'?'register':id==='#loginTab'?'register':'login',true);}
}));
function updateEmployerRegistration(){
 const employer=$('#registerRole').value==='employer';$('#employerRegistrationFields').hidden=!employer;$('#registerCompany').required=employer;$('#registerCompany').disabled=!employer;$('#registerEmployerType').disabled=!employer;
 $('#registerForm button[type="submit"]').textContent='Отправить код на почту →';
}
$('#registerRole').addEventListener('change',updateEmployerRegistration);updateEmployerRegistration();
$('#registerForm').addEventListener('submit',event=>{
 event.preventDefault();if(!event.target.reportValidity())return;
 authAction($('#authStatus'),async()=>{
  const data=await authRequest('start',{name:$('#registerName').value.trim(),email:$('#registerEmail').value.trim(),password:$('#registerPassword').value,roleCode:$('#registerRole').value,employerType:$('#registerEmployerType').value,company:$('#registerCompany').value.trim(),acceptTerms:$('#registerTerms').checked});
  $('#registerPassword').value='';$('#emailCode').value='';showVerification(data);
 });
});
$('#verifyForm').addEventListener('submit',event=>{event.preventDefault();if(!event.target.reportValidity())return;authAction($('#verifyStatus'),async()=>{showVerification(await authRequest('verify',{verificationCode:$('#emailCode').value}));$('#emailCode').value='';});});
$('#resendEmailCode').onclick=()=>authAction($('#verifyStatus'),async()=>{showVerification(await authRequest('resend'));$('#emailCode').value='';});
$('#changeVerifyEmail').onclick=()=>authAction($('#verifyStatus'),async()=>{
 await authRequest('cancel');clearTimeout(pollTimer);pendingVerification=null;$('#verifyForm').reset();$('#registerPassword').value='';selectAuthTab('register');$('#registerEmail').focus();
});
$('#loginForm').addEventListener('submit',event=>{
 event.preventDefault();if(!event.target.reportValidity())return;
 authAction($('#authStatus'),async()=>{const password=$('#loginPassword').value;$('#loginPassword').value='';const result=await authRequest('login',{email:$('#loginEmail').value.trim(),password});$('#authStatus').textContent=result.message;location.assign(result.redirect || '/cabinet');});
});
$('#authModal').addEventListener('close',()=>{$('#loginPassword').value='';$('#registerPassword').value='';$('#emailCode').value='';$('#openAuth').focus();});
$('#authModal').addEventListener('click',event=>{if(event.target!==$('#authModal'))return;const r=$('#authModal').getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)$('#authModal').close();});
function startEmployerRegistration(){ $('#registerRole').value='employer';updateEmployerRegistration();selectAuthTab('register');if(!$('#authModal').open)$('#authModal').showModal(); }
if($('#createVacancy'))$('#createVacancy').addEventListener('click',event=>{event.preventDefault();startEmployerRegistration();});
if($('#myResume'))$('#myResume').addEventListener('click',event=>{event.preventDefault();$('#registerRole').value='applicant';updateEmployerRegistration();selectAuthTab('register');$('#authModal').showModal();});
const requestedRole=new URLSearchParams(location.search).get('register');
if(['applicant','employer'].includes(requestedRole)){$('#registerRole').value=requestedRole;updateEmployerRegistration();selectAuthTab('register');$('#authModal').showModal();}
// Resume the server-bound request after refresh. Its identifier is never taken from browser input.
authRequest('status').then(data=>{if(data.status==='cancelled')return;showVerification(data);if(!$('#authModal').open)$('#authModal').showModal();}).catch(()=>{});

})();

if (new URLSearchParams(location.search).get('login') === '1' && document.querySelector('#authModal')) document.querySelector('#openAuth').click();
