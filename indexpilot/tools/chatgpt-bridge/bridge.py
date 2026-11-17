#!/usr/bin/env python3
"""Мост IndexPilot → ChatGPT по подписке через Codex app-server.

Принимает на 127.0.0.1 запросы в формате OpenAI Chat Completions (тот же, что у OpenAI API и OpenRouter)
и выполняет каждый в отдельном временном диалоге `codex app-server`. Codex вошёл в аккаунт ChatGPT,
поэтому запросы идут по подписке, без ключа OpenAI API.

Модели оставлен только текст и картинки: shell, браузер, плагины, MCP и прочие инструменты выключены,
диалог не сохраняется. Доступ — по общему токену из файла (заголовок Authorization: Bearer).

Только стандартная библиотека Python 3.9+. Настройки — переменные окружения:
  IPX_LISTEN      адрес и порт, по умолчанию 127.0.0.1:8787
  IPX_TOKEN_FILE  файл с токеном доступа, по умолчанию /etc/indexpilot-ai/token
  IPX_CODEX       команда codex, по умолчанию codex
  IPX_EFFORT      усилие рассуждения модели: low, medium, high; по умолчанию medium
  IPX_TIMEOUT     предел ответа модели, секунд; по умолчанию 150
  IPX_PARALLEL    сколько запросов к модели одновременно; по умолчанию 2
"""

import base64
import hmac
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LISTEN = os.environ.get('IPX_LISTEN', '127.0.0.1:8787')
TOKEN_FILE = os.environ.get('IPX_TOKEN_FILE', '/etc/indexpilot-ai/token')
CODEX = os.environ.get('IPX_CODEX', 'codex')
EFFORT = os.environ.get('IPX_EFFORT', 'medium')
TIMEOUT = int(os.environ.get('IPX_TIMEOUT', '150'))
SLOTS = threading.BoundedSemaphore(int(os.environ.get('IPX_PARALLEL', '2')))
MAX_BODY = 30 * 1024 * 1024

# Всё, чем модель могла бы действовать на сервере или в сети, выключено: остаются только текст и картинки.
DISABLED = (
    'shell_tool unified_exec shell_snapshot apps plugins remote_plugin browser_use browser_use_external '
    'computer_use in_app_browser image_generation multi_agent skill_search sleep_tool tool_suggest view_image '
    'code_mode_host'
).split()

# Элементы ответа, которые означают, что модель всё-таки пытается действовать, а не писать текст.
ACTIONS = re.compile(r'command|exec|tool|mcp|fileChange|webSearch|patch|browser', re.I)


def token():
    with open(TOKEN_FILE, encoding='utf-8') as handle:
        return handle.read().strip()


class BridgeError(Exception):
    def __init__(self, status, message):
        super().__init__(message)
        self.status = status


class AppServer:
    """Один процесс codex app-server на один запрос: JSON-RPC построчно через stdin/stdout."""

    def __init__(self, workdir):
        command = [CODEX, 'app-server', '-c', 'mcp_servers={}', '-c', 'web_search="disabled"', '-c', 'project_doc_max_bytes=0']
        for feature in DISABLED:
            command += ['--disable', feature]
        self.process = subprocess.Popen(
            command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            text=True, encoding='utf-8', cwd=workdir, bufsize=1,
        )
        self.next_id = 0
        self.deadline = time.monotonic() + TIMEOUT

    def send(self, method, params, notify=False):
        message = {'method': method, 'params': params}
        if not notify:
            self.next_id += 1
            message['id'] = self.next_id
        self.process.stdin.write(json.dumps(message, ensure_ascii=False) + '\n')
        self.process.stdin.flush()
        return self.next_id

    def read(self):
        if time.monotonic() > self.deadline:
            raise BridgeError(504, 'ChatGPT не ответил вовремя. Повторите через минуту.')
        line = self.process.stdout.readline()
        if not line:
            raise BridgeError(502, 'Codex завершился без ответа. Проверьте вход в ChatGPT: codex login status.')
        try:
            return json.loads(line)
        except ValueError:
            return {}

    def call(self, method, params):
        request_id = self.send(method, params)
        while True:
            message = self.read()
            if message.get('id') == request_id:
                if 'error' in message:
                    raise BridgeError(502, 'Codex: ' + str(message['error'].get('message', message['error']))[:300])
                return message.get('result', {})
            # Запросы сервера к клиенту (одобрения и т. п.) отклоняются: модель ничего не выполняет.
            if 'id' in message and 'method' in message:
                self.process.stdin.write(json.dumps({'id': message['id'], 'error': {'code': -32601, 'message': 'denied'}}) + '\n')
                self.process.stdin.flush()

    def close(self):
        try:
            self.process.kill()
            self.process.wait(timeout=5)
        except Exception:  # noqa: BLE001 — процесс уже завершён
            pass


def split_messages(messages, workdir):
    """Сообщения OpenAI → инструкции диалога и ввод для Codex. Картинки data: сохраняются во временные файлы."""
    instructions, inputs, number = [], [], 0
    for message in messages if isinstance(messages, list) else []:
        role, content = message.get('role'), message.get('content')
        parts = [{'type': 'text', 'text': content}] if isinstance(content, str) else (content or [])
        for part in parts:
            if not isinstance(part, dict):
                continue
            if part.get('type') == 'text' and isinstance(part.get('text'), str):
                if role in ('system', 'developer'):
                    instructions.append(part['text'])
                else:
                    inputs.append({'type': 'text', 'text': part['text']})
            elif part.get('type') == 'image_url' and role not in ('system', 'developer'):
                url = (part.get('image_url') or {}).get('url', '')
                match = re.match(r'^data:image/(png|jpe?g|webp|gif);base64,(.+)$', url, re.S)
                if match:
                    number += 1
                    path = os.path.join(workdir, 'image-%d.%s' % (number, match.group(1).replace('jpeg', 'jpg')))
                    with open(path, 'wb') as handle:
                        handle.write(base64.b64decode(match.group(2)))
                    inputs.append({'type': 'localImage', 'path': path})
                # Адреса картинок Codex не принимает: клиент передаёт картинки внутри запроса (data:).
    return '\n\n'.join(instructions), inputs


