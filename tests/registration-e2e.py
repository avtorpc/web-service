"""End-to-end check against make registration-up and its local Mailpit inbox."""
import http.cookiejar,json,re,time,urllib.request,urllib.error,subprocess
from uuid import uuid4
SITE='http://localhost:8080'
MAIL='http://localhost:8025'
def client():
 opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 html=opener.open(SITE,timeout=15).read().decode()
 token=re.search(r'name="registration-csrf" content="([^"]+)"',html).group(1)
 return opener,token
def call(opener,token,action,payload=None):
 req=urllib.request.Request(SITE+'/registration/'+action,data=json.dumps(payload or {}).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':token})
 try:
  with opener.open(req,timeout=20) as response:return response.status,json.load(response)
 except urllib.error.HTTPError as e:return e.code,json.load(e)
def mail_code(email):
 for _ in range(30):
  with urllib.request.urlopen(MAIL+'/api/v1/messages',timeout=5) as response:messages=json.load(response)['messages']
  for message in messages:
   if any(to.get('Address')==email for to in message['To']):
    with urllib.request.urlopen(MAIL+'/api/v1/message/'+message['ID'],timeout=5) as response:body=json.load(response)
    text=body.get('Text','') or re.sub('<[^>]+>',' ',body['HTML'])
    found=re.search(r'(?<!\d)(\d{6})(?!\d)',text)
    if found:return found.group(1)
  time.sleep(1)
 raise AssertionError('No verification mail received through Kafka')
for role in ['applicant','employer']:
 opener,token=client()
 status,body=call(opener,'invalid','start');assert status==403,(status,body)
 email=role+'-'+uuid4().hex[:12]+'@example.invalid'
 p={'name':'Сквозной тест','email':email,'password':'Test password 123','roleCode':role,'acceptTerms':True,'employerType':'company','company':'Тестовая компания'}
 status,body=call(opener,token,'start',p);assert status==200 and body['status']=='pending',(status,body)
 request_id=body['requestId']
 status,body=call(opener,token,'start',p);assert status==200 and body['requestId']==request_id,(status,body)
 status,body=call(opener,token,'resend');assert status==429,(status,body)
 code=mail_code(email)
 status,body=call(opener,token,'verify',{'verificationCode':code});assert status==200,(status,body)
 for _ in range(20):
  if body['status']=='ready':break
  time.sleep(1);status,body=call(opener,token,'status')
 assert body['status']=='ready',(status,body)
 assert body['roleCode']==role and 'accessToken' not in json.dumps(body),'Role changed or automatic login'
 status,body=call(opener,token,'verify',{'verificationCode':code});assert status==200 and body['status']=='ready',(status,body)
 # A new registration for the other role must check the existing account even in this browser session.
 duplicate=dict(p,roleCode='employer' if role=='applicant' else 'applicant')
 status,body=call(opener,token,'start',duplicate)
 assert status==409 and body['error']['code']=='EMAIL_EXISTS' and 'занят' in body['error']['message'],(status,body)
 # A separate session and a different email case must produce the same occupied-email message.
 other,other_token=client()
 status,body=call(other,other_token,'start',dict(duplicate,email=email.upper()))
 assert status==409 and body['error']['code']=='EMAIL_EXISTS' and 'занят' in body['error']['message'],(status,body)
 print(role+': duplicate email for the other role rejected in both existing and new sessions',flush=True)
 status,body=call(opener,token,'login',{'email':email,'password':'Test password 123'});assert status==200 and body['success'],(status,body)
 assert 'accessToken' not in json.dumps(body),'Tokens exposed to JavaScript'
 assert body['redirect']=='/cabinet',(role,'Missing cabinet redirect')
 cabinet_html=opener.open(SITE+body['redirect'],timeout=15).read().decode()
 assert ('Личный кабинет соискателя' if role=='applicant' else 'Личный кабинет работодателя') in cabinet_html,(role,'Wrong cabinet')
 assert 'id="profileForm"' in cabinet_html,(role,'Profile missing')
 assert ('id="assessmentForm"' in cabinet_html)==(role=='applicant'),(role,'Wrong assessment permissions')
 if role=='employer':assert p['company'] in cabinet_html,(role,'Registration employer profile lost')
 def cabinet_call(action,payload=None,query=''):
  req=urllib.request.Request(SITE+'/cabinet/'+action+query,data=json.dumps(payload).encode() if payload is not None else None,headers={'Content-Type':'application/json','X-CSRF-Token':token})
  try:
   with opener.open(req,timeout=20) as response:return response.status,json.load(response)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 status,dashboard=cabinet_call('data');assert status==200,(role,status,dashboard)
 own_uuid=dashboard['data']['profile']['user_uuid']
 assert own_uuid==request_id and dashboard['data']['role']==role,(role,'Profile belongs to another account')
 assert all(value==0 for value in dashboard['data']['counts'].values()),(role,'Demo records leaked into cabinet')
 status,again=cabinet_call('data',query='?user_uuid=00000000-0000-4000-8000-000000000000');assert status==200 and again['data']['profile']['user_uuid']==own_uuid,(role,'Owner selected by browser input')
 status,invalid=cabinet_call('profile',{'user_uuid':'00000000-0000-4000-8000-000000000000'});assert status==422,(role,'Profile owner can be overwritten')
 # Mutations require the existing web CSRF token.
 invalid_req=urllib.request.Request(SITE+'/cabinet/profile',data=b'{"city":"bad"}',headers={'Content-Type':'application/json','X-CSRF-Token':'invalid'})
 try:opener.open(invalid_req,timeout=15);raise AssertionError('Profile mutation accepted bad CSRF')
 except urllib.error.HTTPError as e:assert e.code==403,e.code
 status,updated=cabinet_call('profile',{'city':'Проверочный город'});assert status==200 and updated['data']['profile']['city']=='Проверочный город',(role,status,updated)
 if role=='applicant':
  status,options=cabinet_call('options');assert status==200,(status,options)
  references=options['data'];assert len(references['specializations'])==120 and len(references['grades'])==3 and len(references['technologies'])==62,(role,'Wrong reference catalog')
  specialization=references['specializations'][0]['id']
  status,choices=cabinet_call('options',query='?specializationId='+str(specialization));assert status==200 and choices['data']['competencies'],(status,choices)
  choice={'specializationId':specialization,'gradeId':references['grades'][0]['id'],'competencyIds':[choices['data']['competencies'][0]['id']],'technologyIds':[references['technologies'][0]['id']]}
  status,saved=cabinet_call('preferences',choice);assert status==200 and saved['data']==choice,(status,saved)
  status,stored=cabinet_call('data');assert status==200 and stored['data']['profile']['assessment_preferences']==choice,(status,stored)
  for bad in [dict(choice,competencyIds=choice['competencyIds']*4),dict(choice,specializationId=999999),dict(choice,technologyIds=[999999]),dict(choice,competencyIds=choice['competencyIds']*2)]:
   status,rejected=cabinet_call('preferences',bad);assert status==422,(status,rejected)
  restored_html=opener.open(SITE+'/cabinet',timeout=15).read().decode();assert 'Проверочный город' in restored_html and '\"competencyIds\"' in restored_html,(role,'Profile/preferences did not reload')
 else:
  assert dashboard['data']['profile']['public_name']==p['company'] and dashboard['data']['profile']['employer_type']==p['employerType'],(role,'Employer profile was not initialized')
  status,rejected=cabinet_call('options');assert status==403,(status,rejected)
  status,rejected=cabinet_call('preferences',{});assert status==403,(status,rejected)
 print(role+': cabinet profile persistence, role isolation and assessment access passed',flush=True)
 # Task endpoints must keep the same session/CSRF boundaries, including when no provider key exists.
 def task_call(path,payload=None,csrf=token):
  req=urllib.request.Request(SITE+path,data=json.dumps(payload).encode() if payload is not None else None,headers={'Content-Type':'application/json','X-CSRF-Token':csrf})
  try:
   with opener.open(req,timeout=20) as response:return response.status,json.load(response)
  except urllib.error.HTTPError as error:return error.code,json.load(error)
 status,_=task_call('/cabinet/tests',{'requestKey':str(uuid4())},'invalid');assert status==403,(role,'Task creation without CSRF')
 status,_=task_call('/cabinet/tests',{'requestKey':'invalid'});assert status==(422 if role=='applicant' else 403),(role,'Task creation role/input boundary')
 status,_=task_call('/cabinet/tests/9223372036854775800/data');assert status==(404 if role=='applicant' else 403),(role,'Task read boundary')
 print(role+': task page/API access and CSRF boundaries passed',flush=True)
 # The account API must work after removal of the legacy company link.
 cookie_handler=next(handler for handler in opener.handlers if isinstance(handler,urllib.request.HTTPCookieProcessor))
 session_id=next(cookie.value for cookie in cookie_handler.cookiejar if cookie.name.startswith('PHPSESSID'))
 result=subprocess.run(['docker','exec','mesto-web-web-service-1','php','tests/read-account-contract.php',session_id],capture_output=True,text=True,check=True,timeout=15)
 me=json.loads(result.stdout)
 assert me['success'] and me['data']['email']==email,(role,'Account API failed')
 assert me['data']['roles']==['ROLE_EMPLOYER' if role=='employer' else 'ROLE_APPLICANT'],(role,'Incorrect account role')
 assert 'company' not in me['data'],(role,'Legacy company field returned')
 assert me['data']['phoneNumber'] is None and me['data']['patronymic'] is None,(role,'Optional account fields failed')
 # Login state is rendered from the server session on every page.
 for page in ['/', '/index.html', '/vacancy.html', '/vacancy-create.html', '/resume.html', '/resume-owner.html', '/user.html', '/employer.html']:
  html=opener.open(SITE+page,timeout=15).read().decode()
  assert 'id="logoutForm"' in html and 'id="logoutButton"' in html,(page,'Missing logout')
  assert 'id="loginForm"' not in html and 'id="registerForm"' not in html and 'id="openAuth"' not in html,(page,'Guest forms still present')
  assert email in html and 'accessToken' not in html,(page,'Missing account or exposed token')
 status,body=call(opener,token,'start',p)
 assert status==409 and body['error']['code']=='ALREADY_AUTHENTICATED',(status,body)
 # Invalid CSRF cannot sign the browser out; a valid form destroys the login session.
 invalid=urllib.request.Request(SITE+'/logout',data=b'_csrf_token=invalid',headers={'Content-Type':'application/x-www-form-urlencoded'})
 try:
  opener.open(invalid,timeout=15)
  raise AssertionError('Logout accepted invalid CSRF')
 except urllib.error.HTTPError as e:assert e.code==403,e.code
 assert 'id="logoutForm"' in opener.open(SITE,timeout=15).read().decode()
 logout=urllib.request.Request(SITE+'/logout',data=('_csrf_token='+token).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})
 html=opener.open(logout,timeout=15).read().decode()
 assert 'id="logoutForm"' not in html and 'id="loginForm"' in html and 'id="registerForm"' in html,'Logout did not restore guest state'
 guest_html=other.open(SITE,timeout=15).read().decode()
 assert 'id="logoutForm"' not in guest_html and 'id="loginForm"' in guest_html,'Authentication leaked to another session'
 print(role+': persistent login state on all pages, blocked signup, protected logout and guest forms passed',flush=True)

 print(role+': web -> verification -> Kafka -> email -> confirmation -> auth -> password login passed',flush=True)
# A separate browser cannot operate another session's request by submitting its requestId.
opener,token=client();status,body=call(opener,token,'verify',{'requestId':request_id,'verificationCode':code});assert status==400,(status,body)
print('CSRF and registration session binding passed',flush=True)
