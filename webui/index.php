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
<style>body{margin:0;font-family:Verdana,Arial,sans-serif;background:#eef1f5}.card{max-width:520px;margin:80px auto;background:#fff;border:1px solid #d8dde5;border-radius:10px;padding:28px;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,.06)}h2{color:#c0392b;margin:0 0 12px}p{color:#444;line-height:1.5}</style>
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
<style>
  :root { --accent:#2f6fed; --ok:#1e9e57; --err:#c0392b; --bg:#eef1f5; --card:#ffffff; --line:#d8dde5; }
  * { box-sizing:border-box; }
  [hidden] { display:none !important; }
  body { margin:0; background:var(--bg); color:#222; font-family:Verdana,Arial,Helvetica,sans-serif; font-size:13px; }
  .wrap { max-width:960px; margin:0 auto; padding:18px; }
  .head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
  .brand { display:flex; align-items:center; gap:12px; }
  .logo { width:40px; height:40px; border-radius:9px; background:var(--accent); color:#fff; display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:bold; }
  .brand h1 { font-size:19px; margin:0; }
  .brand small { color:#6b7480; }
  .pill { padding:5px 12px; border-radius:20px; font-weight:bold; font-size:12px; color:#fff; white-space:nowrap; }
  .pill.on { background:var(--ok); }
  .pill.off { background:#8a939e; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:14px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
  .card h2 { font-size:13px; margin:0 0 10px; color:#3a4250; text-transform:uppercase; letter-spacing:.4px; }
  .actions { display:flex; flex-wrap:wrap; gap:8px; }
  form { display:inline; margin:0; }
  button, .btn { font-family:inherit; font-size:13px; padding:9px 14px; border-radius:7px; border:1px solid var(--line); background:#f7f9fc; color:#222; cursor:pointer; text-decoration:none; display:inline-block; }
  button:hover, .btn:hover { background:#eef3fb; }
  button:disabled { opacity:.55; cursor:progress; }
  .btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
  .btn.primary:hover { background:#255fd0; }
  .btn.warn { background:#fff4e5; border-color:#f0c48a; }
  pre, textarea { width:100%; font-family:'Courier New',Courier,monospace; font-size:12px; background:#0f1620; color:#d7e3f2; border:1px solid #22303f; border-radius:8px; padding:10px; margin:0; white-space:pre-wrap; word-break:break-word; }
  pre.status { max-height:180px; overflow:auto; }
  textarea { height:320px; resize:vertical; }
  .notice { padding:10px 12px; border-radius:8px; margin-bottom:14px; }
  .notice.ok { background:#e7f6ec; border:1px solid #b6e3c6; color:#1e6b40; }
  .notice.err { background:#fdecea; border:1px solid #f5c6c0; color:#9b2c20; }
  .muted { color:#6b7480; }
  .progress { display:flex; align-items:center; gap:14px; }
  .spinner { width:26px; height:26px; flex:0 0 26px; border:3px solid #cdd8ea; border-top-color:var(--accent); border-radius:50%; animation:spin .8s linear infinite; }
  @keyframes spin { to { transform:rotate(360deg); } }
  .progress .ptxt { font-weight:bold; color:#2a3444; }
  .progress .ptime { margin-left:auto; font-family:'Courier New',Courier,monospace; color:#6b7480; }
  .progress .phint { color:#6b7480; margin-top:3px; font-weight:normal; }
  .progress .pstep { margin-top:5px; color:#8a95a5; font-family:'Courier New',Courier,monospace; font-size:11px; max-width:640px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .portform { display:flex; flex-wrap:wrap; align-items:center; gap:8px 10px; margin:0 0 10px; }
  .portform label { color:#3a4250; font-weight:bold; }
  .portform input[type=text] { font-family:inherit; font-size:13px; padding:8px 10px; width:84px; border:1px solid var(--line); border-radius:7px; background:#fff; color:#222; }
  .ptag { display:inline-block; padding:4px 10px; border-radius:14px; font-size:12px; margin:0 6px 6px 0; }
  .ptag.ok { background:#e7f6ec; border:1px solid #b6e3c6; color:#1e6b40; }
  .ptag.bad { background:#fdecea; border:1px solid #f5c6c0; color:#9b2c20; }
</style>
</head>
<body>
<div class="wrap">

  <div class="head">
    <div class="brand">
      <div class="logo">P</div>
      <div>
        <h1>Poznote</h1>
        <small class="muted">Заметки, папки, задачи и поиск — на вашем NAS</small>
      </div>
    </div>
    <span id="pill" class="pill <?php echo $running ? 'on' : 'off'; ?>"><?php echo $running ? 'Работает' : 'Остановлен'; ?></span>
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

  <div class="card">
    <h2>Управление</h2>
    <div class="actions">
      <a class="btn primary applink" href="<?php echo poz_htmlesc($appUrl); ?>" target="_blank" rel="noopener">Открыть Poznote ↗</a>
      <form method="post"><input type="hidden" name="action" value="update"><button class="btn warn" type="submit" data-action="update" data-label="Обновление образов" data-hint="Скачиваются свежие образы Poznote и MCP, затем контейнеры перезапускаются. Это может занять несколько минут.">Обновить образы</button></form>
      <form method="post"><input type="hidden" name="action" value="restart"><button class="btn" type="submit" data-action="restart" data-label="Перезапуск" data-hint="Служба останавливается и запускается заново.">Перезапустить</button></form>
      <form method="post"><input type="hidden" name="action" value="start"><button class="btn" type="submit" data-action="start" data-label="Запуск" data-hint="Запускаются контейнеры и панель управления.">Запустить</button></form>
      <form method="post"><input type="hidden" name="action" value="stop"><button class="btn" type="submit" data-action="stop" data-label="Остановка" data-hint="Останавливаются контейнеры Poznote и MCP.">Остановить</button></form>
      <form method="post"><input type="hidden" name="action" value="reset"><button class="btn" type="submit" data-action="reset" data-label="Пересоздание контейнеров" data-hint="Контейнеры удаляются и создаются заново. Данные (SQLite, вложения) сохраняются.">Пересоздать контейнеры</button></form>
      <button type="button" class="btn" id="refreshBtn">Обновить статус</button>
      <form method="post"><input type="hidden" name="action" value="clearlog"><button class="btn" type="submit" data-action="clearlog" data-label="Очистка журнала" data-hint="Журнал службы будет очищен (только файл журнала, контейнеры не затрагиваются).">Очистить журнал</button></form>
    </div>
    <p class="muted" style="margin:10px 0 0">
      Веб-интерфейс: <a class="applink appurl" href="<?php echo poz_htmlesc($appUrl); ?>" target="_blank" rel="noopener"><?php echo poz_htmlesc($appUrl); ?></a>.
      «Обновить образы» скачивает свежие версии контейнеров Poznote и MCP и перезапускает их.
      Во время операции журнал ниже обновляется автоматически.
    </p>
  </div>

  <div class="card">
    <h2>Порты</h2>
    <form method="post" class="portform" id="portsForm">
      <input type="hidden" name="action" value="saveports">
      <label for="webPort">Веб-интерфейс</label>
      <input type="text" inputmode="numeric" id="webPort" name="web_port" maxlength="5" value="<?php echo poz_htmlesc((string)$APP_PORT); ?>">
      <label for="mcpPort">MCP-сервер (127.0.0.1)</label>
      <input type="text" inputmode="numeric" id="mcpPort" name="mcp_port" maxlength="5" value="<?php echo poz_htmlesc((string)$MCP_PORT); ?>">
      <button class="btn warn" type="submit" data-action="saveports" data-label="Смена портов" data-hint="Настройки сохраняются в poznote.env, затем Poznote перезапускается на новых портах.">Сохранить и перезапустить</button>
    </form>
    <div id="portsBox" class="ports"><?php echo poz_ports_tags(poz_ports_state($INITD, $APP_PORT, $MCP_PORT, $PANEL_PORT)); ?></div>
    <p class="muted" style="margin:4px 0 0">
      Если порт занят другой программой (или недоступен из-за фаервола), укажите здесь свободный — панель сама запишет его в настройки и перезапустит Poznote.
      Порты 8040 и 8045 должны быть свободны; панель управления использует внутренний порт <?php echo poz_htmlesc((string)$PANEL_PORT); ?> (только localhost).
    </p>
  </div>

  <div class="card">
    <h2>Статус</h2>
    <pre class="status" id="statusBox"><?php echo poz_htmlesc($status !== '' ? $status : 'Нет данных'); ?></pre>
  </div>

  <div class="card">
    <h2>Журнал службы (<?php echo poz_htmlesc(basename($LOG_FILE)); ?>, последние <?php echo (int)$LOG_LINES; ?> строк)</h2>
    <textarea id="logBox" readonly><?php echo poz_htmlesc($logTail !== '' ? $logTail : 'Журнал пуст.'); ?></textarea>
  </div>

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
    if (!msg) { notice.hidden = true; return; }
    notice.hidden = false;
    notice.className = 'notice ' + (ok ? 'ok' : 'err');
    notice.textContent = msg;
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
})();
</script>
</body>
</html>
