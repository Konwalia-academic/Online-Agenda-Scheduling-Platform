<?php
/*
 * One-time web installer. Creates app/config.php, the MySQL database,
 * tables, the administrator account and default settings.
 * Run once at /install/ then it locks itself via storage/installed.lock.
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__, 2));
define('APP', ROOT . '/app');
define('STORAGE', ROOT . '/storage');

define('SCHEMA_SQL', "
CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(64) NOT NULL PRIMARY KEY,
  svalue TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendars (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  ctype ENUM('graph','upload','url','caldav') NOT NULL DEFAULT 'url',
  config TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_sync_at DATETIME NULL,
  last_sync_error TEXT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events_cache (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  calendar_id INT NOT NULL,
  uid VARCHAR(255) NOT NULL,
  title VARCHAR(500) NOT NULL DEFAULT '',
  location VARCHAR(500) NOT NULL DEFAULT '',
  start_utc DATETIME NOT NULL,
  end_utc DATETIME NOT NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_cal_uid (calendar_id, uid),
  KEY idx_range (start_utc, end_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  status ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
  name VARCHAR(200) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  theme VARCHAR(500) NOT NULL,
  location_type ENUM('zoom','tencent','phone','inperson') NOT NULL,
  location_detail VARCHAR(500) NOT NULL DEFAULT '',
  attendees TEXT NULL,
  appendix TEXT NULL,
  start_utc DATETIME NOT NULL,
  end_utc DATETIME NOT NULL,
  duration_min INT NOT NULL,
  invite_token VARCHAR(64) NOT NULL,
  admin_token VARCHAR(64) NOT NULL,
  graph_event_id VARCHAR(255) NULL,
  graph_error TEXT NULL,
  lang CHAR(2) NOT NULL DEFAULT 'en',
  created_at DATETIME NOT NULL,
  responded_at DATETIME NULL,
  KEY idx_status (status),
  KEY idx_range (start_utc, end_utc),
  KEY idx_invite (invite_token),
  KEY idx_admin (admin_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$lang = ($_GET['lang'] ?? '') === 'zh' ? 'zh' : 'en';

function inst(string $en, string $zh): string
{
    global $lang;
    return $lang === 'zh' ? $zh : $en;
}

// ---------- requirements ----------
$requirements = [];
$requirements['php'] = version_compare(PHP_VERSION, '8.0.0', '>=');
foreach (['pdo_mysql', 'curl', 'openssl', 'mbstring', 'json', 'session', 'filter'] as $ext) {
    $requirements[$ext] = extension_loaded($ext);
}
$requirements['storage_writable'] = is_writable(STORAGE) || @chmod(STORAGE, 0775);

// ---------- locked? ----------
$lockFile = STORAGE . '/installed.lock';
$locked = is_file($lockFile);

// ---------- POST handling ----------
$error = null;
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked && !empty($_POST['install'])) {
    $reqOk = !in_array(false, $requirements, true);
    if (!$reqOk) {
        $error = inst('Fix the server requirements first.', '请先解决服务器环境问题。');
    } else {
        $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
        $dbPort = (int)($_POST['db_port'] ?? 3306);
        $dbName = trim((string)($_POST['db_name'] ?? 'agenda'));
        $dbUser = trim((string)($_POST['db_user'] ?? ''));
        $dbPass = (string)($_POST['db_pass'] ?? '');
        $adminUser = trim((string)($_POST['admin_user'] ?? ''));
        $adminPass = (string)($_POST['admin_pass'] ?? '');
        $adminPass2 = (string)($_POST['admin_pass2'] ?? '');
        $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
        $siteTitle = trim((string)($_POST['site_title'] ?? 'My Agenda'));
        $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
        $defaultLang = ($_POST['default_lang'] ?? 'en') === 'zh' ? 'zh' : 'en';

        if ($adminUser === '' || $adminEmail === '' || $siteUrl === '') {
            $error = inst('Please fill in all required fields.', '请填写所有必填项。');
        } elseif (strlen($adminPass) < 8) {
            $error = inst('Password must be at least 8 characters.', '密码至少需要 8 个字符。');
        } elseif ($adminPass !== $adminPass2) {
            $error = inst('Passwords do not match.', '两次输入的密码不一致。');
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = inst('Please enter a valid e-mail address.', '请输入有效的电子邮箱。');
        } elseif (!preg_match('#^https?://#i', $siteUrl)) {
            $error = inst('Site base URL must start with http(s)://', '站点基础地址必须以 http(s):// 开头。');
        } else {
            try {
                // connect without dbname first so we can create it
                $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort);
                $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $dbName) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `" . str_replace('`', '', $dbName) . "`");
            } catch (Throwable $ex) {
                // maybe the DB already exists and user can't create DBs
                try {
                    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
                    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                } catch (Throwable $ex2) {
                    $error = inst('Database error: ', '数据库错误：') . $ex->getMessage()
                        . ' ' . inst('(If the database does not exist, create it manually first.)', '（如果数据库不存在，请先手动创建。）');
                }
            }

            if (!$error) {
                try {
                    // write config
                    $secret = bin2hex(random_bytes(32));
                    $config = "<?php\n// Generated by the installer.\n"
                        . "define('APP_SECRET', '" . $secret . "');\n"
                        . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
                        . "define('DB_PORT', " . (int)$dbPort . ");\n"
                        . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
                        . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
                        . "define('DB_PASS', " . var_export($dbPass, true) . ");\n";
                    file_put_contents(APP . '/config.php', $config);

                    foreach (array_filter(array_map('trim', explode(';', SCHEMA_SQL))) as $stmtSql) {
                        if ($stmtSql !== '') {
                            $pdo->exec($stmtSql);
                        }
                    }

                    // seed settings
                    $ins = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)');
                    $settings = [
                        'app_title' => $siteTitle,
                        'default_lang' => $defaultLang,
                        'timezone' => trim((string)($_POST['timezone'] ?? 'UTC')),
                        'site_url' => $siteUrl,
                        'theme_color' => '#3b82f6',
                        'invite_code' => '4310',
                        'admin_email' => $adminEmail,
                        'slot_step' => '15',
                        'min_duration' => '15',
                        'max_duration' => '360',
                        'buffer_min' => '0',
                        'max_advance' => '60',
                        'duration_options' => '15,30,45,60,90,120',
                        'sync_interval_min' => '10',
                        'working_hours' => json_encode([
                            0 => [], 1 => [['s' => '09:00', 'e' => '18:00']],
                            2 => [['s' => '09:00', 'e' => '18:00']], 3 => [['s' => '09:00', 'e' => '18:00']],
                            4 => [['s' => '09:00', 'e' => '18:00']], 5 => [['s' => '09:00', 'e' => '18:00']],
                            6 => [],
                        ]),
                        'graph_tenant' => 'common',
                        'graph_calendars' => '[]',
                        'graph_booking_calendar' => '',
                    ];
                    foreach ($settings as $k => $v) {
                        $ins->execute([$k, $v]);
                    }

                    $insAdmin = $pdo->prepare('INSERT INTO admin (username, password_hash) VALUES (?, ?)');
                    $insAdmin->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);

                    // storage subdirs + lock
                    foreach (['uploads', 'cache', 'logs', 'tmp'] as $d) {
                        if (!is_dir(STORAGE . '/' . $d)) {
                            mkdir(STORAGE . '/' . $d, 0775, true);
                        }
                    }
                    file_put_contents($lockFile, date('c'));
                    $done = true;
                } catch (Throwable $ex) {
                    $error = inst('Database error: ', '数据库错误：') . $ex->getMessage();
                    if (is_file(APP . '/config.php')) {
                        @unlink(APP . '/config.php');
                    }
                }
            }
        }
    }
}

$reqLabels = [
    'php' => 'PHP ≥ 8.0',
    'pdo_mysql' => 'pdo_mysql',
    'curl' => 'curl',
    'openssl' => 'openssl',
    'mbstring' => 'mbstring',
    'json' => 'json',
    'session' => 'session',
    'filter' => 'filter',
    'storage_writable' => inst('storage/ writable', 'storage/ 可写'),
];
$reqOk = !in_array(false, $requirements, true);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= inst('Installation', '安装向导') ?></title>
<style>
  :root { --brand:#3b82f6; --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f8fafc; --ok:#10b981; --bad:#ef4444; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif; background:var(--bg); color:var(--ink); }
  .wrap { max-width:720px; margin:0 auto; padding:32px 16px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:24px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
  h1 { font-size:22px; margin:0 0 8px; }
  .field { margin-bottom:14px; }
  label { display:block; font-weight:600; font-size:14px; margin-bottom:6px; }
  input, select { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:10px; font-size:14px; }
  input:focus, select:focus { outline:none; border-color:var(--brand); }
  .row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  @media(max-width:600px){ .row { grid-template-columns:1fr; } }
  .btn { display:inline-block; border:none; cursor:pointer; border-radius:10px; padding:11px 22px; font-size:15px; font-weight:600; background:var(--brand); color:#fff; }
  .btn:disabled { opacity:.6; }
  .ok { color:var(--ok); } .bad { color:var(--bad); }
  .alert { border-radius:10px; padding:12px 16px; margin-bottom:16px; }
  .alert-bad { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
  .alert-ok { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
  .lang { text-align:right; margin-bottom:16px; }
  .lang a { color:var(--muted); }
  .req-item { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f3f4f6; }
</style>
</head>
<body>
<div class="wrap">
  <div class="lang"><a href="?lang=<?= $lang === 'zh' ? 'en' : 'zh' ?>"><?= $lang === 'zh' ? 'EN' : '中' ?></a></div>
  <div class="card">
    <h1><?= inst('Welcome to the Agenda Platform installer', '欢迎使用日程预约平台安装程序') ?></h1>
    <p style="color:var(--muted);margin:0;"><?= inst('This wizard sets up the database, administrator account and site settings.', '本向导将配置数据库、管理员账号与站点设置。') ?></p>
  </div>

  <?php if ($done): ?>
    <div class="card">
      <div class="alert alert-ok"><?= inst('Installation complete!', '安装完成！') ?></div>
      <p><?= inst('The system is ready. Default invitation code is <b>4310</b> — change it from the admin panel after signing in.', '系统已就绪。默认邀请码为 <b>4310</b>——请在登录后台后尽快修改。') ?></p>
      <a class="btn" href="../admin/login.php"><?= inst('Go to admin sign in', '前往后台登录') ?></a>
      <a class="btn" style="background:#e5e7eb;color:#111827;" href="../"><?= inst('Go to site', '前往站点') ?></a>
    </div>
  <?php elseif ($locked): ?>
    <div class="card">
      <div class="alert alert-bad"><?= inst('Already installed. Remove storage/installed.lock only if you intentionally want to re-install.', '系统已安装。只有在你确实想重新安装时，才删除 storage/installed.lock。') ?></div>
      <a class="btn" href="../admin/login.php"><?= inst('Go to admin sign in', '前往后台登录') ?></a>
    </div>
  <?php else: ?>

  <?php if ($error): ?><div class="card"><div class="alert alert-bad"><?= htmlspecialchars($error) ?></div></div><?php endif; ?>

  <div class="card">
    <h1 style="font-size:18px;">1 · <?= inst('Server requirements', '服务器环境检查') ?></h1>
    <?php foreach ($reqLabels as $key => $label): ?>
      <div class="req-item"><span><?= htmlspecialchars($label) ?></span><span class="<?= $requirements[$key] ? 'ok' : 'bad' ?>"><?= $requirements[$key] ? '✓' : '✗' ?></span></div>
    <?php endforeach; ?>
    <?php if ($reqOk): ?><p class="ok" style="margin-bottom:0;"><?= inst('All requirements are satisfied.', '所有要求均已满足。') ?></p><?php endif; ?>
  </div>

  <form method="post" action="?lang=<?= $lang ?>">
    <div class="card">
      <h1 style="font-size:18px;">2 · <?= inst('Database', '数据库') ?></h1>
      <div class="row">
        <div class="field"><label><?= inst('Database host', '数据库主机') ?></label><input type="text" name="db_host" value="localhost" required></div>
        <div class="field"><label><?= inst('Port', '端口') ?></label><input type="number" name="db_port" value="3306" required></div>
      </div>
      <div class="row">
        <div class="field"><label><?= inst('Database name', '数据库名称') ?></label><input type="text" name="db_name" value="agenda" required></div>
        <div class="field"><label><?= inst('Database user', '数据库用户名') ?></label><input type="text" name="db_user" required></div>
      </div>
      <div class="field"><label><?= inst('Database password', '数据库密码') ?></label><input type="password" name="db_pass"></div>
    </div>

    <div class="card">
      <h1 style="font-size:18px;">3 · <?= inst('Administrator account', '管理员账号') ?></h1>
      <div class="row">
        <div class="field"><label><?= inst('Administrator username', '管理员用户名') ?></label><input type="text" name="admin_user" required></div>
        <div class="field"><label><?= inst('Your e-mail (receives booking requests)', '您的邮箱（接收预约申请通知）') ?></label><input type="email" name="admin_email" required></div>
      </div>
      <div class="row">
        <div class="field"><label><?= inst('Administrator password (≥ 8 chars)', '管理员密码（至少 8 位）') ?></label><input type="password" name="admin_pass" required></div>
        <div class="field"><label><?= inst('Confirm password', '确认密码') ?></label><input type="password" name="admin_pass2" required></div>
      </div>
    </div>

    <div class="card">
      <h1 style="font-size:18px;">4 · <?= inst('Site settings', '站点设置') ?></h1>
      <div class="row">
        <div class="field"><label><?= inst('Site title', '站点标题') ?></label><input type="text" name="site_title" value="My Agenda" required></div>
        <div class="field"><label><?= inst('Default language', '默认语言') ?></label>
          <select name="default_lang"><option value="en">English</option><option value="zh">简体中文</option></select>
        </div>
      </div>
      <div class="row">
        <div class="field"><label><?= inst('Site base URL', '站点基础地址') ?></label>
          <input type="url" name="site_url" value="<?= htmlspecialchars($scheme . '://' . $host . $dir) ?>" required>
          <small style="color:var(--muted)"><?= inst('e.g. https://agenda.example.com (no trailing slash)', '例如 https://agenda.example.com（不要以 / 结尾）') ?></small>
        </div>
        <div class="field"><label><?= inst('Time zone', '时区') ?></label>
          <select name="timezone">
            <option value="Asia/Shanghai">Asia/Shanghai (UTC+8)</option>
            <option value="UTC">UTC</option>
            <option value="Asia/Tokyo">Asia/Tokyo</option>
            <option value="Europe/London">Europe/London</option>
            <option value="Europe/Berlin">Europe/Berlin</option>
            <option value="America/New_York">America/New_York</option>
            <option value="America/Los_Angeles">America/Los_Angeles</option>
          </select>
        </div>
      </div>
    </div>

    <button type="submit" name="install" value="1" class="btn" <?= $reqOk ? '' : 'disabled' ?>><?= inst('Install', '开始安装') ?></button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
