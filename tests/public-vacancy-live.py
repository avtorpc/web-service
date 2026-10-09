"""Read-only anonymous check of a published vacancy; no registration or AI calls."""
import argparse
import json
import re
import urllib.error
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--id', required=True)
args = parser.parse_args()
site = 'http://localhost:8080'

with urllib.request.urlopen(site + '/', timeout=20) as response:
    assert response.status == 200
    html = response.read().decode()
match = re.search(r'<script id="vacancyCatalogData" type="application/json">(.*?)</script>', html, re.S)
assert match, 'Missing catalog data'
data = json.loads(match.group(1))
assert not data['error'], 'Catalog unavailable'
assert any(row['id'] == args.id for row in data['vacancies']), 'Published vacancy missing'
assert all('employer_id' not in row and 'contact_email' not in row for row in data['vacancies'])
assert '/js/employer-store.js' not in html, 'Browser prototype store still used'
with urllib.request.urlopen(site + '/vacancy.html?id=' + args.id, timeout=20) as response:
    assert response.status == 200
    detail = response.read().decode()
assert 'Демонстрационная вакансия' not in detail
try:
    urllib.request.urlopen(site + '/vacancy.html?id=00000000-0000-4000-8000-000000000000', timeout=20)
    raise AssertionError('Unknown vacancy accepted')
except urllib.error.HTTPError as error:
    assert error.code == 404
request = urllib.request.Request(site + '/cabinet/vacancies/' + args.id + '/unpublish', data=b'{"version":1}', headers={'Content-Type':'application/json'})
try:
    urllib.request.urlopen(request, timeout=20)
    raise AssertionError('Anonymous publication mutation accepted')
except urllib.error.HTTPError as error:
    assert error.code == 401
print('PASS: anonymous database catalog, detail, unknown ID 404, publication mutation 401, private fields excluded')
