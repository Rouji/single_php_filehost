<?php
class CONFIG
{
    const MAX_FILESIZE = 512; //max. filesize in MiB
    const MAX_FILEAGE = 180; //max. age of files in days
    const MIN_FILEAGE = 31; //min. age of files in days
    const DECAY_EXP = 2; //high values penalise larger files more

    const UPLOAD_TIMEOUT = 5*60; //max. time an upload can take before it times out
    const MIN_ID_LENGTH = 3; //min. length of the random file ID
    const MAX_ID_LENGTH = 24; //max. length of the random file ID, set to MIN_ID_LENGTH to disable
    const STORE_PATH = 'files/'; //directory to store uploaded files in
    const LOG_PATH = null; //path to log uploads + resulting links to
    const DOWNLOAD_PATH = '%s'; //the path part of the download url. %s = placeholder for filename
    const MAX_EXT_LEN = 7; //max. length for file extensions
    const EXTERNAL_HOOK = null; //external program to call for each upload
    const AUTO_FILE_EXT = false; //automatically try to detect file extension for files that have none

    const FORCE_HTTPS = false; //force generated links to be https://

    const ADMIN_EMAIL = 'admin@example.com';  //address for inquiries

    public static function SITE_URL() : string
    {
        $proto = ($_SERVER['HTTPS'] ?? 'off') == 'on' || CONFIG::FORCE_HTTPS ? 'https' : 'http';
        return "$proto://{$_SERVER['HTTP_HOST']}";
    }

    public static function SCRIPT_URL() : string
    {
        return CONFIG::SITE_URL().$_SERVER['REQUEST_URI'];
    }
};


// generate a random string of characters with given length
function rnd_str(int $len) : string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $chars_len = strlen($chars);
    $random = random_bytes($len);
    $out = '';

    for ($i = 0; $i < $len; ++$i)
    {
        $out .= $chars[ord($random[$i]) % $chars_len];
    }

    return $out;
}

// check php.ini settings and print warnings if anything's not configured properly
function check_config() : void
{
    $warn_config_value = function($ini_name, $var_name, $var_val)
    {
        $ini_val = intval(ini_get($ini_name));
        if ($ini_val < $var_val)
            print("<pre>Warning: php.ini: $ini_name ($ini_val) set lower than $var_name ($var_val)\n</pre>");
    };

    $warn_config_value('upload_max_filesize', 'MAX_FILESIZE', CONFIG::MAX_FILESIZE);
    $warn_config_value('post_max_size', 'MAX_FILESIZE', CONFIG::MAX_FILESIZE);
    $warn_config_value('max_input_time', 'UPLOAD_TIMEOUT', CONFIG::UPLOAD_TIMEOUT);
    $warn_config_value('max_execution_time', 'UPLOAD_TIMEOUT', CONFIG::UPLOAD_TIMEOUT);
}

//extract extension from a path (does not include the dot)
function ext_by_path(string $path) : string
{
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    //special handling of .tar.* archives
    $ext2 = pathinfo(substr($path,0,-(strlen($ext)+1)), PATHINFO_EXTENSION);
    if ($ext2 === 'tar')
    {
        $ext = $ext2.'.'.$ext;
    }
    return $ext;
}

function ext_by_finfo(string $path) : string
{
    $finfo = finfo_open(FILEINFO_EXTENSION);
    $finfo_ext = finfo_file($finfo, $path);
    finfo_close($finfo);
    if ($finfo_ext != '???')
    {
        return explode('/', $finfo_ext, 2)[0];
    }
    else
    {
        $finfo = finfo_open();
        $finfo_info = finfo_file($finfo, $path);
        finfo_close($finfo);
        if (strstr($finfo_info, 'text') !== false)
        {
            return 'txt';
        }
    }
    return '';
}

