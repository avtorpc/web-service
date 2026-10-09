"""Disposable accounts only: renewal after idle, concurrent tabs, logout revocation."""
import argparse, concurrent.futures, http.cookiejar, json, re, subprocess, urllib.request, urllib.error
from pathlib import Path
parser=argparse.ArgumentParser();parser.add_argument('--fixture',required=True);args=parser.parse_args();f=json.loads(Path(args.fixture).read_text())
class Browser:
 def __init__(self):
  self.csrf='';self.jar=http.cookiejar.CookieJar();self.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
  code,html=self.request('GET','/');assert code==200;self.csrf=re.search(r'name="registration-csrf" content="([^"]+)"',html).group(1)
 def request(self,method,path,payload=None,form=False,csrf=True):
  headers={'Content-Type':'application/x-www-form-urlencoded' if form else 'application/json'}
  if csrf:headers['X-CSRF-Token']=self.csrf
  body=None if payload is None else (urllib.parse.urlencode(payload).encode() if form else json.dumps(payload).encode())
  try:r=self.client.open(urllib.request.Request('http://localhost:8080'+path,data=body,headers=headers,method=method),timeout=25)
  except urllib.error.HTTPError as error:r=error
  return r.status,r.read().decode()
 def login(self,email):
  code,html=self.request('POST','/registration/login',{'email':email,'password':'Fixture password 123'});assert code==200,(code,'login failed');assert json.loads(html)['success']
  cookie=next(c for c in self.jar if c.name.upper().startswith('PHPSESSID'))
  assert cookie.expires and cookie.expires>__import__('time').time()+86400,'Session cookie is not persistent'
  self.sid=cookie.value
 def expire(self):
  subprocess.run(['docker','exec','mesto-web-web-service-1','php','tests/session-fixture.php',self.sid],check=True,stdout=subprocess.DEVNULL)
 def cabinet(self):
  code,html=self.request('GET','/cabinet');assert code==200 and 'logoutForm' in html,'Idle session lost';return html
for role in ('candidate','employer'):
 b=Browser();b.login(f[role+'Email']);b.expire();b.cabinet()
 b.expire()
 with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:list(pool.map(lambda _:b.cabinet(),range(4)))
 code,body=b.request('GET','/cabinet/chat-api/bootstrap');assert code==200 and json.loads(body)['data']['expiresAt']>__import__('time').time(),'Chat renewal failed'
 assert b.request('POST','/logout',{'_csrf_token':'wrong'},form=True)[0]==403
 b.cabinet()
 assert b.request('POST','/logout',{'_csrf_token':b.csrf},form=True)[0]==200
 assert b.request('GET','/cabinet/chat-api/list')[0]==401,'Logout retained API access'
print('PASS: applicant and employer idle renewal, persistent cookie, concurrent tabs, chat token renewal, CSRF logout, anonymous after logout')
