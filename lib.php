<?php
declare(strict_types=1);

/**
 * هسته‌ی پروژه: ذخیره‌سازی JSON محافظت‌شده، محتوای پیش‌فرض و آپلود امن.
 * نیازمندی: PHP 8.2+؛ MySQL یا فایل محافظت‌شده برای پیش‌نمایش.
 */

const ROOT_DIR   = __DIR__;
const DATA_DIR   = __DIR__ . '/data';
const UPLOAD_DIR = __DIR__ . '/uploads';
// خط اول هر فایل داده؛ اگر کسی آدرس فایل را باز کند فقط 403 می‌بیند و محتوا لو نمی‌رود.
const STORE_GUARD = "<?php http_response_code(403); exit; ?>\n";

date_default_timezone_set('Asia/Tehran');

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* Storage: MySQL in production, protected local files for preview. */
function app_config(): array {
    static $config;
    if ($config === null) $config = is_file(ROOT_DIR . '/config.php') ? require ROOT_DIR . '/config.php' : [];
    return $config;
}
function database(): ?PDO {
    static $db;
    $cfg = app_config()['mysql'] ?? [];
    if (empty($cfg['database'])) return null;
    if (!$db) {
        $dsn = !empty($cfg['socket']) ? 'mysql:unix_socket=' . $cfg['socket'] : 'mysql:host=' . ($cfg['host'] ?? '127.0.0.1') . ';port=' . ($cfg['port'] ?? 3306);
        $db = new PDO($dsn . ';dbname=' . $cfg['database'] . ';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    return $db;
}
function store_read(string $name, array $default = []): array {
    if ($db = database()) {
        $q = $db->prepare('SELECT payload FROM app_store WHERE name = ?'); $q->execute([$name]);
        $raw = $q->fetchColumn();
    } else {
        $path = DATA_DIR . '/' . basename($name) . '.json.php';
        $raw = is_file($path) ? substr((string)file_get_contents($path), strlen(STORE_GUARD)) : false;
    }
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : $default;
}
function store_write(string $name, array $data): bool {
    try {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($db = database()) {
            $q=$db->prepare('INSERT INTO app_store (name,payload) VALUES (?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)');
            return $q->execute([$name,$json]);
        }
        if (!is_dir(DATA_DIR)) mkdir(DATA_DIR,0700,true);
        $file=DATA_DIR.'/'.basename($name).'.json.php';
        $tmp=$file.'.'.bin2hex(random_bytes(5)).'.php';
        if (file_put_contents($tmp,STORE_GUARD.$json,LOCK_EX)===false) return false;
        chmod($tmp,0600);
        return rename($tmp,$file);
    } catch (Throwable $e) { error_log('Storage write failed'); return false; }
}
function store_update(string $name, callable $update): array {
    $db=database(); $lock=null;
    if ($db) {
        $q=$db->prepare('SELECT GET_LOCK(?, 10)'); $q->execute(['comeback:'.($db->query('SELECT DATABASE()')->fetchColumn()).':'.$name]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Storage busy');
    } else {
        $lock=fopen(DATA_DIR.'/'.basename($name).'.lock.php','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Storage busy');
    }
    try {
        $data=$update(store_read($name));
        if (!store_write($name,$data)) throw new RuntimeException('Storage failed');
        return $data;
    } finally {
        if ($db) { $q=$db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute(['comeback:'.($db->query('SELECT DATABASE()')->fetchColumn()).':'.$name]); }
        elseif ($lock) { flock($lock,LOCK_UN); fclose($lock); }
    }
}
function start_app_session(): void {
    if (session_status()===PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode','1');
    session_name('comeback_session');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
    session_start();
}
function public_csrf_token(): string { return $_SESSION['public_csrf'] ??= bin2hex(random_bytes(32)); }
function public_submission_token(): string { return $_SESSION['submission_id'] ??= bin2hex(random_bytes(16)); }
function telegram_send(string $text): array {
    $s=store_read('settings');
    if (empty($s['telegram_token']) || empty($s['telegram_chat_id'])) return ['ok'=>false,'error'=>'توکن یا شناسهٔ چت تنظیم نشده است.'];
    if (!function_exists('curl_init')) return ['ok'=>false,'error'=>'افزونهٔ cURL روی سرور فعال نیست.'];
    $ch=curl_init('https://api.telegram.org/bot'.$s['telegram_token'].'/sendMessage');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['chat_id'=>$s['telegram_chat_id'],'text'=>mb_substr($text,0,4000)]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $result=is_string($body)?json_decode($body,true):null;
    return !empty($result['ok']) ? ['ok'=>true,'error'=>''] : ['ok'=>false,'error'=>'اتصال یا تنظیمات تلگرام را بررسی کن (HTTP '.(int)$code.').'];
}
function notify_record(string $collection, string $id, string $message, bool $enabled): void {
    if (!$enabled) return;
    $result=telegram_send($message);
    store_update($collection, static function($data) use($id,$result) {
        foreach($data['items'] as &$row) if($row['id']===$id) { $row['telegram_status']=$result['ok']?'sent':'failed'; $row['telegram_error']=$result['error']; }
        return $data;
    });
}

/* ---------- محتوا ---------- */

function default_content(): array
{
    return [
        'meta'=>['title'=>'برای طیبا · یک دفتر، از تمام دلم','recipient'=>'طیبا'],
        'hero'=>[
            'eyebrow'=>'برای تو، با کمی تأخیر و تمامِ حواسم',
            'headline'=>"بعضی حرف‌ها،\nباید با حوصله گفته شوند.",
            'paragraphs'=>[
                'طیبا جان، می‌دانم بی‌توجهی من آزارت داد. مسئولیتش با من است؛ برای احساست توضیح یا بهانه‌ای ندارم.',
                'این دفترچه را با دست ساختم تا خاطره‌هایمان را کنار هم بگذارم. چون نتوانستم به دستت برسانم، نسخهٔ مجازی‌اش را ساختم؛ تا اگر خواستی، در آرامش ورقش بزنی.',
                'وقت گذاشتن برای این دفتر، گذشته را پاک نمی‌کند. فقط راهی است برای گفتنِ حرف‌هایی که باید زودتر می‌گفتم. اگر مایل بودی، چند صفحه همراهم باش.'
            ],
            'notebook'=>'uploads/sample-notebook.svg','notebook_caption'=>'جای عکسِ دفترچه‌ای که با دست ساختم · تصویر نمونه','cta'=>'بیا آرام ورق بزنیم'
        ],
        'section2'=>['title'=>'میانِ حرف‌ها و خاطره‌ها','subtitle'=>'بعضی لحظه‌ها، آرام در دل می‌مانند.','gallery_title'=>'تکه‌هایی از روزهای خوب','cta'=>'آخرین حرفم'],
        'cards'=>[
            ['icon'=>'♡','date'=>'پیش از خاطره‌ها','title'=>'توجه، در چیزهای کوچک بود.','text'=>'این مدت به سهم خودم در اتفاقی که افتاد فکر کرده‌ام. فهمیدم دوست داشتن، وقتی در رفتار دیده نشود، کافی نیست.
می‌خواهم گوش دادن بدون دفاع، حضور داشتن و وقت گذاشتن را تمرین کنم. تغییر را نمی‌شود با یک نامه ثابت کرد؛ باید در رفتار و در طول زمان دیده شود.','voice'=>''],
            ['icon'=>'✳','date'=>'یک قاب نمونه · جای خاطرهٔ واقعی ما','title'=>'همان عصر، همان لبخند.','text'=>'اینجا می‌خواهم از یک خاطرهٔ واقعی‌مان بنویسم؛ از جزئیات کوچکی که هنوز یادم مانده.
این متن نمونه است. در پنل، داستان خودمان و عکس‌های همان روز را جایگزین می‌کنم.','voice'=>''],
            ['icon'=>'☁','date'=>'جای یادداشت شخصی تو','title'=>'چیزهایی که می‌خواهم بهتر بلد باشم.','text'=>'می‌خواهم وقتی کسی برایم مهم است، حضورم هم این را نشان بدهد. حرفش را کامل بشنوم، برایش وقت مشخص بگذارم و به قول‌های کوچک پایبند بمانم.
می‌دانم این مسیر مسئولیت خودم است؛ مستقل از تصمیمی که تو برای ادامه می‌گیری.','voice'=>'']
        ],
        'gallery'=>[
            ['src'=>'assets/demo/flowers.jpg','caption'=>'برای آرامشِ روزهایت · عکس نمونه','page'=>0],
            ['src'=>'assets/demo/cafe.jpg','caption'=>'یک عصرِ آرام · عکس نمونه','page'=>1],
            ['src'=>'assets/demo/sea.jpg','caption'=>'جایی کنارِ آرامش · عکس نمونه','page'=>1],
            ['src'=>'assets/demo/couple.jpg','caption'=>'جای عکسِ خودمان · عکس نمونه','page'=>2]
        ],
        'voice'=>['title'=>'چند کلمه با صدای من','src'=>''],
        'music'=>['title'=>'آهنگِ این دفتر','src'=>'','volume'=>.35],
        'final'=>[
            'photo'=>'assets/demo/couple.jpg','sticker_seconds'=>3,'sticker_text'=>'بعضی دلتنگی‌ها، کلمه ندارند…',
            'eyebrow'=>'یک دعوت دوستانه، اگر خودت بخواهی','title'=>"یک دیدار کوتاه،\nبرای سپردن این دفتر به تو.",
            'paragraphs'=>['اگر مایل باشی، دوست دارم یک بار کوتاه و دوستانه ببینمت و دفترچه را به دستت بدهم؛ در زمان و مکانی که تو راحتی.','این دیدار تعهدی برای برگشتن نیست. می‌توانی فقط دفترچه را بگیری، یا بگویی نمی‌خواهی همدیگر را ببینیم. به هر دو انتخابت احترام می‌گذارم.'],
            'yes_label'=>'برای گرفتن دفتر می‌آیم','no_label'=>'تمایلی به دیدار ندارم',
            'reply_title'=>'ممنون که این چند صفحه را خواندی.',
            'yes_reply'=>'ممنون که گفتی. این دیدار فقط یک قرار کوتاه و دوستانه است. دفترچه را با مهر به دستت می‌دهم و به مرزهایی که انتخاب کنی احترام می‌گذارم.',
            'no_reply'=>'پیامت را شنیدم و به تصمیمت احترام می‌گذارم. لازم نیست توضیح بیشتری بدهی. ممنون که برای خواندن این دفتر وقت گذاشتی؛ برایت آرامش می‌خواهم.',
            'signature'=>'با مهر، رضا'
        ]
    ];
}

function load_content(): array
{
    $d = default_content();
    $s = store_read('content', []);
    foreach (['meta', 'hero', 'section2', 'voice', 'music', 'final'] as $k) {
        if (isset($s[$k]) && is_array($s[$k])) {
            $d[$k] = array_merge($d[$k], $s[$k]);
        }
    }
    foreach (['cards', 'gallery'] as $k) {
        if (isset($s[$k]) && is_array($s[$k])) {
            $d[$k] = array_values($s[$k]);
        }
    }
    return $d;
}

/* ---------- آپلود امن ---------- */

function is_safe_upload_path(?string $path): bool
{
    return $path !== null && (bool) preg_match('#^(uploads/[\w.\-]+|assets/demo/[\w.\-]+)$#', $path) && is_file(ROOT_DIR . '/' . $path);
}

function normalize_files(array $f): array
{
    $out = [];
    if (!isset($f['name']) || !is_array($f['name'])) {
        return $out;
    }
    foreach (array_keys($f['name']) as $i) {
        $out[] = [
            'name'     => $f['name'][$i],
            'type'     => $f['type'][$i] ?? '',
            'tmp_name' => $f['tmp_name'][$i] ?? '',
            'error'    => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $f['size'][$i] ?? 0,
        ];
    }
    return $out;
}

/**
 * @param string $kind 'image' | 'audio'
 * @return string|null مسیر نسبی فایل ذخیره‌شده، یا null (و پیام خطا در $error)
 */
function save_upload(array $f, string $kind, ?string &$error = null): ?string
{
    $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($code !== UPLOAD_ERR_OK) {
        $error = 'آپلود ناموفق بود؛ احتمالاً حجم فایل از سقف سرور بیشتره (upload_max_filesize).';
        return null;
    }
    $max = $kind === 'image' ? 12 * 1024 * 1024 : 25 * 1024 * 1024;
    if (($f['size'] ?? 0) > $max) {
        $error = 'حجم فایل «' . ($f['name'] ?? '') . '» بیش از حد مجازه (عکس ۱۲ مگ، ویس ۲۵ مگ).';
        return null;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $map  = $kind === 'image'
        ? ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif']
        : [
            'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
            'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/mp4' => 'm4a',
            'audio/ogg' => 'ogg', 'application/ogg' => 'ogg',
            'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/vnd.wave' => 'wav',
            'audio/webm' => 'webm', 'video/webm' => 'webm',
            'audio/aac' => 'aac', 'audio/x-hx-aac-adts' => 'aac',
        ];
    if (!isset($map[$mime])) {
        $error = 'فرمت «' . ($f['name'] ?? '') . '» پشتیبانی نمی‌شه. '
            . ($kind === 'image' ? 'از JPG، PNG یا WebP استفاده کن.' : 'از MP3، M4A، OGG یا WAV استفاده کن.');
        return null;
    }
    if ($kind === 'image' && @getimagesize($f['tmp_name']) === false) {
        $error = 'فایل «' . ($f['name'] ?? '') . '» تصویر معتبر نیست.';
        return null;
    }
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true)) {
        $error = 'پوشه‌ی uploads قابل ساخت نیست.';
        return null;
    }
    $name = date('ymd') . '-' . bin2hex(random_bytes(5)) . '.' . $map[$mime];
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        $error = 'ذخیره‌ی فایل روی سرور ناموفق بود (دسترسی نوشتن پوشه‌ی uploads؟).';
        return null;
    }
    return 'uploads/' . $name;
}

function delete_upload(?string $path): void
{
    // فایل‌های نمونه (sample-*) هیچ‌وقت پاک نمی‌شن.
    if (!str_starts_with((string)$path, 'uploads/') || !is_safe_upload_path($path) || str_starts_with(basename((string) $path), 'sample-')) {
        return;
    }
    @unlink(ROOT_DIR . '/' . $path);
}
