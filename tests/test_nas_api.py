"""Run with PHP_BIN=php python -m unittest discover -s tests -v."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import shlex
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]


class NasApiTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.token = secrets.token_urlsafe(32)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        self.url = f'http://127.0.0.1:{port}/calorie-api.php'
        env = dict(os.environ, CALORIE_TRACKER_TOKEN=self.token, CALORIE_TRACKER_DATA_DIR=self.tmp.name)
        self.server = subprocess.Popen(shlex.split(os.environ.get('PHP_BIN', 'php')) +
            ['-n', '-S', f'127.0.0.1:{port}', '-t', str(ROOT)], env=env,
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(self.stop_server)
        for _ in range(50):
            try:
                status, reply = self.request()
                break
            except OSError:
                if self.server.poll() is not None:
                    self.fail('PHP server failed to start')
                time.sleep(0.05)
        else:
            self.fail('PHP server did not become ready')
        self.assertEqual(status, 200)
        self.base = {'goals': {'startDate': '2026-10-16', 'idealWeight': 200, 'targetProtein': 150},
            'dailyLogs': [{'id': 1, 'date': '10/16/26', 'consumed': 2000}],
            'weightTimeline': [{'id': 101, 'date': '10/16/26', 'weight': 215}],
            'bodyFatTimeline': []}
        status, initialized = self.request('PUT', {'protocol': 2, 'revision': reply['revision'], 'initialize': self.base})
        self.assertEqual(status, 200)
        self.revision = initialized['revision']
        self.base_tag = initialized['baseTag']

    def stop_server(self):
        self.server.terminate()
        self.server.wait(timeout=5)

    def request(self, method='GET', payload=None, token=True):
        req = urllib.request.Request(self.url, method=method,
            data=json.dumps(payload).encode() if payload is not None else None,
            headers={'X-Calorie-Token': self.token if token else 'invalid', 'Content-Type': 'application/json'})
        try:
            response = urllib.request.urlopen(req, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, json.load(response)

    def save(self, changes, revision=None):
        return self.request('PUT', {'protocol': 2, 'revision': revision or self.revision, 'baseTag': self.base_tag, 'changes': changes})

    def test_actual_js_client_saves_against_php(self):
        env = dict(os.environ, TEST_NAS_URL=self.url, TEST_NAS_TOKEN=self.token)
        result = subprocess.run(['node', str(ROOT / 'tests/nas-client.integration.cjs')],
            env=env, text=True, capture_output=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_legacy_replace_and_initial_upload_cannot_overwrite(self):
        self.assertEqual(self.request('PUT', {'revision': self.revision, 'store': self.base})[0], 400)
        self.assertEqual(self.request('PUT', {'protocol': 2, 'revision': self.revision, 'initialize': self.base})[0], 409)
        self.assertEqual(self.request()[1]['revision'], self.revision)

    def test_two_devices_merge_distinct_records(self):
        a = {'id': 2, 'date': '10/17/26', 'consumed': 1800}
        b = {'id': 3, 'date': '10/18/26', 'consumed': 1900}
        self.assertEqual(self.save({'dailyLogs': {'upsert': [a], 'delete': []}})[0], 200)
        status, reply = self.save({'dailyLogs': {'upsert': [b], 'delete': []}})
        self.assertEqual(status, 200)
        self.assertEqual({row['id'] for row in reply['store']['dailyLogs']}, {1, 2, 3})
        self.assertTrue(list((Path(self.tmp.name) / 'calorie-backups').glob('*.json')))

    def test_same_record_conflict_is_atomic(self):
        a = dict(self.base['dailyLogs'][0], consumed=1800)
        b = dict(a, consumed=1600)
        status, saved = self.save({'dailyLogs': {'upsert': [a], 'delete': []}})
        self.assertEqual(status, 200)
        status, _ = self.save({'goals': {'set': {'idealWeight': 190}, 'remove': []},
            'dailyLogs': {'upsert': [b], 'delete': []}})
        self.assertEqual(status, 409)
        self.assertEqual(self.request()[1]['store'], saved['store'])

    def test_delete_preserves_concurrent_add_and_rejects_concurrent_edit(self):
        added = {'id': 2, 'date': '10/17/26', 'consumed': 1800}
        self.save({'dailyLogs': {'upsert': [added], 'delete': []}})
        status, reply = self.save({'dailyLogs': {'upsert': [], 'delete': [1]}})
        self.assertEqual(status, 200)
        self.assertEqual(reply['store']['dailyLogs'], [added])
        # A stale edit must not resurrect a record deleted by another device.
        self.assertEqual(self.save({'dailyLogs': {'upsert': [dict(self.base['dailyLogs'][0], consumed=1700)], 'delete': []}})[0], 409)

    def test_duplicate_day_with_different_ids_is_rejected(self):
        row = {'id': 2, 'date': '2026-10-16', 'consumed': 1800}
        self.assertEqual(self.save({'dailyLogs': {'upsert': [row], 'delete': []}})[0], 400)
        self.assertEqual(self.request()[1]['revision'], self.revision)

    def test_goal_changes_merge_and_conflict(self):
        self.save({'goals': {'set': {'targetProtein': 160}, 'remove': []}})
        status, reply = self.save({'goals': {'set': {'idealWeight': 195}, 'remove': []}})
        self.assertEqual(status, 200)
        self.assertEqual(reply['store']['goals']['targetProtein'], 160)
        self.assertEqual(self.save({'goals': {'set': {'targetProtein': 180}, 'remove': []}})[0], 409)

    def test_identical_retry_is_idempotent(self):
        changes = {'dailyLogs': {'upsert': [dict(self.base['dailyLogs'][0], consumed=1700)], 'delete': []}}
        first = self.save(changes)[1]
        status, second = self.save(changes)
        self.assertEqual(status, 200)
        self.assertEqual(first['revision'], second['revision'])

    def test_mismatched_baseline_metadata_cannot_write(self):
        self.assertEqual(self.request('PUT', {'protocol': 2, 'revision': self.revision,
            'baseTag': 'wrong', 'changes': {'goals': {'set': {'idealWeight': 190}, 'remove': []}}})[0], 409)
        self.assertEqual(self.request()[1]['revision'], self.revision)

    def test_auth_unknown_baseline_and_backup_integrity(self):
        self.assertEqual(self.request(token=False)[0], 401)
        self.assertEqual(self.save({}, revision='a' * 64)[0], 409)
        self.save({'goals': {'set': {'idealWeight': 195}, 'remove': []}})
        backups = list((Path(self.tmp.name) / 'calorie-backups').glob('*.json'))
        self.assertTrue(any(hashlib.sha256(p.read_bytes()).hexdigest() == self.revision for p in backups))


if __name__ == '__main__':
    unittest.main()
