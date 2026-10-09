import json,re,urllib.request,urllib.error,http.cookiejar,uuid
from pathlib import Path
import argparse
parser=argparse.ArgumentParser();parser.add_argument('--fixture',required=True);args=parser.parse_args()
f=json.loads(Path(args.fixture).read_text())
class Browser:
 def __init__(self,email=None):
  self.jar=http.cookiejar.CookieJar();self.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar));self.csrf=''
  status,body=self.request('GET','/',raw=True);assert status==200;self.csrf=re.search(r'name="registration-csrf" content="([^"]+)"',body).group(1)
  if email:
   status,data=self.request('POST','/registration/login',{'email':email,'password':'Fixture password 123'});assert status==200, (status,data.get('error'));status,body=self.request('GET','/cabinet',raw=True);assert status==200;self.csrf=re.search(r'name="registration-csrf" content="([^"]+)"',body).group(1)
 def request(self,method,path,body=None,raw=False,csrf=True):
  headers={'Content-Type':'application/json'}
  if csrf:headers['X-CSRF-Token']=self.csrf
  req=urllib.request.Request('http://localhost:8080'+path,data=None if body is None else json.dumps(body).encode(),headers=headers,method=method)
  try:r=self.client.open(req,timeout=20)
  except urllib.error.HTTPError as e:r=e
  text=r.read().decode();return r.status,text if raw else json.loads(text)
 def api(self,path,body=None,status=200):
  code,data=self.request('GET' if body is None else 'POST','/cabinet/chat-api/'+path,body);assert (code in (200,201) if status==200 else code==status),(path,code,data.get('error'));return data.get('data')
a=Browser(f['candidateEmail']);e=Browser(f['employerEmail']);x=Browser(f['strangerEmail']);anon=Browser()
assert anon.request('GET','/cabinet/chat-api/list')[0]==401
payload={'vacancyId':f['vacancy'],'resumeTitle':'PHP-разработчик','resumeText':'Symfony, PostgreSQL, тестирование API.','coverLetter':'Здравствуйте! Готов обсудить вакансию.'}
app=a.api('apply',payload);assert a.api('apply',payload)['id']==app['id'];f['application']=app['id'];Path(args.fixture).write_text(json.dumps(f))
assert a.request('POST','/cabinet/chat-api/apply',payload,csrf=False)[0]==403
assert a.request('POST','/cabinet/chat-api/apply-chat',{'vacancyId':f['vacancy']},csrf=False)[0]==403
e.api('apply-chat',{'vacancyId':f['vacancy']},403)
quick=a.api('apply-chat',{'vacancyId':f['vacancy']});assert quick['applicationId']==app['id'];assert a.api('apply-chat',{'vacancyId':f['vacancy']})['conversationId']==quick['conversationId']
e.api('apply',payload,403);a.api('application/'+app['id'],{'status':'invited'},403);x.api('context/'+app['id'],status=404)
c=a.api('open',{'applicationId':app['id']});assert e.api('open',{'applicationId':app['id']})['id']==c['id'];x.api('open',{'applicationId':app['id']},404)
path='conversations/'+c['id']+'/messages';key=str(uuid.uuid4());msg={'body':'Первое сообщение соискателя. <script>Это обычный текст</script>','clientMessageId':key};m=a.api(path,msg);assert a.api(path,msg)['id']==m['id'];a.api(path,{'body':'изменённый текст','clientMessageId':key},409)
x.api(path,status=404);a.api(path,{'body':' ','clientMessageId':str(uuid.uuid4())},400)
e.api(path,{'body':'Здравствуйте! Приглашаем обсудить условия работы.','clientMessageId':str(uuid.uuid4())});assert a.api('context/'+app['id'])['status']=='reviewing'
e.api('application/'+app['id'],{'status':'invited','score':85,'comment':'Подходящий опыт.'});e.api(path,{'body':'Удобно завтра в 12:00?','clientMessageId':str(uuid.uuid4())});assert a.api('context/'+app['id'])['status']=='invited'
rows=a.api(path);assert len(rows)==3;assert rows[0]['body']==msg['body'];a.api('conversations/'+c['id']+'/read',{'seq':rows[-1]['seq']});a.api('conversations/'+c['id']+'/favorite',{'favorite':True});apps=a.api('list');assert len(apps)==1 and apps[0]['conversation']['unread']==0 and apps[0]['conversation']['candidate_favorite']
assert e.api('list')[0]['resume_snapshot']['text']==payload['resumeText'];assert x.api('list')==[]
for browser in (a,e):
 code,html=browser.request('GET','/cabinet/chats?application='+app['id'],raw=True);assert code==200 and 'chatMessageForm' in html
code,js=anon.request('GET','/chat/socket.io/socket.io.js',raw=True);assert code==200 and 'Socket.IO' in js
print('PASS: browser sessions, CSRF, real applications/resume snapshots, one shared chat, role isolation, message replay, employer reviewing/invitation, score, favorites/read/history, Socket.IO client through gateway')
print('Fixture application:',app['id'])