// store an uploaded file, given its name and temporary path (e.g. values straight out of $_FILES)
// files are stored wit a randomised name, but with their original extension
//
// $name: original filename
// $tmpfile: temporary path of uploaded file
// $formatted: set to true to display formatted message instead of bare link
function store_file(string $name, string $tmpfile, bool $formatted = false) : void
{
    //create folder, if it doesn't exist
    if (!file_exists(CONFIG::STORE_PATH))
    {
        mkdir(CONFIG::STORE_PATH, 0750, true); //TODO: error handling
    }

    //check file size
    $size = filesize($tmpfile);
    if ($size > CONFIG::MAX_FILESIZE * 1024 * 1024)
    {
        header('HTTP/1.0 413 Payload Too Large');
        $max = CONFIG::MAX_FILESIZE;
        $body = '<div class="card error-card"><div class="error-title">413 — File Too Large</div><p>Maximum file size is <strong>'.$max.' MiB</strong>.</p><a class="btn-home" href="javascript:history.back()">← Go back</a></div>';
        print(html_shell($body));
        return;
    }
    if ($size == 0)
    {
        header('HTTP/1.0 400 Bad Request');
        $body = '<div class="card error-card"><div class="error-title">400 — Empty File</div><p>The uploaded file is empty.</p><a class="btn-home" href="javascript:history.back()">← Go back</a></div>';
        print(html_shell($body));
        return;
    }

    $ext = ext_by_path($name);
    if (empty($ext) && CONFIG::AUTO_FILE_EXT)
    {
        $ext = ext_by_finfo($tmpfile);
    }
    $ext = substr($ext, 0, CONFIG::MAX_EXT_LEN);
    $tries_per_len=3; //try random names a few times before upping the length

    $id_length=CONFIG::MIN_ID_LENGTH;
    if(isset($_POST['id_length']) && ctype_digit($_POST['id_length'])) {
        $id_length = max(CONFIG::MIN_ID_LENGTH, min(CONFIG::MAX_ID_LENGTH, $_POST['id_length']));
    }

    for ($len = $id_length; ; ++$len)
    {
        for ($n=0; $n<=$tries_per_len; ++$n)
        {
            $id = rnd_str($len);
            $basename = $id . (empty($ext) ? '' : '.' . $ext);
            $target_file = CONFIG::STORE_PATH . $basename;

            if (!file_exists($target_file))
                break 2;
        }
    }

    $res = move_uploaded_file($tmpfile, $target_file);
    if (!$res)
    {
        //TODO: proper error handling?
        header('HTTP/1.0 520 Unknown Error');
        return;
    }

    if (CONFIG::EXTERNAL_HOOK !== null)
    {
        putenv('REMOTE_ADDR='.$_SERVER['REMOTE_ADDR']);
        putenv('ORIGINAL_NAME='.$name);
        putenv('STORED_FILE='.$target_file);
        $ret = -1;
        $out = null;
        $last_line = exec(CONFIG::EXTERNAL_HOOK, $out, $ret);
        if ($last_line !== false && $ret !== 0)
        {
            unlink($target_file);
            header('HTTP/1.0 400 Bad Request');
            $body = '<div class="card error-card"><div class="error-title">400 — Upload Rejected</div><p>'.htmlspecialchars($last_line).'</p><a class="btn-home" href="javascript:history.back()">← Go back</a></div>';
            print(html_shell($body));
            return;
        }
    }

    //print the download link of the file
    $url = sprintf(CONFIG::SITE_URL().'/'.CONFIG::DOWNLOAD_PATH, $basename);

    if ($formatted)
    {
        $url_base = strtok(CONFIG::SCRIPT_URL(), '?');
        $body = <<<BODY
<div class="card">
  <div class="result-label">✓ Upload successful</div>
  <div class="result-top">
    <div class="url-box" id="result-url">$url</div>
    <a class="btn-open" href="$url" target="_blank" rel="noopener">Open file ↗</a>
  </div>
  <div class="result-bottom">
    <a class="btn-secondary" href="$url_base">← Upload another</a>
    <button class="btn-secondary" id="copy-btn" onclick="copyUrl()">Copy URL</button>
  </div>
</div>
<style>
.result-top {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 10px;
  align-items: stretch;
  margin-top: 10px;
}
.result-bottom {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 10px;
}
.btn-open {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 18px;
  background: var(--accent);
  color: #fff;
  font-family: 'JetBrains Mono', monospace;
  font-weight: 700;
  font-size: 13px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  border-radius: var(--radius);
  text-align: center;
  text-decoration: none;
  white-space: nowrap;
  transition: opacity .15s;
}
.btn-open:hover { opacity: 0.85; }
.btn-secondary {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 10px;
  background: none;
  border: 1px solid var(--border2);
  border-radius: var(--radius);
  font-family: 'JetBrains Mono', monospace;
  font-size: 12px;
  color: var(--muted);
  cursor: pointer;
  text-decoration: none;
  transition: border-color .15s, color .15s;
}
.btn-secondary:hover { border-color: var(--text); color: var(--text); }
.btn-secondary.copied { border-color: var(--accent); color: var(--accent); }
</style>
<script>
function copyUrl() {
  navigator.clipboard.writeText(document.getElementById('result-url').textContent.trim()).then(() => {
    const btn = document.getElementById('copy-btn');
    btn.textContent = 'Copied!';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = 'Copy URL'; btn.classList.remove('copied'); }, 2000);
  });
}
</script>
BODY;
        print(html_shell($body));
    }
    else
    {
        print("$url\n");
    }

    // log uploader's IP, original filename, etc.
    if (CONFIG::LOG_PATH)
    {
        file_put_contents(
            CONFIG::LOG_PATH,
            implode("\t", array(
                date('c'),
                $_SERVER['REMOTE_ADDR'],
                $size,
                escapeshellarg($name),
                $basename
            )) . "\n",
            FILE_APPEND
        );
    }
}

