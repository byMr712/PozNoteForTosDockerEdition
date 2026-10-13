<?php
/*
 * Poznote — модуль управления для TerraMaster TOS (6/7).
 *
 * Назначение:
 *   - открывается иконкой Poznote на рабочем столе TOS (path=/Poznote/);
 *   - показывает статус контейнеров и журнал службы;
 *   - позволяет обновить образы, перезапустить, запустить и остановить Poznote;
 *   - позволяет сменить порты (карточка «Порты»): значения пишутся в
 *     poznote.env, файл настроек службы, после чего Poznote перезапускается.
 *
 * Действия выполняются через штатный init-скрипт пакета
 * (/etc/init.d/Poznote), который, в свою очередь, управляет Docker Compose.
 * Модуль НЕ обращается к Docker напрямую и не зависит от его сокета.
 *
 * Интерфейс асинхронный: действия выполняются AJAX-запросом, во время работы
 * показываются спиннер, название действия и секундомер, а журнал службы
 * обновляется «на лету» опросом ?api=status. Без JavaScript формы продолжают
 * работать обычным POST.
 *
 * Доступ: только для учётной записи суперпользователя TOS (та же политика,
 * что и в штатных модулях: cookie userName/loginStatus).
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', '0');
@set_time_limit(0);
@ignore_user_abort(true);

/* ------------------------------------------------------------------ */
/* Параметры                                                          */
/* ------------------------------------------------------------------ */

$MODULE    = 'Poznote';
$LOG_FILE  = '/usr/local/Poznote/Poznote_start.log';            // журнал службы
$INITD     = '/etc/init.d/Poznote';                              // init-скрипт пакета
if (!is_file($INITD)) {
    $INITD = '/usr/local/Poznote/init.d/service';
}
$LOG_LINES = 200;

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

/* ------------------------------------------------------------------ */
/* Хелперы                                                            */
/* ------------------------------------------------------------------ */

function poz_htmlesc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function poz_json(array $data): void
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function poz_run(string $cmd): string
{
    $out = @shell_exec($cmd . ' 2>&1');
    return $out === null ? '' : (string)$out;
}

function poz_log_tail(string $file, int $n): string
{
    if ($n < 1) { $n = 1; }
    if ($n > 500) { $n = 500; }
    if (!is_readable($file)) { return ''; }
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines) || !$lines) { return ''; }
    return implode("\n", array_slice($lines, -$n));
}

function poz_run_quiet(string $cmd): string
{
    // POZNOTE_QUIET stops the init script from writing routine lines to the
    // service log. Used for the panel's frequent status/log polling so it does
    // not flood the journal.
    $out = @shell_exec('POZNOTE_QUIET=1 ' . $cmd . ' 2>&1');
    return $out === null ? '' : (string)$out;
}

function poz_status(string $initd): string
{
    return trim(poz_run_quiet($initd . ' status'));
}

function poz_running(string $status): bool
{
    return $status !== ''
        && stripos($status, 'running') !== false
        && stripos($status, 'stopped') === false;
}

/* ------------------------------------------------------------------ */
/* Язык интерфейса (ru/en): cookie → ?lang= → en (по умолчанию — англ.)  */
/* ------------------------------------------------------------------ */

/*
 * Словарь страницы: одна строка на ключ, без апострофов и обратных слэшей —
 * так его читают и глазами, и простым парсером (см. инструмент превью).
 * Подстановки — {0}, {1}…: их заменяет poz_t(). Порядок ключей в ru и en
 * одинаковый, поэтому словари удобно сверять построчно.
 */
