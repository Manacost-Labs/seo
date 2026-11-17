# Мост к ChatGPT по подписке

ИИ-помощник IndexPilot (кнопка «Заполнить с ИИ» в панели записи) умеет работать по подписке ChatGPT, без ключа OpenAI API.
Для этого на сервере сайта работает этот мост. Он принимает запросы в формате OpenAI Chat Completions на `127.0.0.1:8787`
и выполняет каждый в отдельном временном диалоге `codex app-server`. Codex при этом вошёл в аккаунт ChatGPT.

У модели остаются только текст и картинки. Shell, браузер, плагины, MCP и остальные инструменты выключены, диалоги не сохраняются.
Служба работает от отдельного пользователя `indexpilot-ai`: каталоги сайтов, `/home` и настройки сервера ей недоступны.

## Установка (Debian, systemd)

Нужны Codex CLI (`npm i -g @openai/codex`) и Python 3.9+.

```sh
sudo useradd --system --home-dir /var/lib/indexpilot-ai --create-home --shell /usr/sbin/nologin indexpilot-ai
sudo install -d -m 755 /opt/indexpilot-ai
sudo install -m 755 bridge.py /opt/indexpilot-ai/bridge.py
sudo install -m 644 indexpilot-ai.service /etc/systemd/system/indexpilot-ai.service
sudo install -d -m 750 -o root -g indexpilot-ai /etc/indexpilot-ai
python3 -c "import secrets;print(secrets.token_hex(24))" | sudo tee /etc/indexpilot-ai/token >/dev/null
sudo chown root:indexpilot-ai /etc/indexpilot-ai/token && sudo chmod 640 /etc/indexpilot-ai/token

# Вход в ChatGPT: команда покажет ссылку и код, их нужно ввести в браузере.
cd /var/lib/indexpilot-ai
sudo -u indexpilot-ai env HOME=/var/lib/indexpilot-ai CODEX_HOME=/var/lib/indexpilot-ai/.codex codex login --device-auth

sudo systemctl daemon-reload && sudo systemctl enable --now indexpilot-ai
```

Затем в WordPress откройте IndexPilot → Техника → ИИ-помощник. Выберите «ChatGPT по подписке», в поле ключа вставьте токен
из `/etc/indexpilot-ai/token` и нажмите «Сохранить».

## Проверка

```sh
T=$(sudo cat /etc/indexpilot-ai/token)
curl -s -H "Authorization: Bearer $T" http://127.0.0.1:8787/v1/models   # 200 — Codex вошёл в ChatGPT
sudo journalctl -u indexpilot-ai -n 20                                  # в журнал пишутся только адреса и коды ответов
```

Мост на другом адресе указывается в `wp-config.php`: `define( 'INDEXPILOT_AI_BRIDGE', 'http://127.0.0.1:8787/v1' );`.

Запросы по подписке расходуют лимиты Codex в вашем тарифе ChatGPT.