// purge all files older than their retention period allows.
function purge_files() : void
{
    $num_del = 0;    //number of deleted files
    $total_size = 0; //total size of deleted files

    //for each stored file
    foreach (scandir(CONFIG::STORE_PATH) as $file)
    {
        //skip virtual . and .. files
        if ($file === '.' ||
            $file === '..')
        {
            continue;
        }

        $file = CONFIG::STORE_PATH . $file;

        $file_size = filesize($file) / (1024*1024); //size in MiB
        $file_age = (time()-filemtime($file)) / (60*60*24); //age in days

        //keep all files below the min age
        if ($file_age < CONFIG::MIN_FILEAGE)
        {
            continue;
        }

        //calculate the maximum age in days for this file
        $file_max_age = CONFIG::MIN_FILEAGE +
                        (CONFIG::MAX_FILEAGE - CONFIG::MIN_FILEAGE) *
                        pow(1 - ($file_size / CONFIG::MAX_FILESIZE), CONFIG::DECAY_EXP);

        //delete if older
        if ($file_age > $file_max_age)
        {
            unlink($file);

            print("deleted $file, $file_size MiB, $file_age days old\n");
            $num_del += 1;
            $total_size += $file_size;
        }
    }
    print("Deleted $num_del files totalling $total_size MiB\n");
}