$POZ_STRINGS = array(
    'ru' => array(
        'page_title' => 'Poznote — панель управления',
        'tagline' => 'Заметки, папки, задачи и поиск — на вашем NAS',
        'theme_dark' => 'Тёмная тема',
        'theme_light' => 'Светлая тема',
        'theme_aria' => 'Переключить тему',
        'lang_other' => 'Переключить на английский',
        'lang_aria' => 'Переключить язык (RU/EN)',
        'running' => 'Работает',
        'stopped' => 'Остановлен',
        'open_app' => 'Открыть Poznote',
        'progress_default' => 'Выполняется…',
        'progress_running' => 'Выполняется: {0}',
        'op_fallback' => 'операция',
        'seconds' => '{0} с',
        'card_control' => 'Управление',
        'hint_control' => 'Служба управляет контейнерами Poznote и MCP',
        'group_service' => 'Служба',
        'group_maintenance' => 'Обслуживание',
        'btn_start' => 'Запустить',
        'btn_start_label' => 'Запуск',
        'btn_start_hint' => 'Запускаются контейнеры и панель управления.',
        'btn_restart' => 'Перезапустить',
        'btn_restart_label' => 'Перезапуск',
        'btn_restart_hint' => 'Служба останавливается и запускается заново.',
        'btn_stop' => 'Остановить',
        'btn_stop_label' => 'Остановка',
        'btn_stop_hint' => 'Останавливаются контейнеры Poznote и MCP.',
        'btn_update' => 'Обновить образы',
        'btn_update_label' => 'Обновление образов',
        'btn_update_hint' => 'Скачиваются свежие образы Poznote и MCP, затем контейнеры перезапускаются. Это может занять несколько минут.',
        'btn_reset' => 'Пересоздать контейнеры',
        'btn_reset_label' => 'Пересоздание контейнеров',
        'btn_reset_hint' => 'Контейнеры удаляются и создаются заново. Данные (SQLite, вложения) сохраняются.',
        'note_ui' => 'Веб-интерфейс:',
        'note_update_1' => '«Обновить образы» скачивает свежие версии контейнеров Poznote и MCP и перезапускает их.',
        'note_update_2' => 'Во время операции журнал ниже обновляется автоматически.',
        'card_ports' => 'Порты',
        'hint_ports' => 'Панель запишет значения в poznote.env и перезапустит Poznote',
        'label_web' => 'Веб-интерфейс',
        'label_mcp' => 'MCP-сервер (127.0.0.1)',
        'btn_saveports' => 'Сохранить и перезапустить',
        'btn_saveports_label' => 'Смена портов',
        'btn_saveports_hint' => 'Настройки сохраняются в poznote.env, затем Poznote перезапускается на новых портах.',
        'note_ports_1' => 'Если порт занят другой программой (или недоступен из-за фаервола), укажите здесь свободный — панель сама запишет его в настройки и перезапустит Poznote.',
        'note_ports_2' => 'Порты 8040 и 8045 должны быть свободны; панель управления использует внутренний порт {0} (только localhost).',
        'card_status' => 'Статус',
        'refresh' => 'Обновить статус',
        'refresh_title' => 'Обновить статус и журнал',
        'no_data' => 'Нет данных',
        'card_log' => 'Журнал службы',
        'log_hint' => '· последние {0} строк',
        'log_empty' => 'Журнал пуст.',
        'btn_clear' => 'Очистить',
        'btn_clear_label' => 'Очистка журнала',
        'btn_clear_hint' => 'Журнал службы будет очищен (только файл журнала, контейнеры не затрагиваются).',
        'role_web' => 'Веб',
        'role_mcp' => 'MCP',
        'port_free' => '{0} {1} — свободен',
        'port_ours' => '{0} {1} — занят Poznote',
        'port_foreign' => '{0} {1} — занят',
        'denied_title' => 'Poznote — доступ запрещён',
        'denied_h' => 'Доступ запрещён',
        'denied_p' => 'Войдите в TOS под учётной записью суперпользователя, созданной при настройке NAS, и откройте модуль повторно.',
        'denied_ajax' => 'Доступ запрещён. Войдите в TOS под суперпользователем.',
        'err_web_port' => 'Порт веб-интерфейса должен быть числом от 1 до 65535.',
        'err_mcp_port' => 'Порт MCP-сервера должен быть числом от 1 до 65535.',
        'err_same_port' => 'Порт веб-интерфейса и порт MCP должны различаться.',
        'err_panel_port' => 'Порты не должны совпадать с портом панели управления ({0}).',
        'err_web_busy' => 'Порт {0} уже занят{1}. Выберите другой порт.',
        'err_mcp_busy' => 'Порт {0} уже занят{1}. Выберите другой порт.',
        'ok_ports_saved' => 'Порты сохранены ({0} / {1}), Poznote перезапущен.',
        'err_env_write' => 'Не удалось записать {0}. Проверьте права на каталог настроек.',
        'msg_start' => 'Poznote запущен.',
        'msg_stop' => 'Poznote остановлен.',
        'msg_restart' => 'Poznote перезапущен.',
        'msg_update' => 'Образы обновлены, контейнеры перезапущены.',
        'msg_reset' => 'Контейнеры пересозданы (данные сохранены).',
        'msg_clearlog' => 'Журнал службы очищен.',
        'msg_unknown' => 'Неизвестное действие.',
        'done' => 'Готово.',
        'error' => 'Ошибка.',
        'ajax_fail' => 'Не удалось выполнить запрос: {0}',
    ),
    'en' => array(
        'page_title' => 'Poznote — control panel',
        'tagline' => 'Notes, folders, tasks and search — on your NAS',
        'theme_dark' => 'Dark theme',
        'theme_light' => 'Light theme',
        'theme_aria' => 'Switch theme',
        'lang_other' => 'Switch to Russian',
        'lang_aria' => 'Switch language (RU/EN)',
        'running' => 'Running',
        'stopped' => 'Stopped',
        'open_app' => 'Open Poznote',
        'progress_default' => 'Working…',
        'progress_running' => 'Working: {0}',
        'op_fallback' => 'operation',
        'seconds' => '{0} s',
        'card_control' => 'Control',
        'hint_control' => 'The service manages the Poznote and MCP containers',
        'group_service' => 'Service',
        'group_maintenance' => 'Maintenance',
        'btn_start' => 'Start',
        'btn_start_label' => 'Starting',
        'btn_start_hint' => 'The containers and the control panel are started.',
        'btn_restart' => 'Restart',
        'btn_restart_label' => 'Restarting',
        'btn_restart_hint' => 'The service is stopped and started again.',
        'btn_stop' => 'Stop',
        'btn_stop_label' => 'Stopping',
        'btn_stop_hint' => 'The Poznote and MCP containers are stopped.',
        'btn_update' => 'Update images',
        'btn_update_label' => 'Updating images',
        'btn_update_hint' => 'Fresh Poznote and MCP images are pulled and the containers are restarted. This may take a few minutes.',
        'btn_reset' => 'Recreate containers',
        'btn_reset_label' => 'Recreating containers',
        'btn_reset_hint' => 'The containers are removed and created again. Your data (SQLite, attachments) is kept.',
        'note_ui' => 'Web UI:',
        'note_update_1' => 'Update images pulls the latest Poznote and MCP container images and restarts them.',
        'note_update_2' => 'While the operation runs, the log below refreshes automatically.',
        'card_ports' => 'Ports',
        'hint_ports' => 'The panel writes the values to poznote.env and restarts Poznote',
        'label_web' => 'Web UI',
        'label_mcp' => 'MCP server (127.0.0.1)',
        'btn_saveports' => 'Save and restart',
        'btn_saveports_label' => 'Changing ports',
        'btn_saveports_hint' => 'The settings are written to poznote.env, then Poznote restarts on the new ports.',
        'note_ports_1' => 'If a port is held by another program (or blocked by the firewall), enter a free one — the panel saves it and restarts Poznote.',
        'note_ports_2' => 'Ports 8040 and 8045 must be free; the control panel uses the internal port {0} (localhost only).',
        'card_status' => 'Status',
        'refresh' => 'Refresh status',
        'refresh_title' => 'Refresh status and log',
        'no_data' => 'No data',
        'card_log' => 'Service log',
        'log_hint' => '· last {0} lines',
        'log_empty' => 'The log is empty.',
        'btn_clear' => 'Clear',
        'btn_clear_label' => 'Clearing the log',
        'btn_clear_hint' => 'The service log will be cleared (the log file only, containers are not touched).',
        'role_web' => 'Web',
        'role_mcp' => 'MCP',
        'port_free' => '{0} {1} — free',
        'port_ours' => '{0} {1} — held by Poznote',
        'port_foreign' => '{0} {1} — held',
        'denied_title' => 'Poznote — access denied',
        'denied_h' => 'Access denied',
        'denied_p' => 'Sign in to TOS with the superuser account created during NAS setup, then open the module again.',
        'denied_ajax' => 'Access denied. Sign in to TOS as the superuser.',
        'err_web_port' => 'The web UI port must be a number from 1 to 65535.',
        'err_mcp_port' => 'The MCP server port must be a number from 1 to 65535.',
        'err_same_port' => 'The web UI port and the MCP port must be different.',
        'err_panel_port' => 'The ports must not clash with the control panel port ({0}).',
        'err_web_busy' => 'Port {0} is already in use{1}. Choose another port.',
        'err_mcp_busy' => 'Port {0} is already in use{1}. Choose another port.',
        'ok_ports_saved' => 'Ports saved ({0} / {1}), Poznote restarted.',
        'err_env_write' => 'Could not write {0}. Check the permissions of the settings directory.',
        'msg_start' => 'Poznote started.',
        'msg_stop' => 'Poznote stopped.',
        'msg_restart' => 'Poznote restarted.',
        'msg_update' => 'Images updated, the containers were restarted.',
        'msg_reset' => 'Containers recreated (data kept).',
        'msg_clearlog' => 'The service log has been cleared.',
        'msg_unknown' => 'Unknown action.',
        'done' => 'Done.',
        'error' => 'Error.',
        'ajax_fail' => 'Request failed: {0}',
    ),
);

/** Язык интерфейса: cookie poznote_lang → ?lang= → en (язык по умолчанию). */
function poz_lang(): string
{
    $allowed = array('ru', 'en');

    $want = '';
    if (!empty($_COOKIE['poznote_lang'])) {
        $want = (string)$_COOKIE['poznote_lang'];
    } elseif (!empty($_GET['lang'])) {
        $want = (string)$_GET['lang'];
    }
    $want = strtolower(str_replace('_', '-', trim($want)));
    $want = preg_replace('/[^a-z-].*$/', '', $want);
    if (in_array($want, $allowed, true)) {
        return $want;
    }
    $short = preg_replace('/-.*$/', '', $want);
    if (in_array($short, $allowed, true)) {
        return $short;
    }

    // Ни cookie, ни ?lang= ничего не выбрали: английский по умолчанию.
    return 'en';
}

$POZ_LANG = poz_lang();
$POZ_T    = $POZ_STRINGS[$POZ_LANG];

/** Строка интерфейса на языке страницы: poz_t('ключ', array('значение')). */
function poz_t(string $key, array $args = array()): string
{
    global $POZ_T;
    $s = isset($POZ_T[$key]) ? (string)$POZ_T[$key] : $key;
    foreach ($args as $i => $v) {
        $s = str_replace('{' . $i . '}', (string)$v, $s);
    }
    return $s;
}


/* ------------------------------------------------------------------ */
/* Настройки портов (poznote.env — тот же файл читает служба пакета)   */
/* ------------------------------------------------------------------ */

/**
 * Путь к poznote.env. Спрашиваем у самой службы (действие `configdir`),
 * чтобы не дублировать правила TOS6/TOS7; при неудаче — типовой путь TOS7.
 */
function poz_env_path(string $initd): string
{
    $dir = trim(poz_run_quiet($initd . ' configdir'));
    if ($dir === '' || strpos($dir, '/') !== 0 || strpos($dir, "\n") !== false) {
        $dir = is_dir('/usr/local/Poznote/config')
            ? '/usr/local/Poznote/config'
            : '/usr/local/@APP_CONFIG/Poznote';
    }
    return rtrim($dir, '/') . '/poznote.env';
}

