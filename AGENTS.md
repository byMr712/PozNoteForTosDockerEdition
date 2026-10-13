# AGENTS.md

Инструкции для ИИ-агентов и разработчиков по сборке/обновлению TPK-пакета
**Poznote** для TerraMaster (TOS6/TOS7).

Репозиторий: `E:\GitHub\PozNoteForTosDockerEdition`

> **Важно:** версия пакета **не захардкожена** в этом документе — она берётся
> из `PoznoteTOS/version` и `PoznoteTOS/config.ini`. Не подставляйте версию
> «на глаз».

---

## 1. Общая структура репозитория

| Путь | Что это |
|---|---|
| `PoznoteTOS/` | **Дерево пакета (payload)** — единственное, что попадает в `.tpk`. |
| `webui/` | **Исходники TOS-модуля** (страница приложения на рабочем столе). Собираются в `PoznoteTOS/webui.bz2`. |
| `tools/build_all.ps1` | **Штатная сборка**: модуль + панель + `.tpk` одной командой. |
| `tools/build_module.ps1` | Сборка `webui.bz2` из `webui/` (утилита `modulemake` + `xz`). |
| `tools/build_panel.ps1` | Кросс-сборка Go-панели управления в `PoznoteTOS/bin/program/poznote-panel`. |
| `tools/build_tpk.ps1` | Сборка одного `.tpk` из `PoznoteTOS/`. |
| `tools/panel/` | Исходники Go-панели управления (`main.go`, `index.html`). |
| `tools/modulemake/` | Go-утилита: GNU-tar → UTF-16LE-контейнер (формат `webui.bz2`). |
| `tools/tarmake/` | Go-утилита: GNU-tar с нужными правами (`root:root`) для payload `.tpk`. |
| `tools/xz/` | Вложенный `xz.exe` + `liblzma-5.dll` + лицензии XZ Utils. |
| `sources/` | **Upstream-исходники Poznote** (не нужны для сборки `.tpk`; игнорируются git). |
| `*.tpk` | Готовые к установке пакеты. |

`PoznoteTOS/` детально:

| Путь | Права в tar | Назначение |
|---|---|---|
| `INFO` | 0755 | Реестр файлов с md5 (генерируется `build_tpk.ps1` автоматически). |
| `config.ini` | 0644 | Регистрация приложения в TOS (id, icon, type, path, version, platform…). |
| `version` | 0644 | Версия пакета (используется TOS). |
| `Poznote.lang` | 0644 | Локализация (name/auth/version/descript по языкам). |
| `init.d/service` | 0755 | Скрипт службы: `start|stop|reload|update|status|reset|clearlog|configdir|ports|inst_version|dep_check`. Управляет Docker Compose и запускает панель. `configdir`/`ports` — служебные, для страницы приложения. |
| `functions/dependapps.sh` | 0744 | Хелперы зависимостей TOS (из эталонного пакета OutkastM). |
| `bin/compose/docker-compose.yml` | 0644 | Контейнеры `poznote` + `poznote-mcp`. |
| `bin/program/poznote-panel` | 0744 | Go-панель управления (собирается `build_panel.ps1`). |
| `images/icons/Poznote.png` | 0644 | Иконка приложения на рабочем столе TOS. |
| `webui.bz2` | 0644 | TOS-модуль страницы приложения (собирается `build_module.ps1`). |

---

## 2. Требования к сборке (Windows)

- **PowerShell 7+** (`pwsh`).
- **Go** (например `E:\go1.27.0`; передаётся `-GoRoot`).
- `xz.exe` — уже лежит в `tools/xz/`.
- Python — опционально (резервный сборщик tar, если Go недоступен).

Сборка `.tpk` **не требует** Docker и NAS: контейнеры подтягиваются из
`ghcr.io` уже на устройстве.

---

## 3. Штатная сборка

```powershell
cd E:\GitHub\PozNoteForTosDockerEdition
pwsh -NoProfile -File .\tools\build_all.ps1 -GoRoot E:\go1.27.0
```

Скрипт последовательно:

1. собирает TOS-модуль → `PoznoteTOS/webui.bz2`;
2. собирает панель → `PoznoteTOS/bin/program/poznote-panel`;
3. собирает `.tpk` в корень репозитория.

Отдельные стадии:

```powershell
pwsh .\tools\build_module.ps1 -GoRoot E:\go1.27.0
pwsh .\tools\build_panel.ps1  -GoRoot E:\go1.27.0
pwsh .\tools\build_tpk.ps1
```

Флаги `build_all.ps1`: `-SkipModule`, `-SkipPanel`, `-ReleaseTag <суффикс>`
(по умолчанию `beta`; пустая строка — без суффикса).

Имя итогового файла:

```
Poznote_TOS7_TOS6_<version>[-<ReleaseTag>]-<platform>.tpk
```

---

## 4. Структура `.tpk` (проверено)

```
[0..2048)     JSON-заголовок (поле "md5" = md5 от payload.tar.xz), добит нулями
[2048..10240) содержимое Poznote.lang, добито нулями до 8192
[10240..end)  payload.tar.xz (magic FD 37 7A 58 5A 00)
```

`payload.tar` — GNU tar, владелец `root:root`. Права задаёт `tarmake`:

- каталоги, `INFO`, `init.d/service` — **0755**;
- `bin/program/*`, `functions/*.sh` — **0744**;
- остальное — **0644**.

Проверка готового пакета:

```powershell
# md5 заголовка == md5(payload) и корректный листинг tar
# (см. раздел «Проверка» в истории репозитория/CI)
```

---

## 5. Формат `webui.bz2` (TOS-модуль)

Разобран по эталонному модулю OutkastM и по факту нашей сборки:

```
webui.bz2 = xz( GNU-tar )
```

- Обычный GNU-tar (`modulemake`) без BOM и без UTF-16-развёртки: `xz -dc` даёт
  tar, читаемый `tar -tvf` (записи с префиксом `./`, каталоги — с `/`).
- Владелец `root:root`; каталоги 0755, файлы 0644.
- Сжимается `xz -9e`.

Собирает `tools/modulemake` (см. `tools/build_module.ps1`). Исходники модуля —
в `webui/`. Модуль отдаётся TOS как страница приложения (`path=/Poznote/`),
т.е. открывается иконкой Poznote на рабочем столе.

Модуль (`webui/index.php`) — самодостаточный PHP (совместим с **PHP 7.4+**,
  без конструкций PHP 8):

- **Требует PHP** на NAS для отображения страницы: на TOS6 он встроен, на TOS7
  нужно установить PHP 7.4 (та же зависимость, что у модулей OutkastM). Само
  приложение и Go-панель от PHP не зависят.
- доступ только для суперпользователя TOS (cookie `userName`/`loginStatus`,
  как в штатных модулях);
- показывает статус и журнал службы;
- кнопки: **Открыть Poznote / Обновить образы / Перезапустить / Запустить /
  Остановить / Пересоздать контейнеры / Очистить журнал**;
- карточка **Порты**: показывает, свободен ли порт, «занят Poznote» (его держат
  наши контейнеры — норма) или «занят» чужой программой с её именем (данные
  берёт у службы: `/etc/init.d/Poznote ports`); пишет новые значения в
  `poznote.env` (путь берёт у службы: `/etc/init.d/Poznote configdir`)
  и вызывает `reload`;
- действия вызывает через `/etc/init.d/Poznote <cmd>` (Docker-сокет не нужен).

---

## 6. Панель управления (`tools/panel`)

Go-бинарник, запускается службой `init.d/service` под root на хосте NAS.

- По умолчанию слушает `127.0.0.1:8042` (флаг `-listen`).
- Умеет: `start`, `stop`, `restart`, `update` (`pull` + `up -d --remove-orphans`,
  обновляет **оба** контейнера), показ статуса и журнала.
- UI встроен (`go:embed` `index.html`), интерфейс на русском, авто-обновление.
- Токен доступа — env `POZNOTE_PANEL_TOKEN` / флаг `-token` (`X-Auth-Token`
  или `?token=`). Без токена доступны только запросы с loopback.

Флаги окружения (см. `init.d/service`): `POZNOTE_PORT` (8040),
`POZNOTE_MCP_PORT` (8045), `POZNOTE_PANEL_PORT` (8042), `POZNOTE_IMAGE_TAG` (6),
`POZNOTE_DATA_DIR`, а также полные ссылки на образы `POZNOTE_IMAGE` /
`POZNOTE_MCP_IMAGE` (по умолчанию `ghcr.io/timothepoznanski/poznote[-mcp]`) —
через них подключается зеркало реестра. Переопределяются в
`$MODCONFIGPATH/poznote.env`.

---

## 7. Обновление версии

Версия пакета = `<версия upstream-приложения>.0` (например приложение
`6.117.0` → пакет `6.117.0.0`; при пересборке без изменения приложения версия упаковки
поднимается на последнюю цифру, например `6.117.0.7`). Менять **синхронно** в:

1. `PoznoteTOS/version`
2. `PoznoteTOS/config.ini` → `"version"`
3. `PoznoteTOS/Poznote.lang` → `version = "…"` (во всех языковых блоках)
4. `PoznoteTOS/init.d/service` → `VERSION="…"`

Тег образа (`POZNOTE_IMAGE_TAG`) соответствует мажорной линии upstream
(`6` = стабильный канал).

---

## 8. Ограничения и заметки

- Сабпас (`/poznote/`) приложение **не поддерживает** — только корень,
  отдельный порт или поддомен. Приложение доверяет заголовкам
  `X-Forwarded-Proto/Host/Port/SSL`.
- `auth.php` намеренно использует `REMOTE_ADDR` (не `X-Forwarded-For`).
- `depend` в `config.ini` = `["DockerEngine"]` (TOS сам подскажет установить
  Docker). Проверки при старте (`init.d/service`):
  - `check_docker` — наличие `docker`/`compose` (ищет бинарь DockerEngine вне PATH);
  - `check_ports` — порты 8040/8045 заняты? Сообщает, кто держит порт, и **не**
    поднимает контейнеры, чтобы не было непонятного падения compose; панель при
    этом остаётся работать, чтобы порт можно было сменить;
  - `check_registry` — доступность `ghcr.io` (или зеркала) перед первым запуском
    и перед `update`; при неудаче — понятное сообщение, ничего не ломается.
- Коды возврата как в эталоне: `exit ${RETVAL}`, где `RETVAL` задаётся только в
  `status` (0 — работает, 3 — остановлено). Ошибки старта (нет Docker, занятый
  порт, нет связи с реестром) намеренно **не** делают install «failed»: TOS не
  снимает иконку, а причина видна в журнале и на странице приложения.
- Пакет не делает коммитов и не трогает git автоматически.