function html_shell(string $body, string $extra_head = '') : string
{
    $mail = CONFIG::ADMIN_EMAIL;
    return <<<EOT
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Filehost</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;600;700&family=Syne:wght@400;700;800&display=swap" rel="stylesheet">
  <script>
    // Apply saved theme before paint to avoid flash
    (function() {
      var t = localStorage.getItem('fh-theme');
      if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    })();
  </script>
  $extra_head
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:       #f0f2f5;
      --bg-inset: #e4e8ee;
      --surface:  #ffffff;
      --border:   #e2e6ec;
      --border2:  #ccd2db;
      --text:     #1a2030;
      --muted:    #7a8699;
      --accent:   #0969da;
      --accent2:  #1a7f5a;
      --danger:   #cf222e;
      --radius:   10px;
      --grid-line: rgba(0,0,0,0.035);
      --grid-line2: rgba(0,0,0,0.025);
      --glow: rgba(9,105,218,0.06);
    }

    [data-theme="dark"] {
      --bg:       #0c0e10;
      --bg-inset: #0a0c0e;
      --surface:  #13161a;
      --border:   #1f2429;
      --border2:  #2a3038;
      --text:     #c8d0db;
      --muted:    #5a6472;
      --accent:   #00e5a0;
      --accent2:  #00b3ff;
      --danger:   #ff4f6a;
      --grid-line: rgba(255,255,255,0.02);
      --grid-line2: rgba(255,255,255,0.015);
      --glow: rgba(0,229,160,0.07);
    }

    html, body {
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      font-family: 'JetBrains Mono', monospace;
      font-size: 14px;
      line-height: 1.6;
      transition: background .2s, color .2s;
    }

    body {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 48px 20px 80px;
      background-image:
        radial-gradient(ellipse 60% 40% at 50% -10%, var(--glow) 0%, transparent 70%),
        repeating-linear-gradient(0deg, transparent, transparent 39px, var(--grid-line) 39px, var(--grid-line) 40px),
        repeating-linear-gradient(90deg, transparent, transparent 39px, var(--grid-line2) 39px, var(--grid-line2) 40px);
    }

    .wrap { width: 100%; max-width: 640px; }

    /* ── Header ── */
    header {
      margin-bottom: 40px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    header h1 {
      font-family: 'Syne', sans-serif;
      font-weight: 800;
      font-size: 28px;
      letter-spacing: -0.5px;
      color: var(--text);
    }
    header h1 span { color: var(--accent); }
    header .tag {
      font-size: 11px;
      color: var(--muted);
      border: 1px solid var(--border2);
      border-radius: 4px;
      padding: 2px 7px;
      letter-spacing: 0.04em;
    }
    header .spacer { flex: 1; }

    /* ── Theme toggle ── */
    #theme-toggle {
      background: none;
      border: 1px solid var(--border2);
      border-radius: 99px;
      padding: 5px 12px;
      font-family: 'JetBrains Mono', monospace;
      font-size: 12px;
      color: var(--muted);
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: border-color .15s, color .15s;
      white-space: nowrap;
    }
    #theme-toggle:hover { border-color: var(--text); color: var(--text); }
    #theme-toggle .icon { font-size: 14px; line-height: 1; }

    /* ── Card ── */
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 32px;
      margin-bottom: 24px;
      position: relative;
      overflow: hidden;
    }
    .card::before {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, var(--glow) 0%, transparent 50%);
      pointer-events: none;
    }

    /* ── Drop zone ── */
    #drop-zone {
      border: 2px dashed var(--border2);
      border-radius: var(--radius);
      padding: 48px 24px;
      text-align: center;
      cursor: pointer;
      transition: border-color .2s, background .2s;
      position: relative;
    }
    #drop-zone.dragover {
      border-color: var(--accent);
      background: rgba(0,229,160,0.05);
    }
    #drop-zone input[type=file] {
      position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
    }
    .drop-icon {
      font-size: 36px;
      margin-bottom: 12px;
      display: block;
      filter: grayscale(0.4);
    }
    .drop-label {
      font-size: 15px;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 4px;
    }
    .drop-sub {
      font-size: 12px;
      color: var(--muted);
    }
    #file-name {
      margin-top: 14px;
      font-size: 12px;
      color: var(--accent);
      min-height: 18px;
      word-break: break-all;
    }

    /* ── Progress ── */
    #progress-wrap {
      display: none;
      margin-top: 20px;
    }
    #progress-wrap.visible { display: block; }
    .prog-label {
      display: flex;
      justify-content: space-between;
      font-size: 11px;
      color: var(--muted);
      margin-bottom: 6px;
    }
    .prog-bar-bg {
      height: 4px;
      background: var(--border);
      border-radius: 99px;
      overflow: hidden;
    }
    .prog-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--accent), var(--accent2));
      border-radius: 99px;
      width: 0%;
      transition: width .1s linear;
    }

    /* ── Upload button ── */
    .btn-upload {
      display: block;
      width: 100%;
      margin-top: 20px;
      padding: 13px;
      background: var(--accent);
      color: #fff;
      font-family: 'JetBrains Mono', monospace;
      font-weight: 700;
      font-size: 13px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      border: none;
      border-radius: var(--radius);
      cursor: pointer;
      transition: opacity .15s, transform .1s;
    }
    .btn-upload:hover { opacity: 0.85; }
    .btn-upload:active { transform: scale(0.98); }
    .btn-upload:disabled { opacity: 0.35; cursor: not-allowed; }

    /* ── Result card ── */
    .result-label {
      font-size: 11px;
      color: var(--muted);
      letter-spacing: 0.08em;
      text-transform: uppercase;
      margin-bottom: 10px;
    }
    .url-row {
      display: flex;
      gap: 10px;
      align-items: stretch;
    }
    .url-box {
      flex: 1;
      background: var(--bg-inset);
      border: 1px solid var(--border2);
      border-radius: var(--radius);
      padding: 11px 14px;
      font-size: 13px;
      color: var(--accent);
      word-break: break-all;
      font-family: 'JetBrains Mono', monospace;
    }
    .btn-copy {
      flex-shrink: 0;
      background: var(--border2);
      border: 1px solid var(--border2);
      color: var(--text);
      font-family: 'JetBrains Mono', monospace;
      font-size: 12px;
      font-weight: 600;
      padding: 0 16px;
      border-radius: var(--radius);
      cursor: pointer;
      transition: background .15s, color .15s;
      letter-spacing: 0.04em;
    }
    .btn-copy:hover { background: var(--accent); color: #fff; }
    .btn-copy.copied { background: var(--accent); color: #fff; }

    .btn-home {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-top: 20px;
      padding: 9px 16px;
      background: transparent;
      border: 1px solid var(--border2);
      border-radius: var(--radius);
      color: var(--muted);
      font-family: 'JetBrains Mono', monospace;
      font-size: 12px;
      cursor: pointer;
      text-decoration: none;
      transition: border-color .15s, color .15s;
    }
    .btn-home:hover { border-color: var(--text); color: var(--text); }

    /* ── Info section ── */
    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 16px;
    }
    .info-tile {
      background: var(--bg-inset);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 14px 16px;
    }
    .info-tile .val {
      font-size: 20px;
      font-weight: 700;
      color: var(--text);
      font-family: 'Syne', sans-serif;
    }
    .info-tile .val span { color: var(--accent); font-size: 14px; }
    .info-tile .key {
      font-size: 11px;
      color: var(--muted);
      letter-spacing: 0.05em;
      margin-top: 2px;
    }

    .formula {
      background: var(--bg-inset);
      border: 1px solid var(--border);
      border-left: 3px solid var(--accent2);
      border-radius: var(--radius);
      padding: 12px 16px;
      font-size: 12px;
      color: var(--muted);
      margin-bottom: 16px;
    }
    .formula strong { color: var(--text); }

    /* ── CLI section ── */
    .cli-block {
      background: var(--bg-inset);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 14px 16px;
      position: relative;
      margin-bottom: 10px;
    }
    .cli-block code {
      font-size: 12px;
      color: var(--accent2);
      display: block;
      white-space: pre-wrap;
      word-break: break-all;
    }
    .cli-copy {
      position: absolute;
      top: 8px; right: 8px;
      background: var(--border2);
      border: none;
      color: var(--muted);
      font-family: 'JetBrains Mono', monospace;
      font-size: 10px;
      padding: 3px 8px;
      border-radius: 4px;
      cursor: pointer;
      transition: color .15s;
    }
    .cli-copy:hover { color: var(--text); }

    section h2 {
      font-family: 'Syne', sans-serif;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    section h2::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border);
    }

    section { margin-bottom: 32px; }

    .links-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
    .pill-link {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 6px 13px;
      border: 1px solid var(--border2);
      border-radius: 99px;
      font-size: 11px;
      color: var(--muted);
      text-decoration: none;
      transition: border-color .15s, color .15s;
    }
    .pill-link:hover { border-color: var(--accent); color: var(--accent); }

    footer {
      margin-top: 40px;
      text-align: center;
      font-size: 11px;
      color: var(--muted);
    }
    footer a { color: var(--muted); text-decoration: underline dotted; }
    footer a:hover { color: var(--text); }

    /* ── Error state ── */
    .error-card { border-color: var(--danger); }
    .error-card::before { background: linear-gradient(135deg, rgba(255,79,106,0.06) 0%, transparent 50%); }
    .error-title { color: var(--danger); font-size: 15px; font-weight: 700; margin-bottom: 8px; }

    @media (max-width: 480px) {
      .info-grid { grid-template-columns: 1fr; }
      .url-row { flex-direction: column; }
      .btn-copy { padding: 10px; }
    }
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>file<span>host</span></h1>
    <span class="tag">v1</span>
    <span class="spacer"></span>
    <button id="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark mode">
      <span class="icon" id="theme-icon">☀️</span>
      <span id="theme-label">Light</span>
    </button>
  </header>
  $body
  <footer>
    Questions or abuse reports? <a href="mailto:$mail">$mail</a> &nbsp;·&nbsp;
    <a href="https://github.com/Rouji/single_php_filehost" target="_blank" rel="noopener">source</a>
  </footer>
  <script>
    function applyTheme(t) {
      if (t === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.getElementById('theme-icon').textContent = '☀️';
        document.getElementById('theme-label').textContent = 'Light';
      } else {
        document.documentElement.removeAttribute('data-theme');
        document.getElementById('theme-icon').textContent = '🌙';
        document.getElementById('theme-label').textContent = 'Dark';
      }
    }
    function toggleTheme() {
      var current = document.documentElement.getAttribute('data-theme');
      var next = current === 'dark' ? 'light' : 'dark';
      localStorage.setItem('fh-theme', next);
      applyTheme(next);
    }
    // Sync button label to whatever theme was applied by the anti-FOUC script
    applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
  </script>
