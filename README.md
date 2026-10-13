# Poznote — TPK-пакет для TerraMaster (TOS6/TOS7)

> **Язык:** Русский · [English](README.en.md)

<div align="center">

[![Version](https://img.shields.io/badge/Версия-6.117.0.0beta-blue.svg)]()
[![Platform](https://img.shields.io/badge/Платформа-TOS-blue.svg)]()
[![License](https://img.shields.io/badge/Лицензия-MIT-blue.svg)](LICENSE)

  <img width="150" alt="Poznote logo" src="/images/poznote-icon.png">
  <h3>Poznote</h3>
  Мощные заметки без лишней сложности.<br/>
  Свободная self-hosted замена Notion, Obsidian, Evernote и OneNote.
  <br/><br/>
  <img width="800" alt="Интерфейс Poznote" src="/images/PoznoteForTos.png">
</div>

- Готовый пакет **Poznote 6.117.0.0-beta** для x86_64 NAS TerraMaster (TOS6/TOS7).
- В этом репозитории — релизы, дерево пакета, инструменты сборки и всё необходимое для пересборки.

## Авторы

- **Приложение:** [Poznote](https://github.com/timothepoznanski/poznote) — автор [Timothé Poznanski](https://poznote.com).
- **Упаковка под TOS (TPK-модуль):** [Mr712](https://github.com/byMr712?tab=repositories).
- Структура модуля вдохновлена пакетами **OutkastM** (TerraMaster Community Place).
- **Внутри пакета нет самого приложения** — это обёртка над официальными
  Docker-образами [`ghcr.io/timothepoznanski/poznote`](https://github.com/timothepoznanski/poznote)
  и `ghcr.io/timothepoznanski/poznote-mcp`; они скачиваются на NAS при первом запуске.

## Отказ от ответственности

Пакет предоставляется **как есть**, без каких-либо гарантий, явных или подразумеваемых,
включая, но не ограничиваясь, подразумеваемыми гарантиями товарной пригодности, пригодности
для конкретной цели и ненарушения прав.

- Пакет — **бета**-сборка (6.117.0.0-beta) от участника сообщества и **не является** официальным
  релизом TerraMaster или Poznote.
- Используйте на свой страх и риск. Автор **не несёт ответственности** за потерю данных, сбои
  системы, простой или любые другие последствия установки и использования этого пакета.
- Всегда делайте резервные копии данных и конфигурации NAS перед установкой или обновлением.
- Нет гарантий, что пакет заработает на вашем конкретном оборудовании или версии TOS.
  Поддержка предоставляется только «по возможности».

## Лицензия и атрибуция

- **Poznote** распространяется под **MIT License**
  (Copyright © 2025 Timothé Poznanski). Полный текст: [`LICENSE`](LICENSE).
- Оригинальный TPK-модуль (упаковка под TOS) © Mr712.
- Уведомления об изменениях и атрибуция: [`NOTICE`](NOTICE).
- Логотип и скриншот приложения в `images/` взяты из проекта Poznote (MIT).
- Вложенный XZ Utils (`tools/xz/`) — стороннее свободное ПО — GPL-2.0+ (xz CLI) и
  0BSD (liblzma). См. `tools/xz/COPYING*`.

## Требования

- TerraMaster NAS, архитектура **x86_64**, TOS **6.0.420 или выше** (TOS6/TOS7).
- Установленный **Docker** (приложение **DockerEngine** из App Center TerraMaster) с
  плагином `docker compose`. В `config.ini` пакет объявлен с зависимостью `DockerEngine`,
  поэтому App Center сам предупредит, если Docker не установлен.
- Доступ к **`ghcr.io`** (GitHub Container Registry) при первом запуске — оттуда скачиваются
  образы. Если провайдер его блокирует, укажите зеркало через `POZNOTE_IMAGE`/`POZNOTE_MCP_IMAGE`
  (см. «Настройка портов и параметров») или заранее загрузите образы на NAS.
- Свободные порты **8040** и **8045** (служебный порт панели `8042` слушается только на
  localhost). Если порт занят, контейнеры не запустятся, а в журнале появится сообщение с
  именем процесса — порт можно сменить прямо на странице приложения.
- **PHP** — только для страницы-модуля (иконка **Poznote** на рабочем столе):
  - **TOS6** — PHP встроен в систему, доустанавливать ничего не нужно;
  - **TOS7** — нужно доустановить **PHP 7.4** (как и для штатных модулей TOS).

  Само приложение Poznote и Go-панель управления от PHP **не зависят** — они работают и
  без него. Без PHP не откроется только страница с иконки на рабочем столе (приложение всё
  равно доступно напрямую по `http://<NAS>:8040/`).

## Установка

1. Скачайте `Poznote_TOS7_TOS6_6.117.0.0-beta-x86_64.tpk`.
2. В TOS: **App Center → Настройки → Установить приложение вручную** (Install from file)
   и выберите `.tpk`.
3. Дождитесь установки. На рабочем столе появится иконка **Poznote**
   (если её нет — добавьте приложение через меню приложений).

Приложение не содержит образов внутри пакета: при первом запуске TOS скачает актуальные
образы Poznote и MCP из GitHub Container Registry.

## Интерфейсы

| Что | Адрес по умолчанию | Назначение |
|---|---|---|
| Poznote (приложение) | `http://<NAS>:8040/` | Само приложение для заметок. |
| MCP-сервер | `127.0.0.1:8045` | Только локально (для AI-интеграций). |
| Панель управления | `127.0.0.1:8042` | Служебный API/панель на хосте. |

## Управление приложением

**Страница приложения** (иконка **Poznote** на рабочем столе TOS) — это минимальный модуль
управления: статус, журнал и кнопки. Никакой работы с Docker вручную не требуется:
все действия модуль выполняет через `/etc/init.d/Poznote <команда>`.

- **Открыть Poznote** — переход в веб-интерфейс приложения;
- **Обновить образы** — скачивает свежие версии контейнеров Poznote и MCP и перезапускает их
  (если `ghcr.io` недоступен, операция отменяется с понятным сообщением, контейнеры
  продолжают работать на прежних образах);
- **Перезапустить / Запустить / Остановить**;
- **Пересоздать контейнеры** — пересоздаёт контейнеры, **данные сохраняются**;
- **Порты** — смена портов веб-интерфейса и MCP: значения сохраняются в `poznote.env`,
  после чего Poznote перезапускается; рядом показано, свободен ли порт, занят ли он нашими
  контейнерами или чужой программой (и кем именно);
- просмотр статуса и журнала службы, **Очистить журнал**.

Журнал намеренно не засоряется: частый опрос статуса панелью (`POZNOTE_QUIET=1`) не пишет
служебные строки в `/usr/local/Poznote/Poznote_start.log`.

Доступ к странице — только для учётной записи суперпользователя TOS (та же политика, что и
у штатных модулей).

## Настройка портов и параметров

Параметры можно переопределить в файле окружения `poznote.env`, который лежит в каталоге
конфигурации приложения:

- **TOS7** — `/usr/local/Poznote/config/poznote.env`;
- **TOS6** — `/usr/local/@APP_CONFIG/Poznote/poznote.env`.

Точный путь всегда можно узнать у службы: `/etc/init.d/Poznote configdir`.

```sh
POZNOTE_PORT=8040        # порт веб-интерфейса
POZNOTE_MCP_PORT=8045    # порт MCP (только localhost)
POZNOTE_PANEL_PORT=8042  # порт служебной панели (только localhost)
POZNOTE_IMAGE_TAG=6      # тег образов (6 = стабильный канал)
POZNOTE_DATA_DIR=/Volume1/Poznote/data   # каталог данных
# Полные имена образов — если ghcr.io недоступен, укажите зеркало:
# POZNOTE_IMAGE=docker.m.daocloud.io/ghcr.io/timothepoznanski/poznote
# POZNOTE_MCP_IMAGE=docker.m.daocloud.io/ghcr.io/timothepoznanski/poznote-mcp
```

Порты удобнее менять не вручную, а на странице приложения (карточка **Порты**): панель сама
запишет значения в `poznote.env` и перезапустит Poznote. Там же видно, свободен ли порт; если
он занят, служба не станет запускать контейнеры, а напишет в журнал, какая программа держит
порт.

По умолчанию данные лежат в `MAIN_VOLUME/Poznote/data` и сохраняются при пересоздании
контейнеров. Если порт сменён, ссылка **Открыть Poznote** переключается автоматически.

## Работа через внешний прокси

Приложение рассчитано на прямой доступ по порту, отдельному поддомену или корню домена и
доверяет заголовкам `X-Forwarded-Proto/Host/Port/SSL`.
**Сабпас (например `/poznote/`) не поддерживается.** Пример nginx:

```nginx
location / {
    proxy_pass http://127.0.0.1:8040;
    proxy_set_header Host              $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host  $host;
    proxy_set_header X-Forwarded-Port  $server_port;
}
```

## Обновление

- **Образы приложения:** кнопка **Обновить образы** на странице приложения
  (то же самое делает `/etc/init.d/Poznote update`).
- **Сам пакет:** установите новую версию `.tpk` поверх старой через App Center —
  служба сама пересоберёт контейнеры и зарегистрирует модуль заново, перезагрузка NAS не нужна.
- При обновлении поверх прежней установки `poznote.env` **не перезаписывается** — ваши порты
  и другие настройки сохраняются.

## Данные, резервные копии и удаление

- Данные приложения (база SQLite, вложения) лежат в каталоге данных
  (по умолчанию `MAIN_VOLUME/Poznote/data`) и **не удаляются** при пересоздании контейнеров.
- Перед удалением пакета сделайте копию каталога данных в безопасное место (лучше вне NAS).
- **Пересоздать контейнеры** (`reset`) — безопасная операция: контейнеры пересоздаются,
  данные остаются на месте.
- Полное удаление: удалите пакет через App Center и вручную удалите каталог данных.

## Содержимое папки

| Путь | Описание |
|---|---|
| `PoznoteTOS/` | Дерево пакета (payload): `config.ini`, `Poznote.lang`, `INFO`, `version`, `bin/`, `functions/`, `images/`, `init.d/`, `webui.bz2` |
| `webui/` | Исходники TOS-модуля (страница приложения на рабочем столе), собираются в `PoznoteTOS/webui.bz2` |
| `tools/build_all.ps1` | Штатная сборка одной командой: модуль + панель + `.tpk` |
| `tools/build_module.ps1` | Сборка `webui.bz2` из `webui/` (утилита `modulemake` + `xz`) |
| `tools/build_panel.ps1` | Кросс-сборка Go-панели в `PoznoteTOS/bin/program/poznote-panel` |
| `tools/build_tpk.ps1` | Сборка одного `.tpk` из дерева `PoznoteTOS/` |
| `tools/panel/` | Исходники Go-панели управления (`main.go`, `index.html`) |
| `tools/modulemake/` | Go-утилита: GNU-tar → контейнер формата `webui.bz2` |
| `tools/tarmake/` | Go-утилита: создаёт GNU-tar с нужными правами (`root:root`) для payload `.tpk` |
| `tools/tarscan/` | Go-утилита: разбор/проверка готового tar (диагностика) |
| `tools/xz/` | Вложенный `xz.exe` + `liblzma-5.dll` + лицензии XZ Utils |
| `tools/go.work` | Go workspace: позволяет собирать утилиты из папки `tools\` |
| `sources/` | Upstream-исходники Poznote (в git не попадают, для сборки `.tpk` не нужны) |
| `images/` | Логотип и скриншот для README |
| `README.md` / `README.en.md` | README на русском (по умолчанию) и английском |
| `LICENSE` / `NOTICE` | Лицензия и атрибуция |
| `.gitignore` / `.gitattributes` | Исключения git |

## Структура .tpk

```
[0..2048)      JSON-заголовок; поле "md5" = md5(payload.tar.xz), дополнен нулями
[2048..10240)  содержимое Poznote.lang, дополнено нулями до 8192
[10240..конец) payload.tar.xz (magic FD 37 7A 58 5A 00)
```

Ключевые факты формата (подтверждены разбором оригинальных пакетов OutkastM):
- md5 в JSON-заголовке — это md5 **всего** payload.tar.xz (не самого tar).
- Payload — tar в формате **GNU** (`ustar `), owner/group `root:root`.
- `INFO` — список `1:file:<путь>:<md5>` / `1:folder:<путь>:`, md5 = md5 содержимого файла.
- `config.ini` присутствует в payload, но **не** перечисляется в `INFO`.

Формат `webui.bz2` (TOS-модуль) — `xz` от обычного GNU-tar (без BOM и UTF-16),
владелец `root:root`, каталоги 0755, файлы 0644.

## Что требуется для сборки

- **PowerShell 7+** (`pwsh`) — сборка пакета на Windows.
- **Go** (проверено: 1.27.0) — сборка панели и утилит. Каталог передаётся флагом `-GoRoot`
  (например `-GoRoot E:\go1.27.0`). https://go.dev/dl/
- **Python** — опционально (резервный сборщик tar, если Go недоступен).
- **Windows:** `xz.exe` вложен в `tools/xz/`, отдельная установка не нужна; если его нет,
  скрипт ищет `C:\Program Files\Git\mingw64\bin\xz.exe`.
- Сборка `.tpk` **не требует** Docker и NAS: контейнеры подтягиваются из `ghcr.io` уже
  на устройстве.

## Лицензия xz.exe

XZ Utils — свободное ПО. `xz.exe` (CLI) распространяется под **GNU GPL v2+**, а библиотека
`liblzma-5.dll` — под **0BSD** (public domain). Поэтому рядом с бинарниками лежат файлы
лицензий: `COPYING`, `COPYING.0BSD`, `COPYING.GPLv2` и `AUTHORS`. xz нужен только для сборки
и в сам `.tpk` не попадает. Если будете распространять папку `tools/` дальше — сохраняйте эти
файлы.

## Быстрая пересборка .tpk из существующего дерева

Правьте файлы в `PoznoteTOS/` (например `bin/compose/docker-compose.yml`, `version` — версия
пакета, `config.ini` и `Poznote.lang` — название/версия в App Center), затем:

```powershell
pwsh .\tools\build_tpk.ps1
```

Скрипт сам:
1. пересчитает `INFO` (md5 всех файлов дерева);
2. соберёт `payload.tar` (tarmake, права root:root);
3. сожмёт `xz -9e` → `payload.tar.xz`;
4. соберёт `.tpk` (заголовок + .lang + payload) в родительскую папку;
5. имя файла и версия в заголовке берутся из `config.ini`.

Имя результата — `<id>_TOS7_TOS6_<версия>[-<суффикс>]-<платформа>.tpk`, например
`Poznote_TOS7_TOS6_6.117.0.0-beta-x86_64.tpk`.

Все пути по умолчанию — **относительные, от папки скрипта** `tools\`: дерево пакета
`..\PoznoteTOS`, результат `..`. Промежуточные файлы создаются во временной подпапке
`tools\_build\` и удаляются после сборки. Запускать можно из любого места.

Опции: `-PkgDir <путь>` (по умолчанию `..\PoznoteTOS`), `-OutDir <куда положить .tpk>`
(по умолчанию `..`), `-XzPath <путь к xz.exe>`, `-ReleaseTag <суффикс версии в имени файла>`
(по умолчанию `beta`; для стабильного релиза `-ReleaseTag ''`).

## Пересборка панели и модуля

Штатная сборка всего сразу:

```powershell
cd E:\GitHub\PozNoteForTosDockerEdition
pwsh -NoProfile -File .\tools\build_all.ps1 -GoRoot E:\go1.27.0
```

Отдельные стадии:

```powershell
pwsh .\tools\build_module.ps1 -GoRoot E:\go1.27.0   # webui/ -> PoznoteTOS/webui.bz2
pwsh .\tools\build_panel.ps1  -GoRoot E:\go1.27.0   # tools/panel -> PoznoteTOS/bin/program/poznote-panel
pwsh .\tools\build_tpk.ps1                          # PoznoteTOS/ -> Poznote_..._x86_64.tpk
```

Флаги `build_all.ps1`: `-GoRoot <каталог Go>`, `-SkipModule`, `-SkipPanel`
(если исходники модуля/панели не менялись) и `-ReleaseTag <суффикс>`.

**Модуль страницы** (`webui/index.php`) — самодостаточный PHP, совместимый с **PHP 7.4+**
(без конструкций PHP 8). Для отображения страницы нужен PHP на NAS: на TOS6 он встроен,
на TOS7 нужно установить PHP 7.4 (та же зависимость, что у модулей OutkastM). Само
приложение и Go-панель от PHP не зависят.

**Панель управления** (`tools/panel`) — Go-бинарник; служба запускает его под root на хосте
NAS на `127.0.0.1:8042` (флаг `-listen`) и общается с ним по HTTP.

## Конфигурация приложения

- `bin/compose/docker-compose.yml` — контейнеры `poznote` (веб) и `poznote-mcp`.
  Образы: `ghcr.io/timothepoznanski/poznote` и `.../poznote-mcp`, тег `POZNOTE_IMAGE_TAG`
  (по умолчанию `6` — стабильный канал). Данные — том `${POZNOTE_DATA}:/var/www/html/data`.
- `init.d/service` — служба TOS: `start|stop|reload|update|status|reset|clearlog|configdir|ports|inst_version|dep_check`.
  При старте проверяет наличие Docker, занятость портов и доступность реестра; при конфликте
  порта или недоступном `ghcr.io` контейнеры **не** поднимаются, но панель управления
  запускается, а причина пишется в `/usr/local/Poznote/Poznote_start.log`.
- `bin/Poznote.nginx.conf` — сниппет `AppAccessControl` (`auth_request` → `127.0.0.1:5057/validate`),
  доступ к странице только для суперпользователя TOS.
- `functions/dependapps.sh` — хелперы зависимостей TOS (из эталонных пакетов OutkastM).
- `bin/program/poznote-panel` — Go-панель управления (собирается `build_panel.ps1`).
- `version` — версия пакета (`<версия upstream-приложения>.0`), меняется синхронно с
  `config.ini` и `Poznote.lang`.
- `webui.bz2` — TOS-модуль страницы приложения (собирается `build_module.ps1`).

## Права файлов в payload (как в оригинале)

| Файл | Права |
|---|---|
| `INFO`, `init.d/service` | 0755 |
| `bin/program/*`, `functions/*.sh` | 0744 |
| каталоги | 0755 |
| остальные файлы | 0644 |

Все файлы принадлежат `root:root`.

## Проверка результата

```powershell
# 1. md5 в заголовке должен совпадать с md5 payload (смещение 10240)
$b = [IO.File]::ReadAllBytes("$pwd\Poznote_TOS7_TOS6_6.117.0.0-beta-x86_64.tpk")
# 2. payload извлекается, и права/состав совпадают с деревом пакета
#    (вырезать байты с offset 10240 и посмотреть tar -tvf)
```

На NAS:

```sh
/etc/init.d/Poznote status       # состояние, порты, контейнеры
/etc/init.d/Poznote ports        # кто держит порты (free / ours / foreign <кто>)
/etc/init.d/Poznote configdir    # где лежит poznote.env
```

## Ссылки

- Приложение Poznote: <https://github.com/timothepoznanski/poznote> · <https://poznote.com>
- Демо: <https://demo.poznote.com> (логин/пароль `poznote`)
- Документация приложения: `sources/docs/` (в т.ч. `README.ru.md`)