/** Читает KEY=VALUE-файл службы (кавычки снимаются). */
function poz_env_read(string $file): array
{
    $out = array();
    if (!is_readable($file)) {
        return $out;
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return $out;
    }
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if ($key === '') {
            continue;
        }
        if (strlen($val) >= 2) {
            $q = $val[0];
            if (($q === '"' || $q === "'") && substr($val, -1) === $q) {
                $val = substr($val, 1, -1);
            }
        }
        $out[$key] = $val;
    }
    return $out;
}

/** Экранирует значение, чтобы файл был безопасен для `source` в bash. */
function poz_env_quote(string $v): string
{
    return '"' . str_replace(
        array('\\', '"', '$', '`'),
        array('\\\\', '\\"', '\\$', '\\`'),
        $v
    ) . '"';
}

/** Атомарно записывает poznote.env (каталог 0755, файл 0644). */
function poz_env_write(string $file, array $vars): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $out = array(
        '# Poznote для TerraMaster TOS — настройки (создано панелью управления).',
        '# Служба читает этот файл при каждом запуске: правка вручную допустима.',
    );
    foreach ($vars as $k => $v) {
        $out[] = $k . '=' . poz_env_quote((string)$v);
    }
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, implode("\n", $out) . "\n") === false) {
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Занят ли TCP-порт (парсим /proc/net/tcp — так же, как служба). */
function poz_port_in_use(int $port): bool
{
    if ($port < 1 || $port > 65535) {
        return false;
    }
    $hex = strtoupper(str_pad(dechex($port), 4, '0', STR_PAD_LEFT));
    foreach (array('/proc/net/tcp', '/proc/net/tcp6') as $f) {
        $lines = @file($f, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }
            $cols = preg_split('/\s+/', trim((string)$line));
            if (!is_array($cols) || count($cols) < 4 || $cols[3] !== '0A') {
                continue;                       // 0A = LISTEN
            }
            $cut = strrpos($cols[1], ':');
            if ($cut !== false && strtoupper(substr($cols[1], $cut + 1)) === $hex) {
                return true;
            }
        }
    }
    return false;
}

/** Кто держит порт — только для сообщения оператору. */
function poz_port_owner(int $port): string
{
    $out = poz_run_quiet('ss -ltnp | grep -E "[:.]' . $port . '[[:space:]]" | head -n 1');
    if (trim($out) === '') {
        $out = poz_run_quiet('netstat -ltnp | grep -E "[:.]' . $port . '[[:space:]]" | head -n 1');
    }
    if (preg_match('/\(\("([^"]+)",pid=(\d+)/', $out, $m)) {
        return $m[1] . ' (pid ' . $m[2] . ')';
    }
    return trim($out);
}

/** Разбор вывода `init.d/service ports` (строки "web 8040 free|ours|foreign …"). */
function poz_ports_parse(string $out): array
{
    $state = array();
    foreach (preg_split('/\r?\n/', $out) as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $parts = explode(' ', $line, 4);
        if (count($parts) < 3 || ($parts[0] !== 'web' && $parts[0] !== 'mcp')) {
            continue;
        }
        $state[$parts[0]] = array(
            'port'  => (int)$parts[1],
            'busy'  => ($parts[2] !== 'free'),
            'ours'  => ($parts[2] === 'ours'),
            'owner' => isset($parts[3]) ? trim($parts[3]) : '',
        );
    }
    return $state;
}

/** Состояние портов для страницы и для ?api=status. */
function poz_ports_state(string $initd, string $web, string $mcp, string $panel): array
{
    // Служба знает про Docker и отличает «занят нашими контейнерами» (норма) от
    // «занят чужой программой» (конфликт).
    $state = poz_ports_parse(poz_run_quiet($initd . ' ports'));
    if ($state) {
        return $state;
    }

    // Резервный путь (старая служба в пакете): занятость определяем сами.
    $state = array();
    foreach (array('web' => $web, 'mcp' => $mcp, 'panel' => $panel) as $role => $value) {
        $num  = (int)$value;
        $busy = poz_port_in_use($num);
        // Панель слушает только localhost: её собственный сокет — не конфликт.
        $state[$role] = array(
            'port'  => $num,
            'busy'  => $busy,
            'ours'  => false,
            'owner' => ($busy && $role !== 'panel') ? poz_port_owner($num) : '',
        );
    }
    return $state;
}

/** Метки «свободен/занят» для карточки портов. */
function poz_ports_tags(array $state): string
{
    $tags = array();
    foreach (array('web' => poz_t('role_web'), 'mcp' => poz_t('role_mcp')) as $role => $label) {
        if (!isset($state[$role])) {
            continue;
        }
        $p = $state[$role];
        if (!$p['busy']) {
            $text = poz_t('port_free', array($label, $p['port']));
            $cls  = 'ok';
        } elseif (!empty($p['ours'])) {
            $text = poz_t('port_ours', array($label, $p['port']));
            $cls  = 'ok';
        } else {
            $text = poz_t('port_foreign', array($label, $p['port']))
                . ($p['owner'] !== '' ? ' (' . $p['owner'] . ')' : '');
            $cls = 'bad';
        }
        $tags[] = '<span class="ptag ' . $cls . '">' . poz_htmlesc($text) . '</span>';
    }
    return implode(' ', $tags);
}

/* Текущие настройки. Умолчания совпадают с init.d/service. */
$ENV_FILE   = poz_env_path($INITD);
$ENV_VARS   = poz_env_read($ENV_FILE);
$APP_PORT   = isset($ENV_VARS['POZNOTE_PORT'])       ? (string)$ENV_VARS['POZNOTE_PORT']       : '8040';
$MCP_PORT   = isset($ENV_VARS['POZNOTE_MCP_PORT'])   ? (string)$ENV_VARS['POZNOTE_MCP_PORT']   : '8045';
$PANEL_PORT = isset($ENV_VARS['POZNOTE_PANEL_PORT']) ? (string)$ENV_VARS['POZNOTE_PANEL_PORT'] : '8042';

/* ------------------------------------------------------------------ */
/* Контроль доступа (аналогично session_info.php штатных модулей)      */
/* ------------------------------------------------------------------ */

$tosUser = trim((string)@shell_exec('id -un'));
$cookieU = isset($_COOKIE['userName'])    ? (string)$_COOKIE['userName']    : '';
$cookieS = isset($_COOKIE['loginStatus']) ? (string)$_COOKIE['loginStatus'] : '';