</div>
</body>
</html>
EOT;
}

function send_text_file(string $filename, string $content) : void
{
    header('Content-type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Content-Length: '.strlen($content));
    print($content);
}

// send a ShareX custom uploader config as .json
function send_sharex_config() : void
{
    $name = $_SERVER['SERVER_NAME'];
    $site_url = str_replace("?sharex", "", CONFIG::SCRIPT_URL());
    send_text_file($name.'.sxcu', <<<EOT
{
  "Version": "18.0.2",
  "DestinationType": "ImageUploader, FileUploader",
  "RequestMethod": "POST",
  "RequestURL": "$site_url",
  "FileFormName": "file",
  "Body": "MultipartFormData"
}
EOT);
}

// send a Hupl uploader config as .hupl (which is just JSON)
function send_hupl_config() : void
{
    $name = $_SERVER['SERVER_NAME'];
    $site_url = str_replace("?hupl", "", CONFIG::SCRIPT_URL());
    send_text_file($name.'.hupl', <<<EOT
{
  "name": "$name",
  "type": "http",
  "targetUrl": "$site_url",
  "fileParam": "file"
}
EOT);
}

// print a styled index page
function print_index() : void
{
    $site_url = CONFIG::SCRIPT_URL();
    $sharex_url = $site_url.'?sharex';
    $hupl_url = $site_url.'?hupl';
    $decay = CONFIG::DECAY_EXP;
    $min_age = CONFIG::MIN_FILEAGE;
    $max_size = CONFIG::MAX_FILESIZE;
    $max_age = CONFIG::MAX_FILEAGE;
    $max_id_length = CONFIG::MAX_ID_LENGTH;

    $length_info_html = '';
    if (CONFIG::MIN_ID_LENGTH !== CONFIG::MAX_ID_LENGTH)
    {
        $length_info_html = "<p>To use a longer file ID (up to <strong>$max_id_length</strong> chars), add <code>-F id_length=&lt;number&gt;</code></p>";
    }

    $fixed_retention = (CONFIG::MIN_FILEAGE === CONFIG::MAX_FILEAGE || CONFIG::DECAY_EXP == 0);
    if ($fixed_retention)
    {
        $retention_tile_val = "$max_age <span>days</span>";
        $retention_kept     = "$max_age days";
        $retention_formula  = '';
        $retention_desc     = "<p style=\"font-size:12px;color:var(--muted)\">All files are kept for exactly <strong style=\"color:var(--text)\">$max_age days</strong>.</p>";
    }
    else
    {
        $retention_tile_val = "$min_age – $max_age <span>days</span>";
        $retention_kept     = "$min_age – $max_age days";
        $retention_formula  = "<div class=\"formula\"><strong>max age</strong> = MIN_AGE + (MAX_AGE - MIN_AGE) &times; (1 &minus; FILE_SIZE / MAX_SIZE)<sup>$decay</sup></div>";
        $retention_desc     = "<p style=\"font-size:12px;color:var(--muted)\">Larger files are rotated sooner. Small files stay up to <strong style=\"color:var(--text)\">$max_age days</strong>.</p>";
    }

    $body = <<<BODY
<!-- ── Upload card ── -->
<div class="card">
  <div id="drop-zone">
    <input type="file" name="file" id="file-input" />
    <span class="drop-icon">📂</span>
    <div class="drop-label">Drop a file here, or click to browse</div>
    <div class="drop-sub">Max $max_size MiB &nbsp;·&nbsp; Kept $retention_kept</div>
    <div id="file-name"></div>
  </div>
  <div id="progress-wrap">
    <div class="prog-label">
      <span id="prog-text">Uploading…</span>
      <span id="prog-pct">0%</span>
    </div>
    <div class="prog-bar-bg"><div class="prog-bar-fill" id="prog-fill"></div></div>
  </div>
  <button class="btn-upload" id="upload-btn" disabled>Upload</button>
</div>

<!-- ── CLI section ── -->
<section>
  <h2>Command Line</h2>
  <div class="cli-block">
    <code>curl -F "file=@/path/to/file.jpg" $site_url</code>
    <button class="cli-copy" onclick="cliCopy(this, 'curl -F \"file=@/path/to/file.jpg\" $site_url')">copy</button>
  </div>
  <div class="cli-block">
    <code>echo "hello" | curl -F "file=@-;filename=.txt" $site_url</code>
    <button class="cli-copy" onclick="cliCopy(this, 'echo \"hello\" | curl -F \"file=@-;filename=.txt\" $site_url')">copy</button>
  </div>
  $length_info_html
</section>

<!-- ── Apps section ── -->
<section>
  <h2>Upload Clients</h2>
  <div class="links-row">
    <a class="pill-link" href="$sharex_url">⬇ ShareX config <span style="color:var(--muted);font-size:10px">(Windows)</span></a>
    <a class="pill-link" href="$hupl_url">⬇ Hupl config <span style="color:var(--muted);font-size:10px">(Android)</span></a>
  </div>
</section>

<!-- ── Retention section ── -->
<section>
  <h2>Retention Policy</h2>
  <div class="info-grid">
    <div class="info-tile">
      <div class="val">$max_size <span>MiB</span></div>
      <div class="key">Max file size</div>
    </div>
    <div class="info-tile">
      <div class="val">$retention_tile_val</div>
      <div class="key">Retention window</div>
    </div>
  </div>
  $retention_formula
  $retention_desc
</section>

<script>
const dropZone  = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const fileLabel = document.getElementById('file-name');
const uploadBtn = document.getElementById('upload-btn');
const progWrap  = document.getElementById('progress-wrap');
const progFill  = document.getElementById('prog-fill');
const progPct   = document.getElementById('prog-pct');
const progText  = document.getElementById('prog-text');

function setFile(file) {
  if (!file) return;
  fileLabel.textContent = '📄 ' + file.name + '  (' + (file.size / 1024 / 1024).toFixed(2) + ' MiB)';
  uploadBtn.disabled = false;
  uploadBtn.dataset.file = '1';
}

fileInput.addEventListener('change', () => setFile(fileInput.files[0]));

dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e => {
  e.preventDefault();
  dropZone.classList.remove('dragover');
  const file = e.dataTransfer.files[0];
  if (!file) return;
  const dt = new DataTransfer();
  dt.items.add(file);
  fileInput.files = dt.files;
  setFile(file);
});

