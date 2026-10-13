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
    foreach (array('web' => 'Веб', 'mcp' => 'MCP') as $role => $label) {
        if (!isset($state[$role])) {
            continue;
        }
        $p = $state[$role];
        if (!$p['busy']) {
            $text = $label . ' ' . $p['port'] . ' — свободен';
            $cls  = 'ok';
        } elseif (!empty($p['ours'])) {
            $text = $label . ' ' . $p['port'] . ' — занят Poznote';
            $cls  = 'ok';
        } else {
            $text = $label . ' ' . $p['port'] . ' — занят'
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
        poz_json(array('ok' => false, 'message' => 'Доступ запрещён. Войдите в TOS под суперпользователем.'));
    }
    http_response_code(403);
    ?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><title>Poznote — доступ запрещён</title>
<style>body{margin:0;background:#f3f1ea;color:#24211c;font-family:ui-monospace,"Cascadia Mono",Consolas,"DejaVu Sans Mono",monospace;font-size:13px;line-height:1.5}.card{max-width:560px;margin:80px auto;background:#fffdf8;border:1px solid #ded8ca;border-left:3px solid #a8362a;padding:20px}.card h2{margin:0 0 10px;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#a8362a}.card p{margin:0;color:#5b5449}@media (prefers-color-scheme:dark){body{background:#14161a;color:#e8e6e1}.card{background:#1b1e23;border-color:#2e333b;border-left-color:#e2725b}.card h2{color:#e2725b}.card p{color:#a49e94}}</style>
</head><body>
<div class="card">
  <h2>Доступ запрещён</h2>
  <p>Войдите в TOS под учётной записью суперпользователя, созданной при настройке NAS, и откройте модуль повторно.</p>
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
        $err = 'Порт веб-интерфейса должен быть числом от 1 до 65535.';
    } elseif (!preg_match('/^\d{1,5}$/', $mcp) || (int)$mcp < 1 || (int)$mcp > 65535) {
        $err = 'Порт MCP-сервера должен быть числом от 1 до 65535.';
    } elseif ($web === $mcp) {
        $err = 'Порт веб-интерфейса и порт MCP должны различаться.';
    } elseif ((int)$web === (int)$PANEL_PORT || (int)$mcp === (int)$PANEL_PORT) {
        $err = 'Порты не должны совпадать с портом панели управления (' . $PANEL_PORT . ').';
    } elseif ((int)$web !== (int)$APP_PORT && poz_port_in_use((int)$web)) {
        $who = poz_port_owner((int)$web);
        $err = 'Порт ' . $web . ' уже занят' . ($who !== '' ? ' — ' . $who : '') . '. Выберите другой порт.';
    } elseif ((int)$mcp !== (int)$MCP_PORT && poz_port_in_use((int)$mcp)) {
        $who = poz_port_owner((int)$mcp);
        $err = 'Порт ' . $mcp . ' уже занят' . ($who !== '' ? ' — ' . $who : '') . '. Выберите другой порт.';
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
            $notice = 'Не удалось записать ' . $ENV_FILE . '. Проверьте права на каталог настроек.';
            $noticeKind = 'err';
            if ($isAjax) {
                poz_json(array('ok' => false, 'action' => $action, 'message' => $notice));
            }
        } else {
            $output = poz_run($INITD . ' reload');
            $ENV_VARS = poz_env_read($ENV_FILE);
            $APP_PORT = $web;
            $MCP_PORT = $mcp;
            $notice = 'Порты сохранены (' . $web . ' / ' . $mcp . '), Poznote перезапущен.';
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
        case 'start':   $cmd = 'start';  $msg = 'Poznote запущен.';                                   break;
        case 'stop':    $cmd = 'stop';   $msg = 'Poznote остановлен.';                                 break;
        case 'restart': $cmd = 'reload'; $msg = 'Poznote перезапущен.';                                break;
        case 'update':  $cmd = 'update'; $msg = 'Образы обновлены, контейнеры перезапущены.';          break;
        case 'reset':   $cmd = 'reset';  $msg = 'Контейнеры пересозданы (данные сохранены).';          break;
        case 'clearlog': $cmd = 'clearlog'; $msg = 'Журнал службы очищен.';                             break;
        default:
            $notice = 'Неизвестное действие.';
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

$logTail = poz_log_tail($LOG_FILE, $LOG_LINES);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Poznote</title>
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
          background:var(--accent); color:var(--accent-text); font-size:16px; font-weight:bold; }
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
      <div class="logo">P</div>
      <div>
        <h1>Poznote</h1>
        <small>Заметки, папки, задачи и поиск — на вашем NAS</small>
      </div>
    </div>
    <div class="topright">
      <button type="button" class="btn icon" id="themeBtn" title="Тёмная тема" aria-label="Переключить тему">
        <svg class="i i-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/></svg>
        <svg class="i i-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2 12h2M20 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>
      </button>
      <span id="pill" class="pill <?php echo $running ? 'on' : 'off'; ?>"><?php echo $running ? 'Работает' : 'Остановлен'; ?></span>
      <a class="btn primary applink" href="<?php echo poz_htmlesc($appUrl); ?>" target="_blank" rel="noopener">
        <svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4l-8.5 8.5"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
        Открыть Poznote
      </a>
    </div>
  </div>

  <div id="notice" class="notice <?php echo poz_htmlesc($noticeKind); ?>"<?php echo $notice === '' ? ' hidden' : ''; ?>><?php echo poz_htmlesc($notice); ?></div>

  <div id="progress" class="card progress" hidden>
    <div class="spinner" aria-hidden="true"></div>
    <div>
      <div class="ptxt" id="progressText">Выполняется…</div>
      <div class="phint" id="progressHint"></div>
      <div class="pstep" id="progressStep"></div>
    </div>
    <div class="ptime" id="progressTime">0 с</div>
  </div>

  <div class="grid">

  <div class="card g-ctrl">
    <div class="cardhead">
      <h2>Управление</h2>
      <span class="hint">Служба управляет контейнерами Poznote и MCP</span>
    </div>

    <div class="group">
      <span class="grouplabel">Служба</span>
      <div class="btnrow">
        <form method="post"><input type="hidden" name="action" value="start"><button class="btn go" type="submit" data-action="start" data-label="Запуск" data-hint="Запускаются контейнеры и панель управления."><svg class="i solid" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 4.8 19 12 7.5 19.2z"/></svg>Запустить</button></form>
        <form method="post"><input type="hidden" name="action" value="restart"><button class="btn" type="submit" data-action="restart" data-label="Перезапуск" data-hint="Служба останавливается и запускается заново."><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M20.6 4.6v5.2h-5.2"/></svg>Перезапустить</button></form>
        <form method="post"><input type="hidden" name="action" value="stop"><button class="btn danger" type="submit" data-action="stop" data-label="Остановка" data-hint="Останавливаются контейнеры Poznote и MCP."><svg class="i solid" viewBox="0 0 24 24" aria-hidden="true"><rect x="6.5" y="6.5" width="11" height="11" rx="2.5"/></svg>Остановить</button></form>
      </div>
    </div>

    <div class="group">
      <span class="grouplabel">Обслуживание</span>
      <div class="btnrow">
        <form method="post"><input type="hidden" name="action" value="update"><button class="btn warn" type="submit" data-action="update" data-label="Обновление образов" data-hint="Скачиваются свежие образы Poznote и MCP, затем контейнеры перезапускаются. Это может занять несколько минут."><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5v11"/><path d="M7.5 10.5 12 15l4.5-4.5"/><path d="M4.5 19.5h15"/></svg>Обновить образы</button></form>
        <form method="post"><input type="hidden" name="action" value="reset"><button class="btn" type="submit" data-action="reset" data-label="Пересоздание контейнеров" data-hint="Контейнеры удаляются и создаются заново. Данные (SQLite, вложения) сохраняются."><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 8 12 3.5 20.5 8v8L12 20.5 3.5 16z"/><path d="M3.5 8 12 12.5 20.5 8"/><path d="M12 12.5v8"/></svg>Пересоздать контейнеры</button></form>
      </div>
    </div>

    <p class="cardnote">
      Веб-интерфейс: <a class="applink appurl" href="<?php echo poz_htmlesc($appUrl); ?>" target="_blank" rel="noopener"><?php echo poz_htmlesc($appUrl); ?></a>.
      «Обновить образы» скачивает свежие версии контейнеров Poznote и MCP и перезапускает их.
      Во время операции журнал ниже обновляется автоматически.
    </p>
  </div>

  <div class="card g-ports">
    <div class="cardhead">
      <h2>Порты</h2>
      <span class="hint">Панель запишет значения в poznote.env и перезапустит Poznote</span>
    </div>
    <form method="post" id="portsForm">
      <input type="hidden" name="action" value="saveports">
      <div class="fields">
        <div class="field">
          <label for="webPort">Веб-интерфейс</label>
          <input type="text" inputmode="numeric" id="webPort" name="web_port" maxlength="5" value="<?php echo poz_htmlesc((string)$APP_PORT); ?>">
        </div>
        <div class="field">
          <label for="mcpPort">MCP-сервер (127.0.0.1)</label>
          <input type="text" inputmode="numeric" id="mcpPort" name="mcp_port" maxlength="5" value="<?php echo poz_htmlesc((string)$MCP_PORT); ?>">
        </div>
        <button class="btn warn" type="submit" data-action="saveports" data-label="Смена портов" data-hint="Настройки сохраняются в poznote.env, затем Poznote перезапускается на новых портах."><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5 10 17.5 19.5 6.5"/></svg>Сохранить и перезапустить</button>
      </div>
    </form>
    <div id="portsBox" class="ports"><?php echo poz_ports_tags(poz_ports_state($INITD, $APP_PORT, $MCP_PORT, $PANEL_PORT)); ?></div>
    <p class="cardnote">
      Если порт занят другой программой (или недоступен из-за фаервола), укажите здесь свободный — панель сама запишет его в настройки и перезапустит Poznote.
      Порты 8040 и 8045 должны быть свободны; панель управления использует внутренний порт <?php echo poz_htmlesc((string)$PANEL_PORT); ?> (только localhost).
    </p>
  </div>

  <div class="card g-status">
    <div class="cardhead">
      <h2>Статус</h2>
      <button type="button" class="btn sm ghost right" id="refreshBtn" title="Обновить статус и журнал">
        <svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.4 6.3"/><path d="M20 4.8v5.4h-5.4"/></svg>Обновить статус
      </button>
    </div>
    <pre class="status" id="statusBox"><?php echo poz_htmlesc($status !== '' ? $status : 'Нет данных'); ?></pre>
  </div>

  <div class="card g-log">
    <div class="cardhead">
      <h2>Журнал службы</h2>
      <span class="hint"><?php echo poz_htmlesc(basename($LOG_FILE)); ?> · последние <?php echo (int)$LOG_LINES; ?> строк</span>
      <form method="post"><input type="hidden" name="action" value="clearlog"><button class="btn sm ghost" type="submit" data-action="clearlog" data-label="Очистка журнала" data-hint="Журнал службы будет очищен (только файл журнала, контейнеры не затрагиваются)."><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M9.5 7V4.5h5V7"/><path d="M6.5 7l.9 12.5h9.2L17.5 7"/></svg>Очистить</button></form>
    </div>
    <textarea id="logBox" readonly><?php echo poz_htmlesc($logTail !== '' ? $logTail : 'Журнал пуст.'); ?></textarea>
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

  /* Светлая/тёмная тема. Выбор хранится в localStorage, поэтому сохраняется
     между открытиями страницы; при первом входе берётся системная настройка. */
  function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem('poznote-theme', t); } catch (e) {}
    if (themeBtn) {
      var label = (t === 'dark') ? 'Светлая тема' : 'Тёмная тема';
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
    var roles = [['web', 'Веб'], ['mcp', 'MCP']];
    var out = [];
    roles.forEach(function (r) {
      var p = ports[r[0]];
      if (!p) { return; }
      var text, cls;
      if (!p.busy) {
        text = r[1] + ' ' + p.port + ' — свободен';
        cls = 'ok';
      } else if (p.ours) {
        text = r[1] + ' ' + p.port + ' — занят Poznote';
        cls = 'ok';
      } else {
        text = r[1] + ' ' + p.port + ' — занят' + (p.owner ? ' (' + p.owner + ')' : '');
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
      pill.textContent = data.running ? 'Работает' : 'Остановлен';
      pill.className = 'pill ' + (data.running ? 'on' : 'off');
    }
    if (typeof data.status !== 'undefined') {
      statusBox.textContent = data.status || 'Нет данных';
    }
    portsTags(data.ports);
    if (typeof data.log !== 'undefined') {
      logBox.value = data.log || 'Журнал пуст.';
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
      pText.textContent = 'Выполняется: ' + (label || 'операция');
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
    pTime.textContent = s + ' с';
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
      setNotice(!!d.ok, d.message || (d.ok ? 'Готово.' : 'Ошибка.'));
      tick();
    }).catch(function (e) {
      setBusy(false);
      setRate(10000);
      setNotice(false, 'Не удалось выполнить запрос: ' + e);
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
