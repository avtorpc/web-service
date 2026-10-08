"""Explicit live check: creates one synthetic applicant and calls GigaChat generation/evaluation.

Run only when a real provider check is authorized. Never prints tokens or API keys.
"""
import argparse
import http.cookiejar
import json
import re
import time
import urllib.error
import urllib.request
from uuid import uuid4

parser = argparse.ArgumentParser()
parser.add_argument('--resume', type=int)
parser.add_argument('--email')
args = parser.parse_args()
SITE = 'http://localhost:8080'
MAIL = 'http://localhost:8025'
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
html = opener.open(SITE, timeout=15).read().decode()
csrf = re.search(r'name="registration-csrf" content="([^"]+)"', html).group(1)


def call(path, payload=None):
    request = urllib.request.Request(SITE + path, data=json.dumps(payload).encode() if payload is not None else None,
                                    headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf})
    try:
        with opener.open(request, timeout=25) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, json.load(error)


def ok(path, payload=None):
    status, result = call(path, payload)
    assert status == 200 and result.get('success'), (path, status, result)
    return result['data']


def wait_task(task_id, operation):
    previous = None
    for _ in range(60):
        task = ok(f'/cabinet/tests/{task_id}/data')
        state = task['status'] if operation == 'generation' else task['attempt']['status']
        if state != previous:
            print(operation + ': ' + state, flush=True)
            previous = state
        failed = 'generation_failed' if operation == 'generation' else 'evaluation_failed'
        if state == failed:
            error = task if operation == 'generation' else task['attempt']['evaluation']
            raise AssertionError('Provider check failed: ' + str(error.get('error_code')) + '; ' + str(error.get('error')))
        if state != ('generating' if operation == 'generation' else 'evaluating'):
            return task
        time.sleep(4)
    raise AssertionError('Background operation did not complete within four minutes')


if args.resume:
    assert args.email, '--email is required for resume'
    email = args.email
else:
    email = 'assessment-live-' + uuid4().hex[:12] + '@example.invalid'
    status, registration = call('/registration/start', {'name': 'Проверка GigaChat', 'email': email,
                                                       'password': 'Test password 123', 'roleCode': 'applicant', 'acceptTerms': True})
    assert status == 200 and registration['status'] == 'pending', (status, registration)
    code = None
    for _ in range(30):
        with urllib.request.urlopen(MAIL + '/api/v1/messages', timeout=5) as response:
            messages = json.load(response)['messages']
        for message in messages:
            if any(to.get('Address') == email for to in message['To']):
                with urllib.request.urlopen(MAIL + '/api/v1/message/' + message['ID'], timeout=5) as response:
                    body = json.load(response)
                text = body.get('Text') or re.sub('<[^>]+>', ' ', body['HTML'])
                match = re.search(r'(?<!\d)(\d{6})(?!\d)', text)
                if match:
                    code = match.group(1)
        if code:
            break
        time.sleep(1)
    assert code, 'Verification mail not received'
    status, registration = call('/registration/verify', {'verificationCode': code})
    assert status == 200, (status, registration)
    for _ in range(20):
        if registration['status'] == 'ready':
            break
        time.sleep(1)
        status, registration = call('/registration/status', {})
    assert registration['status'] == 'ready'
status, login = call('/registration/login', {'email': email, 'password': 'Test password 123'})
assert status == 200 and login['success'] and login['redirect'] == '/cabinet', (status, login)
print('Synthetic applicant logged in: ' + email, flush=True)
if args.resume:
    task_id = args.resume
    task = ok(f'/cabinet/tests/{task_id}/data')
    if task['status'] == 'generation_failed':
        task = ok(f'/cabinet/tests/{task_id}/retry-generation', {})
    competencies = task['criteria']
else:
    options = ok('/cabinet/options')
    specialization = next(item for item in options['specializations'] if 'backend' in item['code'])
    grade = next(item for item in options['grades'] if item['code'] == 'junior')
    options = ok('/cabinet/options?specializationId=' + str(specialization['id']))
    competencies = [item for item in options['competencies'] if item['code'] in ['api-design', 'transactions']]
    if not competencies:
        competencies = options['competencies'][:1]
    technologies = [item['id'] for item in options['technologies'] if item['code'] in ['php', 'postgresql']]
    selection = {'specializationId': specialization['id'], 'gradeId': grade['id'],
                 'competencyIds': [item['id'] for item in competencies], 'technologyIds': technologies}
    assert ok('/cabinet/preferences', selection) == selection
    key = {'requestKey': str(uuid4())}
    task = ok('/cabinet/tests', key)
    task_id = task['id']
    assert ok('/cabinet/tests', key)['id'] == task_id, 'Task replay created a duplicate'
    print('Task persisted: ' + str(task_id), flush=True)
task = wait_task(task_id, 'generation')
assert task['status'] == 'ready' and task['assignment']['text']
assert len(task['criteria']) == len(competencies)
assert len({c['code'] for c in task['criteria']}) == len(competencies)
assert all(len(c['checks']) == 2 for c in task['criteria'])
snapshot = task['criteria']
page = opener.open(SITE + '/cabinet/tests/' + str(task_id), timeout=15).read().decode()
assert 'Описание задания' in page and 'Параметры оценки' in page and 'Ответ соискателя' in page
assert 'aria-readonly="true"' in page
answer = {'text': 'Тестовый ответ для проверки интеграции. Предлагаю реализовать API на PHP с проверкой входных данных, '
                  'явными HTTP-кодами ответа и обработкой ошибок. Изменения данных в PostgreSQL выполняются в одной '
                  'транзакции, при ошибке производится rollback. Полный код и результаты запуска не предоставлены; '
                  'это описание подхода, а не подтверждение работоспособности.'}
assert ok(f'/cabinet/tests/{task_id}/answer', answer)['attempt']['answer'] == answer
assert ok(f'/cabinet/tests/{task_id}/data')['attempt']['answer'] == answer
assert ok(f'/cabinet/tests/{task_id}/submit', answer)['attempt']['status'] == 'evaluating'
assert ok(f'/cabinet/tests/{task_id}/submit', {'text': 'Should not overwrite'})['attempt']['answer'] == answer
task = wait_task(task_id, 'evaluation')
assert task['criteria'] == snapshot
evaluation = task['attempt']['evaluation']
assert not evaluation['code_executed'] and not evaluation['grade_confirmed']
assert len(evaluation['criteria']) == len(snapshot)
for criterion in evaluation['criteria']:
    states = [check['status'] for check in criterion['checks']]
    expected = None if 'unknown' in states else states.count('passed')
    assert criterion['score'] == expected
page = opener.open(SITE + '/cabinet/tests/' + str(task_id), timeout=15).read().decode()
assert 'Предварительная оценка' in page and 'readonly' in page
dashboard = ok('/cabinet/data')
assert any(int(item['id']) == task_id for item in dashboard['tests'])
status, rejected = call(f'/cabinet/tests/{task_id}/answer', {'text': 'Overwrite'})
assert status == 409
print('PASS: live generation, stored ownership/selection, readonly criteria, answer persistence, live evaluation, immutable submission, server scores and cabinet history.', flush=True)
print('Test task URL: ' + SITE + '/cabinet/tests/' + str(task_id), flush=True)