if ($tosUser === '' || $cookieU !== $tosUser || $cookieS !== 'true') {
    if ($isAjax) {
        http_response_code(403);
        poz_json(array('ok' => false, 'message' => poz_t('denied_ajax')));
    }
    http_response_code(403);
    ?>
<!doctype html>
<html lang="<?php echo poz_htmlesc($POZ_LANG); ?>"><head><meta charset="utf-8"><title><?php echo poz_htmlesc(poz_t('denied_title')); ?></title>
<style>body{margin:0;background:#f3f1ea;color:#24211c;font-family:ui-monospace,"Cascadia Mono",Consolas,"DejaVu Sans Mono",monospace;font-size:13px;line-height:1.5}.card{max-width:560px;margin:80px auto;background:#fffdf8;border:1px solid #ded8ca;border-left:3px solid #a8362a;padding:20px}.card h2{margin:0 0 10px;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#a8362a}.card p{margin:0;color:#5b5449}@media (prefers-color-scheme:dark){body{background:#14161a;color:#e8e6e1}.card{background:#1b1e23;border-color:#2e333b;border-left-color:#e2725b}.card h2{color:#e2725b}.card p{color:#a49e94}}</style>
</head><body>
<div class="card">
  <h2><?php echo poz_htmlesc(poz_t('denied_h')); ?></h2>
  <p><?php echo poz_htmlesc(poz_t('denied_p')); ?></p>
</div>
</body></html>
    <?php
    exit;
}

/* ------------------------------------------------------------------ */
/* API (AJAX): статус + журнал одним запросом                         */
/* ------------------------------------------------------------------ */

if ($isAjax && isset($_GET['api']) && $_GET['api'] === 'status') {
    $status = poz_status($INITD);
    poz_json(array(
        'ok'      => true,
        'running' => poz_running($status),
        'status'  => $status,
        'log'     => poz_log_tail($LOG_FILE, $LOG_LINES),
        'ports'   => poz_ports_state($INITD, $APP_PORT, $MCP_PORT, $PANEL_PORT),
    ));
}

/* ------------------------------------------------------------------ */
/* Обработка действий                                                 */
/* ------------------------------------------------------------------ */

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$notice = '';
$noticeKind = 'ok';

/* --- Смена портов: пишем poznote.env и перезапускаем Poznote --------- */

if ($action === 'saveports') {
    $web = isset($_POST['web_port']) ? trim((string)$_POST['web_port']) : '';
    $mcp = isset($_POST['mcp_port']) ? trim((string)$_POST['mcp_port']) : '';
    $err = '';

    if (!preg_match('/^\d{1,5}$/', $web) || (int)$web < 1 || (int)$web > 65535) {
        $err = poz_t('err_web_port');
    } elseif (!preg_match('/^\d{1,5}$/', $mcp) || (int)$mcp < 1 || (int)$mcp > 65535) {
        $err = poz_t('err_mcp_port');
    } elseif ($web === $mcp) {
        $err = poz_t('err_same_port');
    } elseif ((int)$web === (int)$PANEL_PORT || (int)$mcp === (int)$PANEL_PORT) {
        $err = poz_t('err_panel_port', array($PANEL_PORT));
    } elseif ((int)$web !== (int)$APP_PORT && poz_port_in_use((int)$web)) {
        $who = poz_port_owner((int)$web);
        $err = poz_t('err_web_busy', array($web, $who !== '' ? ' — ' . $who : ''));
    } elseif ((int)$mcp !== (int)$MCP_PORT && poz_port_in_use((int)$mcp)) {
        $who = poz_port_owner((int)$mcp);
        $err = poz_t('err_mcp_busy', array($mcp, $who !== '' ? ' — ' . $who : ''));
    }

    if ($err !== '') {
        $notice = $err;
        $noticeKind = 'err';
        if ($isAjax) {
            poz_json(array('ok' => false, 'action' => $action, 'message' => $err));
        }
    } else {
        $vars = $ENV_VARS;
        $vars['POZNOTE_PORT']     = $web;
        $vars['POZNOTE_MCP_PORT'] = $mcp;
        ksort($vars);
        if (!poz_env_write($ENV_FILE, $vars)) {
            $notice = poz_t('err_env_write', array($ENV_FILE));
            $noticeKind = 'err';
            if ($isAjax) {
                poz_json(array('ok' => false, 'action' => $action, 'message' => $notice));
            }
        } else {
            $output = poz_run($INITD . ' reload');
            $ENV_VARS = poz_env_read($ENV_FILE);
            $APP_PORT = $web;
            $MCP_PORT = $mcp;
            $notice = poz_t('ok_ports_saved', array($web, $mcp));
            $noticeKind = 'ok';
            if ($isAjax) {
                $status = poz_status($INITD);
                poz_json(array(
                    'ok'      => true,
                    'action'  => $action,
                    'message' => $notice,
                    'output'  => $output,
                    'ports'   => array('web' => (int)$web, 'mcp' => (int)$mcp),
                    'running' => poz_running($status),
                    'status'  => $status,
                    'log'     => poz_log_tail($LOG_FILE, $LOG_LINES),
                ));
            }
        }
    }
}

if ($action !== '' && $action !== 'saveports') {
    $cmd = '';
    $msg = '';
    switch ($action) {
        case 'start':    $cmd = 'start';    $msg = poz_t('msg_start');    break;
        case 'stop':     $cmd = 'stop';     $msg = poz_t('msg_stop');     break;
        case 'restart':  $cmd = 'reload';   $msg = poz_t('msg_restart');  break;
        case 'update':   $cmd = 'update';   $msg = poz_t('msg_update');   break;
        case 'reset':    $cmd = 'reset';    $msg = poz_t('msg_reset');    break;
        case 'clearlog': $cmd = 'clearlog'; $msg = poz_t('msg_clearlog'); break;
        default:
            $notice = poz_t('msg_unknown');
            $noticeKind = 'err';
    }

    if ($cmd !== '') {
        $output = poz_run($INITD . ' ' . $cmd);
        $notice = $msg;
        $noticeKind = 'ok';
        if ($isAjax) {
            $status = poz_status($INITD);
            poz_json(array(
                'ok'      => true,
                'action'  => $action,
                'message' => $msg,
                'output'  => $output,
                'running' => poz_running($status),
                'status'  => $status,
                'log'     => poz_log_tail($LOG_FILE, $LOG_LINES),
            ));
        }
    } elseif ($isAjax) {
        poz_json(array('ok' => false, 'action' => $action, 'message' => $notice));
    }
}

/* ------------------------------------------------------------------ */
/* Статус и журнал (серверный рендер / запасной вариант без JS)       */
/* ------------------------------------------------------------------ */

$status  = poz_status($INITD);
$running = poz_running($status);

$host = 'localhost';
if (!empty($_SERVER['HTTP_HOST'])) {
    $host = preg_replace('/:\d+$/', '', (string)$_SERVER['HTTP_HOST']);
} elseif (!empty($_SERVER['SERVER_ADDR'])) {
    $host = (string)$_SERVER['SERVER_ADDR'];
}
$appUrl = 'http://' . $host . ':' . $APP_PORT . '/';

/* Официальная иконка Poznote — элемент <g> из poznote.svg проекта Poznote
   (poznote-white.svg и poznote-black.svg — те же контуры). Координаты в исходнике
   заданы в масштабе 0…5120, поэтому transform обязателен: без него в viewBox
   512×512 виден только угол. В шапке цвет переопределяется через currentColor,
   в favicon остаётся фирменный #007AB5. */
$pozMark = '<g transform="translate(0,512) scale(0.1,-0.1)" fill="#007AB5"><path d="M945 5113 c-56 -8 -170 -45 -230 -75 -255 -128 -416 -361 -444 -647 -15 -142 -15 -3520 0 -3662 33 -335 245 -592 574 -700 68 -22 85 -23 494 -26 369 -4 430 -2 466 11 165 63 171 292 10 367 -37 17 -71 19 -401 19 -200 0 -385 5 -414 10 -151 29 -261 124 -311 268 -18 53 -19 114 -19 1882 0 1768 1 1829 19 1882 50 144 160 239 311 268 33 6 502 10 1295 10 793 0 1262 -4 1295 -10 152 -29 272 -134 315 -278 12 -39 15 -159 15 -679 l0 -633 -1345 0 c-1485 0 -1383 4 -1453 -63 -20 -20 -39 -52 -48 -84 -14 -48 -14 -58 0 -107 18 -62 70 -116 132 -136 31 -11 327 -12 1501 -7 1597 6 1486 2 1545 64 15 15 37 51 50 78 l23 50 3 684 c2 418 -1 724 -7 786 -34 339 -242 594 -576 706 l-70 23 -1350 2 c-742 1 -1363 -1 -1380 -3z"/><path d="M1190 3904 c-56 -24 -99 -73 -116 -131 -14 -48 -14 -58 0 -106 9 -32 28 -64 48 -84 69 -67 -3 -63 1175 -63 1018 0 1070 1 1108 19 157 73 157 289 0 362 -38 18 -89 19 -1110 18 -933 0 -1074 -2 -1105 -15z"/><path d="M4101 2380 c-156 -42 -153 -40 -800 -684 -377 -376 -599 -604 -608 -626 -15 -35 -222 -708 -248 -808 -35 -131 58 -261 186 -262 51 0 866 227 914 255 22 13 301 286 621 607 525 527 584 590 616 653 48 94 69 180 69 285 0 208 -97 389 -269 502 -97 63 -181 89 -307 94 -79 3 -116 0 -174 -16z m264 -417 c103 -72 112 -227 18 -315 l-29 -28 -139 140 -140 140 36 36 c70 70 175 81 254 27z m-658 -991 l-368 -368 -200 -55 c-115 -33 -199 -51 -199 -45 0 6 25 92 55 191 l55 180 372 372 373 373 140 -140 140 -140 -368 -368z"/><path d="M1190 2304 c-56 -24 -99 -73 -116 -131 -14 -48 -14 -58 0 -106 9 -32 28 -64 48 -84 67 -65 40 -63 768 -63 722 0 701 -2 763 58 43 41 60 81 60 142 0 84 -45 151 -125 184 -55 24 -1345 23 -1398 0z"/></g>';
$pozFavicon = 'data:image/svg+xml,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">' . $pozMark . '</svg>'
);