uploadBtn.addEventListener('click', () => {
  if (!fileInput.files[0]) return;
  const fd = new FormData();
  fd.append('file', fileInput.files[0]);
  fd.append('formatted', 'true');

  const xhr = new XMLHttpRequest();
  xhr.open('POST', '$site_url');

  xhr.upload.addEventListener('progress', e => {
    if (!e.lengthComputable) return;
    const pct = Math.round(e.loaded / e.total * 100);
    progFill.style.width = pct + '%';
    progPct.textContent = pct + '%';
    if (pct === 100) progText.textContent = 'Processing…';
  });

  xhr.addEventListener('load', () => {
    document.open();
    document.write(xhr.responseText);
    document.close();
  });

  xhr.addEventListener('error', () => {
    alert('Upload failed. Please try again.');
    progWrap.classList.remove('visible');
    uploadBtn.disabled = false;
  });

  progWrap.classList.add('visible');
  uploadBtn.disabled = true;
  uploadBtn.textContent = 'Uploading…';
  xhr.send(fd);
});

function cliCopy(btn, text) {
  navigator.clipboard.writeText(text).then(() => {
    btn.textContent = 'copied!';
    setTimeout(() => { btn.textContent = 'copy'; }, 1800);
  });
}
</script>
BODY;

    print(html_shell($body));
}


// decide what to do, based on POST parameters etc.
if (isset($_FILES['file']['name']) &&
    isset($_FILES['file']['tmp_name']) &&
    is_uploaded_file($_FILES['file']['tmp_name']))
{
    //file was uploaded, store it
    $formatted = isset($_REQUEST['formatted']);
    store_file($_FILES['file']['name'],
              $_FILES['file']['tmp_name'],
              $formatted);
}
else if (isset($_GET['sharex']))
{
    send_sharex_config();
}
else if (isset($_GET['hupl']))
{
    send_hupl_config();
}
else if ($argv[1] ?? null === 'purge')
{
    purge_files();
}
else
{
    check_config();
    print_index();
}