def complete(body):
    workdir = tempfile.mkdtemp(prefix='ipx-')
    server = None
    try:
        instructions, inputs = split_messages(body.get('messages'), workdir)
        if not inputs:
            raise BridgeError(400, 'Пустой запрос.')
        server = AppServer(workdir)
        server.call('initialize', {'clientInfo': {'name': 'indexpilot', 'title': 'IndexPilot', 'version': '1.0'}})
        server.send('initialized', {}, notify=True)
        thread = {'ephemeral': True, 'sandbox': 'read-only', 'approvalPolicy': 'never', 'cwd': workdir}
        if instructions:
            thread['developerInstructions'] = instructions
        model = str(body.get('model') or '').strip()
        if model and model != 'default':
            thread['model'] = model
        thread_id = server.call('thread/start', thread)['thread']['id']
        turn = {'threadId': thread_id, 'input': inputs, 'effort': EFFORT}
        if isinstance(body.get('output_schema'), dict):
            turn['outputSchema'] = body['output_schema']
        server.call('turn/start', turn)
        text = ''
        while True:
            message = server.read()
            method = message.get('method', '')
            params = message.get('params') or {}
            item = params.get('item') or {}
            if method in ('item/started', 'item/completed') and ACTIONS.search(str(item.get('type', ''))):
                raise BridgeError(502, 'Модель попыталась выполнить действие — запрос остановлен.')
            if method == 'item/completed' and item.get('type') == 'agentMessage':
                text = item.get('text') or text
            if method == 'error':
                error = params.get('error') or {}
                raise BridgeError(429 if 'limit' in json.dumps(error).lower() else 502, 'ChatGPT: ' + str(error.get('message', error))[:300])
            if method == 'turn/completed':
                result = params.get('turn') or {}
                if result.get('status') != 'completed':
                    error = json.dumps(result.get('error') or {}, ensure_ascii=False)
                    raise BridgeError(429 if 'limit' in error.lower() else 502, 'ChatGPT не завершил ответ: ' + error[:300])
                break
        if not text.strip():
            raise BridgeError(502, 'ChatGPT вернул пустой ответ.')
        return {
            'object': 'chat.completion',
            'model': model or 'default',
            'choices': [{'index': 0, 'message': {'role': 'assistant', 'content': text}, 'finish_reason': 'stop'}],
        }
    finally:
        if server:
            server.close()
        shutil.rmtree(workdir, ignore_errors=True)


def account():
    """Вошёл ли Codex в ChatGPT: «codex login status» с кодом 0."""
    result = subprocess.run([CODEX, 'login', 'status'], capture_output=True, text=True, timeout=30)
    return result.returncode == 0, (result.stdout + result.stderr).strip()[:200]


class Handler(BaseHTTPRequestHandler):
    server_version = 'IndexPilotBridge/1.0'

    def reply(self, status, data):
        payload = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def fail(self, status, message):
        self.reply(status, {'error': {'message': message}})

    def allowed(self):
        given = self.headers.get('Authorization', '')
        try:
            expected = 'Bearer ' + token()
        except OSError:
            return False
        return len(expected) > 20 and hmac.compare_digest(given.encode(), expected.encode())

    def do_GET(self):  # noqa: N802 — имя задаёт http.server
        if not self.allowed():
            return self.fail(401, 'Неверный токен моста.')
        if self.path.rstrip('/') != '/v1/models':
            return self.fail(404, 'Нет такого адреса.')
        ok, status = account()
        if not ok:
            return self.fail(503, 'Codex не вошёл в ChatGPT: ' + status)
        return self.reply(200, {'object': 'list', 'data': [{'id': 'default', 'object': 'model'}]})

    def do_POST(self):  # noqa: N802
        if not self.allowed():
            return self.fail(401, 'Неверный токен моста.')
        if self.path.rstrip('/') != '/v1/chat/completions':
            return self.fail(404, 'Нет такого адреса.')
        length = int(self.headers.get('Content-Length') or 0)
        if length <= 0 or length > MAX_BODY:
            return self.fail(413, 'Запрос слишком большой.')
        try:
            body = json.loads(self.rfile.read(length).decode('utf-8'))
        except ValueError:
            return self.fail(400, 'Тело запроса — не JSON.')
        if not SLOTS.acquire(timeout=60):
            return self.fail(429, 'Мост занят другими запросами. Повторите через минуту.')
        try:
            return self.reply(200, complete(body))
        except BridgeError as error:
            return self.fail(error.status, str(error))
        except Exception as error:  # noqa: BLE001 — ответ клиенту вместо обрыва соединения
            print('bridge error:', repr(error), file=sys.stderr, flush=True)
            return self.fail(500, 'Ошибка моста: ' + type(error).__name__)
        finally:
            SLOTS.release()

    def log_message(self, fmt, *args):
        # В журнал — только адрес и код ответа, без текста статей.
        sys.stderr.write('%s %s\n' % (self.log_date_time_string(), fmt % args))


def main():
    host, port = LISTEN.rsplit(':', 1)
    server = ThreadingHTTPServer((host, int(port)), Handler)
    server.daemon_threads = True
    print('IndexPilot bridge on http://%s:%s' % (host, port), flush=True)
    server.serve_forever()


if __name__ == '__main__':
    main()