$logTail = poz_log_tail($LOG_FILE, $LOG_LINES);
?>
<!doctype html>
<html lang="<?php echo poz_htmlesc($POZ_LANG); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo poz_htmlesc(poz_t('page_title')); ?></title>
<link rel="icon" href="<?php echo poz_htmlesc($pozFavicon); ?>">
<script>
/* Тема применяется до первой отрисовки, иначе страница «мигает» белым. */
(function () {
  var t = null;
  try { t = localStorage.getItem('poznote-theme'); } catch (e) {}
  if (t !== 'dark' && t !== 'light') {
    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-theme', t);
})();
</script>

<script>
/* Словарь интерфейса отдаётся из PHP, чтобы тексты жили в одном месте. */
var POZ_LANG = <?php echo (json_encode($POZ_LANG) ?: '"en"'); ?>;
var POZ_T    = <?php echo (json_encode($POZ_T, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'); ?>;
function T(key, args) {
  var s = (POZ_T && typeof POZ_T[key] === 'string') ? POZ_T[key] : key;
  if (args) {
    for (var i = 0; i < args.length; i++) { s = s.replace('{' + i + '}', String(args[i])); }
  }
  return s;
}
</script>
<style>
  /* ==================================================================
     Poznote — «приборная панель», а не набор карточек: моноширинный
     шрифт, прямые углы, линейки вместо теней, тёплая палитра.
     Светлая тема — «бумажный журнал», тёмная — графит.
     ================================================================== */
  :root {
    color-scheme: light;
    --mono: ui-monospace, "Cascadia Mono", Consolas, "DejaVu Sans Mono", "Liberation Mono", monospace;
    --accent:#b3550f; --accent-text:#fff8f1;
    --bg:#f3f1ea; --card:#fffdf8; --card2:#f7f4ec;
    --line:#ded8ca; --line-strong:#c4bca7;
    --text:#24211c; --head:#6a6255; --muted:#7d766a;
    --btn-bg:transparent; --btn-bg-hover:#24211c; --btn-text-hover:#f7f4ec; --btn-line:#c4bca7;
    --ok:#2f7d4f; --ok-bg:#edf5ef; --ok-line:#b9d6c3;
    --err:#a8362a; --err-bg:#fbeeec; --err-line:#e3c0b9;
    --warn:#8a6412; --warn-bg:#faf3e1; --warn-line:#ddcb9c;
    --console-bg:#fffdf8; --console-text:#2b2822; --console-line:#ded8ca;
    --focus:rgba(179,85,15,.25);
  }
  [data-theme="dark"] {
    color-scheme: dark;
    --accent:#e0873a; --accent-text:#17130d;
    --bg:#14161a; --card:#1b1e23; --card2:#20242a;
    --line:#2e333b; --line-strong:#3f4650;
    --text:#e8e6e1; --head:#a49e94; --muted:#8b867e;
    --btn-bg:transparent; --btn-bg-hover:#e8e6e1; --btn-text-hover:#14161a; --btn-line:#3f4650;
    --ok:#57b98a; --ok-bg:#17251e; --ok-line:#2f5442;
    --err:#e2725b; --err-bg:#2a1a17; --err-line:#5c342c;
    --warn:#d9a24a; --warn-bg:#2a2314; --warn-line:#5c4a25;
    --console-bg:#101216; --console-text:#d7d3cc; --console-line:#2b3038;
    --focus:rgba(224,135,58,.3);
  }

  * { box-sizing:border-box; }
  [hidden] { display:none !important; }
  body { margin:0; background:var(--bg); color:var(--text);
         font-family:var(--mono); font-size:13px; line-height:1.5; }
  .wrap { max-width:1600px; margin:0 auto; padding:18px 14px 16px; counter-reset:sec; }

  /* ---------- Сетка: широкое окно — две колонки ---------- */
  .grid { display:grid; gap:10px; grid-template-columns:minmax(0,1.1fr) minmax(0,1fr); }
  .grid > .card { margin-bottom:0; }
  .grid .g-ctrl   { grid-column:1; grid-row:1; }
  .grid .g-ports  { grid-column:2; grid-row:1; }
  .grid .g-status { grid-column:1; grid-row:2; }
  .grid .g-log    { grid-column:2; grid-row:2; }
  .grid .g-status, .grid .g-log { display:flex; flex-direction:column; }
  .grid .g-status pre.status, .grid .g-log textarea { flex:1 1 auto; min-height:130px; max-height:none; height:auto; }
  @media (max-width:900px) {
    .grid { grid-template-columns:1fr; }
    .grid .g-ctrl, .grid .g-ports, .grid .g-status, .grid .g-log { grid-column:1; grid-row:auto; }
  }

  /* ---------- Верхняя панель ---------- */
  .topbar { position:sticky; top:0; z-index:20; display:flex; align-items:center; justify-content:space-between;
            gap:12px; flex-wrap:wrap; padding:8px 12px; margin-bottom:10px;
            border:1px solid var(--line); border-bottom:2px solid var(--accent); background:var(--card); }
  .brand { display:flex; align-items:center; gap:10px; }
  .logo { width:34px; height:34px; flex:0 0 34px; display:flex; align-items:center; justify-content:center;
          color:#007ab5; }
  .logo svg { width:34px; height:34px; display:block; }
  .logo svg g { fill:currentColor; }
  [data-theme="dark"] .logo { color:#5cb7e6; }
  .brand h1 { font-size:15px; margin:0; font-weight:bold; letter-spacing:.14em; text-transform:uppercase; }
  .brand small { display:block; margin-top:1px; color:var(--muted); font-size:11px; }
  .topright { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }

  .pill { display:inline-flex; align-items:center; gap:7px; padding:5px 9px; white-space:nowrap;
          border:1px solid var(--line-strong); background:var(--card2); color:var(--text);
          font-size:11px; font-weight:bold; letter-spacing:.1em; text-transform:uppercase; }
  .pill::before { content:""; width:8px; height:8px; background:currentColor; }
  .pill.on  { color:var(--ok);  background:var(--ok-bg);  border-color:var(--ok-line); }
  .pill.off { color:var(--muted); }

  /* ---------- Панели (карточки) с шапкой-линейкой ---------- */
  .card { background:var(--card); border:1px solid var(--line); padding:12px; margin-bottom:10px; overflow:hidden; }
  .cardhead { display:flex; align-items:center; gap:10px; flex-wrap:wrap;
              margin:-12px -12px 10px; padding:7px 12px; border-bottom:1px solid var(--line); background:var(--card2); }
  .cardhead h2 { font-size:11px; margin:0; color:var(--head); font-weight:bold;
                 letter-spacing:.12em; text-transform:uppercase; }
  .cardhead h2::before { counter-increment:sec; content:counter(sec, decimal-leading-zero) "/";
                         margin-right:7px; color:var(--accent); }
  .cardhead .hint { margin-left:auto; color:var(--muted); font-size:11px; text-align:right; }
  .cardhead .right { margin-left:auto; }
  .cardnote { margin:10px 0 0; padding-left:9px; border-left:2px solid var(--line-strong);
              color:var(--muted); font-size:11px; line-height:1.55; }
  .cardnote::before { content:"// "; color:var(--line-strong); }
  a { color:var(--accent); }
  .cardnote a { text-decoration:none; border-bottom:1px dotted var(--accent); }
  .cardnote a:hover { border-bottom-style:solid; }

  /* ---------- Кнопки: блоки с инверсией при наведении ---------- */
  .btnrow { display:flex; flex-wrap:wrap; gap:6px; }
  form { display:inline; margin:0; }
  button, .btn { display:inline-flex; align-items:center; gap:7px; font-family:var(--mono); font-size:11px;
                 font-weight:bold; letter-spacing:.09em; text-transform:uppercase; line-height:1;
                 padding:8px 11px; border:1px solid var(--btn-line); background:var(--btn-bg); color:var(--text);
                 cursor:pointer; text-decoration:none; transition:background .12s, color .12s, border-color .12s; }
  button:hover, .btn:hover { background:var(--btn-bg-hover); border-color:var(--btn-bg-hover); color:var(--btn-text-hover); }
  button:active, .btn:active { transform:translateY(1px); }
  button:disabled { opacity:.45; cursor:progress; }
  .btn.primary { background:var(--accent); border-color:var(--accent); color:var(--accent-text); }
  .btn.primary:hover { background:var(--btn-bg-hover); border-color:var(--btn-bg-hover); color:var(--btn-text-hover); }
  .btn.go { color:var(--ok); border-color:var(--ok-line); }
  .btn.go:hover { background:var(--ok); border-color:var(--ok); color:var(--card); }
  .btn.warn { color:var(--warn); border-color:var(--warn-line); }
  .btn.warn:hover { background:var(--warn); border-color:var(--warn); color:var(--card); }
  .btn.danger { color:var(--err); border-color:var(--err-line); }
  .btn.danger:hover { background:var(--err); border-color:var(--err); color:var(--card); }
  .btn.sm { padding:5px 8px; font-size:10.5px; letter-spacing:.06em; }
  .btn.ghost { border-color:transparent; color:var(--muted); }
  .btn.ghost:hover { background:transparent; border-color:var(--btn-line); color:var(--text); }
  .btn.icon { padding:7px 8px; }
  svg.i { width:13px; height:13px; flex:0 0 13px; fill:none; stroke:currentColor; stroke-width:1.6;
          stroke-linecap:square; stroke-linejoin:miter; }
  svg.i.solid { fill:currentColor; stroke:none; }
  /* Иконка темы: в светлой теме показываем «луну» (переключение в тёмную) и наоборот. */
  svg.i-sun { display:none; }
  [data-theme="dark"] svg.i-sun { display:inline-block; }
  [data-theme="dark"] svg.i-moon { display:none; }

  /* ---------- Группы кнопок ---------- */
  .group + .group { margin-top:10px; padding-top:10px; border-top:1px dashed var(--line-strong); }
  .grouplabel { display:block; margin-bottom:7px; color:var(--head); font-size:10.5px; font-weight:bold;
                letter-spacing:.16em; text-transform:uppercase; }
  .grouplabel::before { content:"— "; color:var(--line-strong); }

  /* ---------- Порты ---------- */
  #portsForm { display:block; }
  .fields { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px 16px; margin-bottom:10px; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { color:var(--head); font-size:10.5px; font-weight:bold; letter-spacing:.12em; text-transform:uppercase; }
  .field input[type=text] { font-family:var(--mono); font-size:14px; letter-spacing:.08em; width:86px; padding:6px 8px;
                            text-align:center; border:1px solid var(--line-strong); background:var(--card2); color:var(--text); }
  .field input[type=text]:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 2px var(--focus); }
  .ports { display:flex; flex-wrap:wrap; gap:6px; }
  .ptag { display:inline-flex; align-items:center; gap:7px; padding:4px 8px; font-size:10.5px;
          letter-spacing:.05em; text-transform:uppercase; border:1px solid var(--line-strong);
          background:var(--card2); color:var(--text); }
  .ptag::before { content:""; width:7px; height:7px; background:currentColor; }
  .ptag.ok  { color:var(--ok);  background:var(--ok-bg);  border-color:var(--ok-line); }
  .ptag.bad { color:var(--err); background:var(--err-bg); border-color:var(--err-line); }

  /* ---------- Консоль, сообщения, прогресс ---------- */
  pre, textarea { width:100%; font-family:var(--mono); font-size:11.5px; line-height:1.45; margin:0; padding:9px;
                  background:var(--console-bg); color:var(--console-text); border:1px solid var(--console-line);
                  white-space:pre-wrap; word-break:break-word; }
  pre.status { max-height:170px; overflow:auto; }
  textarea { height:200px; min-height:110px; resize:vertical; }
  .notice { padding:8px 10px; margin-bottom:10px; border:1px solid var(--line-strong); border-left-width:3px; font-size:11.5px; }
  .notice.ok  { background:var(--ok-bg);  border-color:var(--ok-line);  border-left-color:var(--ok);  color:var(--ok); }
  .notice.err { background:var(--err-bg); border-color:var(--err-line); border-left-color:var(--err); color:var(--err); }
  .muted { color:var(--muted); }
  .progress { display:flex; align-items:center; gap:12px; }
  .spinner { width:18px; height:18px; flex:0 0 18px; border:2px solid var(--line-strong); border-top-color:var(--accent);
             animation:spin 1s linear infinite; }
  @keyframes spin { to { transform:rotate(360deg); } }
  .progress .ptxt { font-weight:bold; font-size:11.5px; letter-spacing:.06em; text-transform:uppercase; }
  .progress .ptime { margin-left:auto; font-size:11.5px; color:var(--muted); }
  .progress .phint { color:var(--muted); margin-top:2px; font-size:11px; font-weight:normal; }
  .progress .pstep { margin-top:3px; color:var(--muted); font-size:10.5px; max-width:760px;
                     white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  @media (max-width:560px) {
    .topbar { position:static; }
    .cardhead .hint { margin-left:0; width:100%; text-align:left; }
  }
</style>
</head>
<body>
<div class="wrap" id="fit">

  <div class="topbar">
    <div class="brand">
      <span class="logo" aria-hidden="true"><svg viewBox="0 0 512 512"><?php echo $pozMark; ?></svg></span>
      <div>
        <h1>Poznote</h1>
        <small><?php echo poz_htmlesc(poz_t('tagline')); ?></small>
      </div>
    </div>
    <div class="topright">
      <button type="button" class="btn icon" id="themeBtn" title="<?php echo poz_htmlesc(poz_t('theme_dark')); ?>" aria-label="<?php echo poz_htmlesc(poz_t('theme_aria')); ?>">
        <svg class="i i-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/></svg>
        <svg class="i i-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2 12h2M20 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>
      </button>
      <button type="button" class="btn icon" id="langBtn" title="<?php echo poz_htmlesc(poz_t('lang_other')); ?>" aria-label="<?php echo poz_htmlesc(poz_t('lang_aria')); ?>"><?php echo $POZ_LANG === 'ru' ? 'EN' : 'RU'; ?></button>
      <span id="pill" class="pill <?php echo $running ? 'on' : 'off'; ?>"><?php echo poz_htmlesc($running ? poz_t('running') : poz_t('stopped')); ?></span>
      <a class="btn primary applink" href="<?php echo poz_htmlesc($appUrl); ?>" target="_blank" rel="noopener">
        <svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4l-8.5 8.5"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
        <?php echo poz_htmlesc(poz_t('open_app')); ?>
      </a>
    </div>
  </div>

  <div id="notice" class="notice <?php echo poz_htmlesc($noticeKind); ?>"<?php echo $notice === '' ? ' hidden' : ''; ?>><?php echo poz_htmlesc($notice); ?></div>

  <div id="progress" class="card progress" hidden>
    <div class="spinner" aria-hidden="true"></div>
    <div>
      <div class="ptxt" id="progressText"><?php echo poz_htmlesc(poz_t('progress_default')); ?></div>
      <div class="phint" id="progressHint"></div>
      <div class="pstep" id="progressStep"></div>
    </div>
    <div class="ptime" id="progressTime"><?php echo poz_htmlesc(poz_t('seconds', array(0))); ?></div>
  </div>

  <div class="grid">

  <div class="card g-ctrl">
    <div class="cardhead">
      <h2><?php echo poz_htmlesc(poz_t('card_control')); ?></h2>
      <span class="hint"><?php echo poz_htmlesc(poz_t('hint_control')); ?></span>
    </div>

    <div class="group">
      <span class="grouplabel"><?php echo poz_htmlesc(poz_t('group_service')); ?></span>
      <div class="btnrow">
        <form method="post"><input type="hidden" name="action" value="start"><button class="btn go" type="submit" data-action="start" data-label="<?php echo poz_htmlesc(poz_t('btn_start_label')); ?>" data-hint="<?php echo poz_htmlesc(poz_t('btn_start_hint')); ?>"><svg class="i solid" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 4.8 19 12 7.5 19.2z"/></svg><?php echo poz_htmlesc(poz_t('btn_start')); ?></button></form>
        <form method="post"><input type="hidden" name="action" value="restart"><button class="btn" type="submit" data-action="restart" data-label="<?php echo poz_htmlesc(poz_t('btn_restart_label')); ?>" data-hint="<?php echo poz_htmlesc(poz_t('btn_restart_hint')); ?>"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M20.6 4.6v5.2h-5.2"/></svg><?php echo poz_htmlesc(poz_t('btn_restart')); ?></button></form>
        <form method="post"><input type="hidden" name="action" value="stop"><button class="btn danger" type="submit" data-action="stop" data-label="<?php echo poz_htmlesc(poz_t('btn_stop_label')); ?>" data-hint="<?php echo poz_htmlesc(poz_t('btn_stop_hint')); ?>"><svg class="i solid" viewBox="0 0 24 24" aria-hidden="true"><rect x="6.5" y="6.5" width="11" height="11" rx="2.5"/></svg><?php echo poz_htmlesc(poz_t('btn_stop')); ?></button></form>
      </div>
    </div>

    <div class="group">
      <span class="grouplabel"><?php echo poz_htmlesc(poz_t('group_maintenance')); ?></span>
      <div class="btnrow">
        <form method="post"><input type="hidden" name="action" value="update"><button class="btn warn" type="submit" data-action="update" data-label="<?php echo poz_htmlesc(poz_t('btn_update_label')); ?>" data-hint="<?php echo poz_htmlesc(poz_t('btn_update_hint')); ?>"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5v11"/><path d="M7.5 10.5 12 15l4.5-4.5"/><path d="M4.5 19.5h15"/></svg><?php echo poz_htmlesc(poz_t('btn_update')); ?></button></form>
        <form method="post"><input type="hidden" name="action" value="reset"><button class="btn" type="submit" data-action="reset" data-label="<?php echo poz_htmlesc(poz_t('btn_reset_label')); ?>" data-hint="<?php echo poz_htmlesc(poz_t('btn_reset_hint')); ?>"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 8 12 3.5 20.5 8v8L12 20.5 3.5 16z"/><path d="M3.5 8 12 12.5 20.5 8"/><path d="M12 12.5v8"/></svg><?php echo poz_htmlesc(poz_t('btn_reset')); ?></button></form>
      </div>
    </div>

    <p class="cardnote">
      <?php echo poz_htmlesc(poz_t('note_ui')); ?> <a class="applink appurl" href="<?php echo poz_htmlesc($appUrl); ?>" target="_blank" rel="noopener"><?php echo poz_htmlesc($appUrl); ?></a>.
      <?php echo poz_htmlesc(poz_t('note_update_1')); ?>
      <?php echo poz_htmlesc(poz_t('note_update_2')); ?>
    </p>
  </div>

  <div class="card g-ports">
    <div class="cardhead">
      <h2><?php echo poz_htmlesc(poz_t('card_ports')); ?></h2>
      <span class="hint"><?php echo poz_htmlesc(poz_t('hint_ports')); ?></span>
    </div>
    <form method="post" id="portsForm">
      <input type="hidden" name="action" value="saveports">
      <div class="fields">
        <div class="field">
          <label for="webPort"><?php echo poz_htmlesc(poz_t('label_web')); ?></label>
          <input type="text" inputmode="numeric" id="webPort" name="web_port" maxlength="5" value="<?php echo poz_htmlesc((string)$APP_PORT); ?>">
        </div>
        <div class="field">
          <label for="mcpPort"><?php echo poz_htmlesc(poz_t('label_mcp')); ?></label>
          <input type="text" inputmode="numeric" id="mcpPort" name="mcp_port" maxlength="5" value="<?php echo poz_htmlesc((string)$MCP_PORT); ?>">
        </div>
        <button class="btn warn" type="submit" data-action="saveports" data-label="<?php echo poz_htmlesc(poz_t('btn_saveports_label')); ?>" data-hint="<?php echo poz_htmlesc(poz_t('btn_saveports_hint')); ?>"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5 10 17.5 19.5 6.5"/></svg><?php echo poz_htmlesc(poz_t('btn_saveports')); ?></button>
      </div>
    </form>
    <div id="portsBox" class="ports"><?php echo poz_ports_tags(poz_ports_state($INITD, $APP_PORT, $MCP_PORT, $PANEL_PORT)); ?></div>
    <p class="cardnote">
      <?php echo poz_htmlesc(poz_t('note_ports_1')); ?>
      <?php echo poz_htmlesc(poz_t('note_ports_2', array($PANEL_PORT))); ?>
    </p>
  </div>

  <div class="card g-status">
    <div class="cardhead">
      <h2><?php echo poz_htmlesc(poz_t('card_status')); ?></h2>
      <button type="button" class="btn sm ghost right" id="refreshBtn" title="<?php echo poz_htmlesc(poz_t('refresh_title')); ?>">
        <svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.4 6.3"/><path d="M20 4.8v5.4h-5.4"/></svg><?php echo poz_htmlesc(poz_t('refresh')); ?>
      </button>
    </div>
    <pre class="status" id="statusBox"><?php echo poz_htmlesc($status !== '' ? $status : poz_t('no_data')); ?></pre>
  </div>

  <div class="card g-log">
    <div class="cardhead">
      <h2><?php echo poz_htmlesc(poz_t('card_log')); ?></h2>
      <span class="hint"><?php echo poz_htmlesc(basename($LOG_FILE)); ?> <?php echo poz_htmlesc(poz_t('log_hint', array((int)$LOG_LINES))); ?></span>
      <form method="post"><input type="hidden" name="action" value="clearlog"><button class="btn sm ghost" type="submit" data-action="clearlog" data-label="<?php echo poz_htmlesc(poz_t('btn_clear_label')); ?>" data-hint="<?php echo poz_htmlesc(poz_t('btn_clear_hint')); ?>"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M9.5 7V4.5h5V7"/><path d="M6.5 7l.9 12.5h9.2L17.5 7"/></svg><?php echo poz_htmlesc(poz_t('btn_clear')); ?></button></form>
    </div>
    <textarea id="logBox" readonly><?php echo poz_htmlesc($logTail !== '' ? $logTail : poz_t('log_empty')); ?></textarea>
  </div>

  </div><!-- /.grid -->

</div>

<script>
(function () {
  var ENDPOINT = location.pathname || '/Poznote/';
  var notice    = document.getElementById('notice');
  var progress  = document.getElementById('progress');
  var pText     = document.getElementById('progressText');
  var pHint     = document.getElementById('progressHint');
  var pTime     = document.getElementById('progressTime');
  var pStep     = document.getElementById('progressStep');
  var pill      = document.getElementById('pill');
  var statusBox = document.getElementById('statusBox');
  var logBox    = document.getElementById('logBox');
  var portsBox  = document.getElementById('portsBox');
  var refreshBtn= document.getElementById('refreshBtn');

  var buttons = Array.prototype.slice.call(document.querySelectorAll('button[data-action]'));
  var themeBtn  = document.getElementById('themeBtn');
  var langBtn   = document.getElementById('langBtn');

  /* Светлая/тёмная тема. Выбор хранится в localStorage, поэтому сохраняется
     между открытиями страницы; при первом входе берётся системная настройка. */
  function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem('poznote-theme', t); } catch (e) {}
    if (themeBtn) {
      var label = (t === 'dark') ? T('theme_light') : T('theme_dark');
      themeBtn.title = label;
      themeBtn.setAttribute('aria-label', label);
    }
  }
  function currentTheme() {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  }
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
    });
    applyTheme(currentTheme());
  }

  /* Язык: кнопка ставит cookie poznote_lang и перезагружает страницу —
     так выбор сохраняется и применяется сразу, без правок в PHP. */
  if (langBtn) {
    langBtn.addEventListener('click', function () {
      var next = (POZ_LANG === 'ru') ? 'en' : 'ru';
      var d = new Date();
      d.setTime(d.getTime() + 365 * 24 * 60 * 60 * 1000);
      document.cookie = 'poznote_lang=' + next + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
      location.reload();
    });
  }

  /* Подгонка по высоте: если содержимое не влезает в окно модуля, аккуратно
     уменьшаем масштаб (не сильнее 0.7), чтобы не пришлось прокручивать. */
  var fit = document.getElementById('fit');
  var fitTimer = null;

  function fitToWindow() {
    if (!fit) { return; }
    fit.style.zoom = '';
    fit.style.width = '';
    var avail = (window.innerHeight || document.documentElement.clientHeight) - 24;
    var need  = fit.offsetHeight;
    if (avail < 120 || need < 120) { return; }
    var s = avail / need;
    if (s > 0.995) { return; }                 // влезает как есть — не масштабируем
    var z = Math.round(Math.max(0.7, s) * 1000) / 1000;
    fit.style.zoom = String(z);
    // zoom уменьшает и ширину, поэтому компенсируем её — колонки не «съёживаются» дважды
    fit.style.width = (100 / z) + '%';
  }

  function scheduleFit() {
    if (fitTimer) { clearTimeout(fitTimer); }
    fitTimer = setTimeout(fitToWindow, 80);
  }

  window.addEventListener('resize', scheduleFit);
  window.addEventListener('load', scheduleFit);
  if (window.MutationObserver && fit) {
    new MutationObserver(scheduleFit).observe(fit, { childList: true, subtree: true, characterData: true });
  }
  var tickTimer = null;
  var startAt = 0;
  var elapsedTimer = null;
  var busy = false;

  function lastLine(text) {
    if (!text) return '';
    var lines = String(text).split('\n');
    for (var i = lines.length - 1; i >= 0; i--) {
      var s = lines[i].replace(/^\s+|\s+$/g, '');
      if (!s) continue;
      // skip routine setup noise so the "current step" stays meaningful
      if (/Found docker in|The main volume is|Data dir:/.test(s)) continue;
      return s;
    }
    return '';
  }

  function setNotice(ok, msg) {
    if (!msg) { notice.hidden = true; scheduleFit(); return; }
    notice.hidden = false;
    notice.className = 'notice ' + (ok ? 'ok' : 'err');
    notice.textContent = msg;
    scheduleFit();
  }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // Метки «свободен/занят» — те же, что рендерит PHP при загрузке страницы.
  function portsTags(ports) {
    if (!ports || !portsBox) { return; }
    var roles = [['web', T('role_web')], ['mcp', T('role_mcp')]];
    var out = [];
    roles.forEach(function (r) {
      var p = ports[r[0]];
      if (!p) { return; }
      var text, cls;
      if (!p.busy) {
        text = T('port_free', [r[1], p.port]);
        cls = 'ok';
      } else if (p.ours) {
        text = T('port_ours', [r[1], p.port]);
        cls = 'ok';
      } else {
        text = T('port_foreign', [r[1], p.port]) + (p.owner ? ' (' + p.owner + ')' : '');
        cls = 'bad';
      }
      out.push('<span class="ptag ' + cls + '">' + esc(text) + '</span>');
    });
    portsBox.innerHTML = out.join(' ');
  }

  // После смены порта поправляем ссылку «Открыть Poznote».
  function setAppPort(port) {
    if (!port) { return; }
    var url = location.protocol + '//' + location.hostname + ':' + port + '/';
    Array.prototype.slice.call(document.querySelectorAll('.applink')).forEach(function (a) {
      a.href = url;
    });
    Array.prototype.slice.call(document.querySelectorAll('.appurl')).forEach(function (a) {
      a.textContent = url;
    });
  }

  function serializeForm(form, action) {
    var pairs = [];
    function push(k, v) { pairs.push(encodeURIComponent(k) + '=' + encodeURIComponent(v)); }
    if (form) {
      var fields = form.elements;
      for (var i = 0; i < fields.length; i++) {
        var f = fields[i];
        if (!f.name || f.disabled) { continue; }
        if ((f.type === 'checkbox' || f.type === 'radio') && !f.checked) { continue; }
        push(f.name, f.value);
      }
    }
    push('action', action);
    return pairs.join('&');
  }

  function applyStatus(data) {
    if (!data) return;
    if (typeof data.running !== 'undefined') {
      pill.textContent = data.running ? T('running') : T('stopped');
      pill.className = 'pill ' + (data.running ? 'on' : 'off');
    }
    if (typeof data.status !== 'undefined') {
      statusBox.textContent = data.status || T('no_data');
    }
    portsTags(data.ports);
    if (typeof data.log !== 'undefined') {
      logBox.value = data.log || T('log_empty');
      logBox.scrollTop = logBox.scrollHeight;
      if (busy) { pStep.textContent = lastLine(data.log); }
    }
    scheduleFit();
  }

  function tick() {
    fetch(ENDPOINT + '?api=status&_=' + Date.now(), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      cache: 'no-store'
    }).then(function (r) { return r.json(); }).then(applyStatus).catch(function () {});
  }

  function setRate(ms) {
    if (tickTimer) { clearInterval(tickTimer); }
    tickTimer = setInterval(tick, ms);
  }

  function setBusy(on, label, hint) {
    busy = on;
    buttons.forEach(function (b) { b.disabled = on; });
    refreshBtn.disabled = on;
    if (on) {
      pText.textContent = T('progress_running', [label || T('op_fallback')]);
      pHint.textContent = hint || '';
      pStep.textContent = '';
      progress.hidden = false;
      startAt = Date.now();
      updateElapsed();
      if (elapsedTimer) { clearInterval(elapsedTimer); }
      elapsedTimer = setInterval(updateElapsed, 250);
    } else {
      progress.hidden = true;
      pHint.textContent = '';
      pStep.textContent = '';
      if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
    }
    scheduleFit();
  }

  function updateElapsed() {
    var s = Math.max(0, Math.round((Date.now() - startAt) / 1000));
    pTime.textContent = T('seconds', [s]);
  }

  function runAction(action, label, hint, form) {
    if (busy) { return; }
    setNotice(true, '');
    setBusy(true, label, hint);
    setRate(1000);
    tick();

    var body = serializeForm(form, action);
    fetch(ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body
    }).then(function (r) { return r.json(); }).then(function (d) {
      setBusy(false);
      setRate(10000);
      applyStatus(d);
      if (d && d.ports && d.ports.web) { setAppPort(d.ports.web); }
      setNotice(!!d.ok, d.message || (d.ok ? T('done') : T('error')));
      tick();
    }).catch(function (e) {
      setBusy(false);
      setRate(10000);
      setNotice(false, T('ajax_fail', [e]));
      tick();
    });
  }

  buttons.forEach(function (b) {
    var form = b.closest('form');
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        runAction(
          b.getAttribute('data-action'),
          b.getAttribute('data-label') || (b.textContent || '').replace(/^\s+|\s+$/g, ''),
          b.getAttribute('data-hint'),
          form
        );
      });
    }
  });

  refreshBtn.addEventListener('click', function () { tick(); });

  setRate(10000);
  tick();
  scheduleFit();
})();
</script>
</body>
</html>
