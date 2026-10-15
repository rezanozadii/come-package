<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
start_app_session();
require_once __DIR__ . '/../visitor.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

function admin_url(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/admin/index.php');
    $path = preg_match('#^(.*?/admin)(?:/index\.php)?/?$#', $script, $m) ? $m[1] : dirname($script);
    return '/' . trim($path, '/') . '/';
}
$adminTabs = ['p1','p2','media','music','p3','responses','visits','telegram','security'];
$activeTab = is_string($_GET['tab'] ?? null) && in_array($_GET['tab'], $adminTabs, true) ? $_GET['tab'] : 'p1';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? admin_url(), PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestPath !== admin_url()) {
    header('Location: ' . admin_url() . '?tab=' . rawurlencode($activeTab), true, 302);
    exit;
}


function admin_csrf(): string
{
    if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['admin_csrf'];
}
function flash(string $type, string $text): void { $_SESSION['admin_flash'] = [$type, $text]; }
function go(string $tab = ''): never
{
    header('Location: ' . admin_url() . '?tab=' . rawurlencode($tab ?: 'p1') . '#' . rawurlencode($tab ?: 'p1'), true, 303);
    exit;
}
function post_str(string $key, int $max = 500): string
{
    $value = $_POST[$key] ?? '';
    return mb_substr(trim(is_string($value) ? $value : ''), 0, $max);
}
function post_arr(string $key): array
{
    $value = $_POST[$key] ?? [];
    return is_array($value) ? array_values(array_map(static fn($v) => is_string($v) ? $v : '', $value)) : [];
}
function post_lines(string $key): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', post_str($key, 8000)) ?: []), static fn($v) => $v !== ''));
}
function uploaded_file(string $name, ?string $key = null): array
{
    $file = $_FILES[$name] ?? [];
    if ($key === null) return $file;
    $out = [];
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) $out[$field] = $file[$field][$key] ?? ($field === 'error' ? UPLOAD_ERR_NO_FILE : '');
    return $out;
}
function media_paths(array $content): array
{
    $paths = [];
    array_walk_recursive($content, static function ($value) use (&$paths): void {
        if (is_string($value) && preg_match('#^uploads/[\w.\-]+$#', $value)) $paths[] = $value;
    });
    return array_unique($paths);
}
function save_content(): void
{
    // One JSON field avoids PHP max_input_vars silently truncating large books.
    if (isset($_POST['content_payload'])) {
        $payload = json_decode(is_string($_POST['content_payload']) ? $_POST['content_payload'] : '', true);
        if (!is_array($payload)) { flash('err', 'اطلاعات دفتر ناقص است؛ ذخیره انجام نشد.'); return; }
        foreach ($payload as $key => $values) {
            if (!is_string($key) || !preg_match('/^([a-z][a-z0-9_]*)(\[\])?$/', $key, $m) || !is_array($values)) continue;
            if (in_array($m[1], ['csrf','action','content_payload'], true)) continue;
            foreach ($values as $value) if (!is_string($value)) { flash('err', 'اطلاعات دفتر معتبر نیست.'); return; }
            $_POST[$m[1]] = !empty($m[2]) ? array_values($values) : ($values[0] ?? '');
        }
    }
    if (post_str('form_complete', 10) !== '1') { flash('err', 'فرم ناقص به سرور رسیده؛ هیچ تغییری ذخیره نشد. صفحه را تازه کن یا محدودیت حجم هاست را افزایش بده.'); return; }

    $original = load_content();
    $c = $original;
    $errors = [];
    $newUploads = [];
    $upload = static function (array $file, string $kind) use (&$errors, &$newUploads): ?string {
        $error = null;
        $path = save_upload($file, $kind, $error);
        if ($error) $errors[] = $error;
        if ($path) $newUploads[] = $path;
        return $path;
    };
    $c['meta']['title'] = post_str('meta_title', 80) ?: 'فقط برای تو';
    $c['meta']['recipient'] = post_str('meta_recipient', 60);
    $c['hero']['eyebrow'] = post_str('hero_eyebrow', 120);
    $c['hero']['headline'] = post_str('hero_headline', 200) ?: 'برای تو';
    $c['hero']['paragraphs'] = post_lines('hero_paragraphs');
    $c['hero']['notebook_caption'] = post_str('hero_caption', 160);
    $c['hero']['cta'] = post_str('hero_cta', 40) ?: 'دفتر را باز کن';
    if ($path = $upload(uploaded_file('notebook_file'), 'image')) $c['hero']['notebook'] = $path;
    $c['section2']['title'] = post_str('s2_title', 160);
    $c['section2']['subtitle'] = post_str('s2_sub', 240);
    $c['section2']['gallery_title'] = post_str('gallery_title', 120);
    $c['section2']['cta'] = post_str('s2_cta', 40) ?: 'آخرین حرفم';

    $keys = post_arr('card_key');
    $titles = post_arr('card_title');
    $texts = post_arr('card_text');
    $icons = post_arr('card_icon');
    $dates = post_arr('card_date');
    $removeVoices = post_arr('card_voice_rm');
    $cards = [];
    $pageByKey = [];
    foreach ($keys as $i => $key) {
        if (!preg_match('/^(c\d+|n[a-z0-9]+)$/', $key) || isset($pageByKey[$key])) continue;
        $previous = preg_match('/^c(\d+)$/', $key, $m) ? ($original['cards'][(int) $m[1]] ?? []) : [];
        $card = [
            'icon' => mb_substr(trim($icons[$i] ?? ''), 0, 8),
            'title' => mb_substr(trim($titles[$i] ?? ''), 0, 100),
            'text' => mb_substr(trim($texts[$i] ?? ''), 0, 1800),
            'date' => mb_substr(trim($dates[$i] ?? ''), 0, 80),
            'voice' => in_array($key, $removeVoices, true) ? '' : ($previous['voice'] ?? ''),
        ];
        if ($path = $upload(uploaded_file('card_voice', $key), 'audio')) $card['voice'] = $path;
        if ($card['title'] === '' && $card['text'] === '' && $card['voice'] === '') continue;
        $pageByKey[$key] = count($cards);
        $cards[] = $card;
    }
    if (!$cards) {
        flash('err', 'حداقل یک خاطره با عنوان یا متن نگه دار. تغییرات ذخیره نشد.');
        foreach ($newUploads as $path) delete_upload($path);
        return;
    }
    $c['cards'] = $cards;
    $knownPhotos = array_column($original['gallery'], null, 'src');
    $remove = post_arr('g_rm');
    $caps = post_arr('g_cap');
    $pages = post_arr('g_page');
    $gallery = [];
    $seen = [];
    foreach (post_arr('g_src') as $i => $src) {
        if (!isset($knownPhotos[$src]) || isset($seen[$src]) || in_array($src, $remove, true) || !is_safe_upload_path($src)) continue;
        $seen[$src] = true;
        $gallery[] = ['src' => $src, 'caption' => mb_substr(trim($caps[$i] ?? ''), 0, 120), 'page' => $pageByKey[$pages[$i] ?? ''] ?? 0];
    }
    $newCaps = post_arr('g_new_cap');
    $newPages = post_arr('g_new_page');
    foreach (normalize_files($_FILES['g_new'] ?? []) as $i => $file) {
        if ($path = $upload($file, 'image')) $gallery[] = ['src' => $path, 'caption' => mb_substr(trim($newCaps[$i] ?? ''), 0, 120), 'page' => $pageByKey[$newPages[$i] ?? ''] ?? 0];
    }
    $c['gallery'] = $gallery;
    $c['voice']['title'] = post_str('voice_title', 80) ?: 'چند کلمه با صدای من';
    if (!empty($_POST['voice_rm'])) $c['voice']['src'] = '';
    if ($path = $upload(uploaded_file('voice_file'), 'audio')) $c['voice']['src'] = $path;
    $c['music']['title'] = post_str('music_title', 80) ?: 'آهنگِ این دفتر';
    $c['music']['volume'] = max(0.05, min(1, (float) post_str('music_volume', 10) / 100));
    if (!empty($_POST['music_rm'])) $c['music']['src'] = '';
    if ($path = $upload(uploaded_file('music_file'), 'audio')) $c['music']['src'] = $path;
    $f = &$c['final'];
    $f['sticker_seconds'] = max(1, min(8, (int) post_str('f_seconds', 1)));
    $f['sticker_text'] = post_str('f_sticker_text', 80);
    $f['eyebrow'] = post_str('f_eyebrow', 80);
    $f['title'] = post_str('f_title', 200) ?: 'اگر دلت خواست…';
    $f['paragraphs'] = post_lines('f_paragraphs');
    $f['yes_label'] = post_str('f_yes', 60) ?: 'می‌خوام دفتر رو بگیرم';
    $f['no_label'] = post_str('f_no', 60) ?: 'هنوز آماده نیستم';
    $f['reply_title'] = post_str('f_reply_title', 120) ?: 'چند کلمه برای تو';
    $f['yes_reply'] = post_str('f_yes_reply', 1600);
    $f['no_reply'] = post_str('f_no_reply', 1600);
    $f['signature'] = post_str('f_sign', 120);
    if ($path = $upload(uploaded_file('couple_file'), 'image')) $f['photo'] = $path;
    unset($f);
    if (!store_write('content', $c)) {
        foreach ($newUploads as $path) delete_upload($path);
        flash('err', 'ذخیره در دیتابیس انجام نشد. فایل‌ها و محتوای قبلی محفوظ‌اند؛ دوباره تلاش کن.');
        return;
    }
    // Delete old media only after the new content is committed and only when no longer referenced.
    foreach (array_diff(media_paths($original), media_paths($c)) as $path) delete_upload($path);
    flash($errors ? 'err' : 'ok', $errors ? 'متن‌ها ذخیره شد، اما بعضی فایل‌ها نیاز به بررسی دارند: ' . implode(' ', array_unique($errors)) : 'دفترت به‌روز شد. تغییرات همین حالا روی صفحه دیده می‌شود.');
}

$csrf = admin_csrf();
$auth = store_read('admin');
$logged = !empty($_SESSION['admin_ok']) && (int) ($_SESSION['admin_version'] ?? 0) === (int) ($auth['version'] ?? 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { flash('err', 'حجم فایل‌ها از محدودیت سرور بیشتر است. هر بار فایل‌های کمتری انتخاب کن.'); go('media'); }
    if (!hash_equals($csrf, post_str('csrf', 128))) { flash('err', 'مهلت این فرم تمام شده. صفحه را تازه کن و دوباره تلاش کن.'); go(); }
    $action = post_str('action', 40);
    if ($action === 'setup' && !$auth) {
        $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        $setupKey = app_config()['setup_key'] ?? '';
        if (!$local && ($setupKey === '' || !hash_equals($setupKey, post_str('setup_key', 300)))) { flash('err', 'کلید راه‌اندازی موجود در config.php را وارد کن.'); go(); }
        $username = post_str('username', 60); $password = post_str('password', 300);
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username) || mb_strlen($password) < 10) { flash('err', 'نام کاربری انگلیسی حداقل ۳ حرف و رمز حداقل ۱۰ کاراکتر باشد.'); go(); }
        try {
            store_update('admin', static function($existing) use($username,$password) {
                if ($existing) throw new RuntimeException('Already set up');
                return ['username'=>$username,'hash'=>password_hash($password,PASSWORD_DEFAULT),'version'=>1];
            });
            flash('ok', 'حساب ساخته شد. با مشخصات خودت وارد شو.');
        } catch (Throwable $e) { flash('err', 'ساخت حساب انجام نشد؛ تنظیمات ذخیره‌سازی را بررسی کن.'); }
        go();
    }
    if ($action === 'login' && !$logged) {
        $limits = store_read('login_limits', ['items' => []]);
        $key = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $now = time();
        $limits['items'] = array_filter($limits['items'] ?? [], static fn($row) => (int) ($row['expires'] ?? 0) > $now);
        $limit = $limits['items'][$key] ?? ['fails' => 0, 'expires' => $now + 900];
        if ((int) $limit['fails'] >= 5) {
            flash('err', 'برای امنیت حساب، ورود موقتاً محدود شده. ۱۵ دقیقه بعد دوباره تلاش کن.');
        } elseif (hash_equals((string) ($auth['username'] ?? 'admin'), post_str('username', 60)) && password_verify(post_str('password', 300), (string) ($auth['hash'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            $_SESSION['admin_version'] = (int) ($auth['version'] ?? 1);
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
            record_entry('login_success');
            unset($limits['items'][$key]);
            store_write('login_limits', $limits);
        } else {
            record_entry('login_failed');
            $limit['fails'] = (int) $limit['fails'] + 1;
            $limits['items'][$key] = $limit;
            store_write('login_limits', $limits);
            flash('err', 'نام کاربری یا رمز عبور درست نیست.');
        }
        go();
    }
    if (!$logged) { flash('err', 'برای ادامه وارد حساب مدیریت شو.'); go(); }
    switch ($action) {
        case 'logout':
            $_SESSION = [];
            session_regenerate_id(true);
            go();
        case 'save':
            save_content();
            $tab = post_str('tab', 20);
            go(in_array($tab, ['p1', 'p2', 'media', 'music', 'p3'], true) ? $tab : 'p1');
        case 'clear_responses':
        case 'clear_visits':
            $name = $action === 'clear_visits' ? 'visits' : 'responses';
            $ok = store_write($name, ['items' => []]);
            flash($ok ? 'ok' : 'err', $ok ? 'فهرست پاک شد.' : 'پاک کردن فهرست انجام نشد؛ دوباره تلاش کن.');
            go($name);
        case 'credentials':
            $username = post_str('username', 60);
            $password = post_str('new', 300);
            if (!password_verify(post_str('cur', 300), (string) ($auth['hash'] ?? ''))) flash('err', 'رمز فعلی درست نیست.');
            elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) flash('err', 'نام کاربری باید ۳ تا ۶۰ حرف انگلیسی، عدد، نقطه، خط تیره یا زیرخط باشد.');
            elseif ($password !== '' && mb_strlen($password) < 8) flash('err', 'رمز جدید باید دست‌کم ۸ کاراکتر داشته باشد.');
            elseif ($password !== post_str('new2', 300)) flash('err', 'تکرار رمز جدید یکسان نیست.');
            else {
                $next = array_merge($auth, ['username' => $username, 'hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : $auth['hash'], 'version' => (int) ($auth['version'] ?? 1) + 1]);
                if (store_write('admin', $next)) {
                    session_regenerate_id(true);
                    $_SESSION['admin_version'] = $next['version'];
                    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
                    flash('ok', 'مشخصات ورود به‌روز شد. نشست‌های قبلی دیگر به پنل دسترسی ندارند.');
                } else flash('err', 'تغییر اطلاعات ورود ذخیره نشد؛ مشخصات قبلی هنوز معتبر است.');
            }
            go('security');
        case 'telegram':
            $settings = store_read('settings');
            $token = post_str('telegram_token', 180);
            $chat = post_str('telegram_chat_id', 100);
            if ($token !== '' && !preg_match('/^\d+:[a-zA-Z0-9_-]{20,}$/', $token)) { flash('err', 'فرمت توکن ربات درست نیست. توکن کامل BotFather را وارد کن.'); go('telegram'); }
            if ($chat !== '' && !preg_match('/^(?:-?\d+|@[a-zA-Z0-9_]{5,})$/', $chat)) { flash('err', 'شناسهٔ چت باید عددی یا نام کانال با @ باشد.'); go('telegram'); }
            if (!empty($_POST['telegram_token_rm'])) $settings['telegram_token'] = '';
            if ($token !== '') $settings['telegram_token'] = $token;
            $settings['telegram_chat_id'] = $chat;
            $settings['telegram_responses'] = !empty($_POST['telegram_responses']);
            $settings['telegram_visits'] = !empty($_POST['telegram_visits']);
            $settings['visit_logging'] = !empty($_POST['visit_logging']);
            $ok = store_write('settings', $settings);
            flash($ok ? 'ok' : 'err', $ok ? 'تنظیمات تلگرام ذخیره شد. برای بررسی اتصال، پیام آزمایشی بفرست.' : 'تنظیمات ذخیره نشد؛ دوباره تلاش کن.');
            go('telegram');
        case 'test_telegram':
            $result = telegram_send('🤍 پیام آزمایشی دفتر خاطره‌ها' . "\n" . 'اتصال پنل مدیریت با موفقیت برقرار شد.');
            flash(!empty($result['ok']) ? 'ok' : 'err', !empty($result['ok']) ? 'پیام آزمایشی به تلگرام رسید.' : 'پیام ارسال نشد: ' . ($result['error'] ?: 'ابتدا توکن ربات و شناسهٔ چت را ذخیره کن.'));
            go('telegram');
    }
    go();
}
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
function flash_html(?array $flash): void
{
    if ($flash) echo '<div class="flash ' . e($flash[0]) . '" role="status">' . e($flash[1]) . '</div>';
}
function layout_head(string $title): void
{
    $adminPath = rtrim(admin_url(), '/');
    $fontUrl = dirname($adminPath) . '/assets/fonts/Vazirmatn-Variable.woff2';
    $fontUrl = '/' . ltrim($fontUrl, '/');
    $css = str_replace('../assets/fonts/Vazirmatn-Variable.woff2', $fontUrl, (string)file_get_contents(__DIR__.'/admin.css'));

    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><base href="<?= e(admin_url()) ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#f4efe7"><title><?= e($title) ?></title><style id="admin-styles"><?= $css ?></style></head><?php
}
function book_icon(): string { return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v15M12 5C9 3 5 3 2 4v15c3-1 7-1 10 1 3-2 7-2 10-1V4c-3-1-7-1-10 1Z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>'; }
function form_tokens(string $action): void { ?><input type="hidden" name="csrf" value="<?= e(admin_csrf()) ?>"><input type="hidden" name="action" value="<?= e($action) ?>"><?php }
if (!$logged):
    layout_head('ورود · دفترِ ما'); ?>
<body class="auth">
  <div class="auth-art" aria-hidden="true"><span class="auth-kicker">SOME THINGS ARE WORTH KEEPING.</span><div class="paper-book"><span>دفترِ ما</span><i>حرف‌هایی که هنوز<br>روی کاغذ مانده‌اند.</i><b>♡</b></div><p>برای چیزهایی که هنوز ارزشِ توجه دارند.</p></div>
  <main class="auth-main"><form class="login" method="post" action="<?= e(admin_url()) ?>">
    <a class="auth-brand" href="../"><?= book_icon() ?> دفترِ ما</a><span class="eyebrow">پشتِ صفحه‌های این دفتر</span><h1><?= $auth ? 'خوش برگشتی.' : 'کلیدِ این دفتر را بساز.' ?></h1><p class="hint">اینجا خاطره‌ها را کنار هم می‌چینی؛<br>با کلمات خودت، با حوصله.</p>
    <?php flash_html($flash); form_tokens($auth ? 'login' : 'setup'); ?>
    <label class="field"><span class="lbl">نام کاربری</span><input type="text" name="username" autocomplete="username" maxlength="60" dir="ltr" required autofocus placeholder="نام کاربری‌ات را وارد کن"></label>
    <label class="field"><span class="lbl">رمز عبور</span><input type="password" name="password" autocomplete="current-password" maxlength="300" dir="ltr" required placeholder="رمز عبورت را وارد کن"></label>
    <?php if (!$auth): ?><label class="field"><span class="lbl">کلید راه‌اندازی (روی هاست)</span><input name="setup_key" type="password" autocomplete="off" dir="ltr"><small>در اجرای localhost لازم نیست. رمز حساب حداقل ۱۰ کاراکتر باشد.</small></label><?php endif; ?><button class="primary full"><?= $auth ? 'ورود به اتاق تدوین' : 'ساخت حساب مدیریت' ?> <span aria-hidden="true">←</span></button><a class="back-link" href="../">بازگشت به دفتر ↗</a>
  </form><span class="auth-foot">یک دفتر کوچک، برای حرف‌های بزرگ.</span></main>
</body></html><?php exit; endif;

$c = load_content(); $h = $c['hero']; $s2 = $c['section2']; $f = $c['final'];
$settings = store_read('settings');
$responses = store_read('responses', ['items' => []])['items'] ?? [];
$visits = store_read('visits', ['items' => []])['items'] ?? [];
$defaultCredentials = false;
function field_file(string $name, string $accept, ?string $current, string $label, bool $audio = false): void
{
    $safe = $current && is_safe_upload_path($current);
    ?><div class="field"><span class="lbl"><?= e($label) ?></span><div class="filebox" data-filebox>
      <?php if ($safe && !$audio): ?><img class="thumb big" src="../<?= e($current) ?>" alt="پیش‌نمایش تصویر فعلی" data-file-preview><?php endif; ?>
      <?php if ($safe && $audio): ?><audio controls preload="none" src="../<?= e($current) ?>" data-file-preview></audio><?php endif; ?>
      <label class="upload"><input type="file" name="<?= e($name) ?>" accept="<?= e($accept) ?>" data-file-kind="<?= $audio ? 'audio' : 'image' ?>"><span>＋ <?= $safe ? 'تعویض فایل' : 'انتخاب فایل' ?></span></label>
      <span class="file-caption"><?= $audio ? 'صوت تا ۲۵ مگابایت' : 'JPG، PNG یا WebP تا ۱۲ مگابایت' ?></span>
    </div></div><?php
}
function card_row(array $card, string $key, int $number = 1): void
{
    ?><article class="row card-row" data-key="<?= e($key) ?>">
    <header class="row-head"><span class="row-number">خاطرهٔ <b data-card-number><?= $number ?></b></span><div class="mv"><button type="button" data-act="up" aria-label="جابه‌جایی خاطره به بالا">↑</button><button type="button" data-act="down" aria-label="جابه‌جایی خاطره به پایین">↓</button><button type="button" data-act="rm" class="danger" aria-label="حذف خاطره">×</button></div></header>
    <input type="hidden" name="card_key[]" value="<?= e($key) ?>">
    <label class="field page-position"><span class="lbl">شمارهٔ نمایش در دفتر</span><input type="number" min="2" step="1" value="<?= $number + 1 ?>" data-card-position aria-label="شمارهٔ نمایش در دفتر"><small data-book-position>ورق <?= $number + 1 ?> دفتر</small></label>
    <div class="card-heading"><label class="field icon-field"><span class="lbl">نشانه</span><input type="text" name="card_icon[]" value="<?= e($card['icon'] ?? '♡') ?>" maxlength="8" class="ico-in"></label><label class="field grow"><span class="lbl">عنوان خاطره</span><input type="text" name="card_title[]" value="<?= e($card['title'] ?? '') ?>" maxlength="100" placeholder="مثلاً: آن عصرِ بارانی" data-card-title></label></div>
    <label class="field"><span class="lbl">تاریخ یا یادداشت کوتاه <em>اختیاری</em></span><input type="text" name="card_date[]" value="<?= e($card['date'] ?? '') ?>" maxlength="80" placeholder="پاییز ۱۴۰۳ · همان کافهٔ همیشگی"></label>
    <label class="field"><span class="lbl">حرفِ این صفحه</span><textarea name="card_text[]" rows="4" maxlength="1800" placeholder="این خاطره را به زبان خودت بنویس…"><?= e($card['text'] ?? '') ?></textarea></label>
    <?php field_file('card_voice[' . $key . ']', 'audio/*,.mp3,.m4a,.ogg,.wav,.webm', $card['voice'] ?? '', 'صدای تو برای همین خاطره (اختیاری)', true); ?>
    <?php if (!empty($card['voice'])): ?><label class="chk"><input type="checkbox" name="card_voice_rm[]" value="<?= e($key) ?>"> حذف صدای فعلی این خاطره</label><?php endif; ?>
    </article><?php
}
function page_options(array $cards, int $page = 0): void
{
    foreach ($cards as $i => $card): ?><option value="c<?= $i ?>" <?= $page === $i ? 'selected' : '' ?>><?= ($i + 1) . ' · ' . e($card['title'] ?: 'خاطرهٔ بدون عنوان') ?></option><?php endforeach;
}
function delivery_status(array $row): void
{
    $status = $row['telegram_status'] ?? 'disabled';
    $labels = ['sent' => 'به تلگرام رسید', 'failed' => 'ارسال به تلگرام ناموفق', 'pending' => 'در انتظار ارسال', 'disabled' => 'ارسال تلگرام غیرفعال', 'unconfigured' => 'اتصال تلگرام تنظیم نشده'];
    ?><div class="delivery <?= e($status) ?>"><span class="status-dot"></span><?= e($labels[$status] ?? $status) ?><?php if (!empty($row['telegram_error'])): ?><small><?= e($row['telegram_error']) ?></small><?php endif; ?></div><?php
}
layout_head('اتاق تدوین · دفترِ ما');
?>
<body>
<header class="top"><a class="brand" href="../" target="_blank" rel="noopener"><span class="mark"><?= book_icon() ?></span><span>دفترِ ما<small>اتاق تدوین خاطره‌ها</small></span></a><div class="top-actions"><a class="btn preview-button" href="../?preview=1" target="_blank" rel="noopener">مشاهدهٔ دفتر <span aria-hidden="true">↗</span></a><form method="post"><?php form_tokens('logout'); ?><button class="btn quiet">خروج</button></form></div></header>
<div class="layout"><aside class="sidebar"><div class="sidebar-intro"><span class="eyebrow">هر صفحه، کمی نزدیک‌تر</span><h2>با حوصله بساز.</h2><p>جزئیات کوچک،<br>حرف‌های زیادی برای گفتن دارند.</p></div><nav class="tabs" aria-label="بخش‌های مدیریت">
<?php $tabs = ['p1'=>['01','جلد و نامهٔ اول'],'p2'=>['02','صفحه‌های خاطره'],'media'=>['03','عکس‌ها و صدا'],'music'=>['04','آهنگِ دفتر'],'p3'=>['05','آخرین صفحه'],'responses'=>['↳','پاسخ‌ها'],'visits'=>['◉','بازدیدها'],'telegram'=>['↗','اتصال تلگرام'],'security'=>['⚙','حساب کاربری']]; foreach ($tabs as $id => [$number, $label]): ?><a href="<?= e(admin_url()) ?>?tab=<?= $id ?>#<?= $id ?>" data-tab="<?= $id ?>"><span class="nav-number"><?= $number ?></span><span><?= $label ?></span><?php if ($id === 'responses' && $responses): ?><b class="badge"><?= count($responses) ?></b><?php endif; ?></a><?php endforeach; ?>
</nav><div class="sidebar-note"><span>♡</span><p>بهترین جمله‌ها،<br>همان‌هایی‌اند که از خودت می‌آیند.</p></div></aside>
<main class="editor">
<div class="page-topline"><span>مدیریت دفتر</span><span class="live-state"><i></i> ذخیره‌سازی: <?= database() ? 'MySQL' : 'فایل محلی (نمونه)' ?></span></div>
<?php flash_html($flash); ?>
<?php if ($defaultCredentials): ?><div class="notice">با نام کاربری و رمز اولیهٔ <code>admin / admin</code> وارد شده‌ای. <a href="#security">اطلاعات ورودت را تغییر بده ←</a></div><?php endif; ?>
<form method="post" action="<?= e(admin_url()) ?>" enctype="multipart/form-data" id="mainForm">
<?php form_tokens('save'); ?><input type="hidden" name="tab" id="tabField" value="<?= e($activeTab) ?>">
<section class="panel" id="panel-p1" data-form="1" <?= $activeTab === 'p1' ? '' : 'hidden' ?>><div class="panel-heading"><span class="chapter">فصل اول / شروعِ نامه</span><h1>از همین‌جا شروع می‌شود.</h1><p>عکس دفترچه و چند خط صادقانه، پیش از ورق خوردن خاطره‌ها.</p></div>
<div class="grid2"><label class="field"><span class="lbl">نام مخاطب</span><input type="text" name="meta_recipient" value="<?= e($c['meta']['recipient'] ?? '') ?>" maxlength="60" placeholder="مثلاً: طیبا"></label><label class="field"><span class="lbl">عنوان تب مرورگر</span><input type="text" name="meta_title" value="<?= e($c['meta']['title']) ?>" maxlength="80"></label></div>
<?php field_file('notebook_file', 'image/jpeg,image/png,image/webp', $h['notebook'], 'عکس دفترچه‌ای که ساخته‌ای'); ?>
<label class="field"><span class="lbl">یادداشت زیر عکس</span><input type="text" name="hero_caption" value="<?= e($h['notebook_caption']) ?>" maxlength="160"></label>
<hr><label class="field"><span class="lbl">نوشتهٔ کوچک بالای تیتر</span><input type="text" name="hero_eyebrow" value="<?= e($h['eyebrow']) ?>" maxlength="120"></label>
<label class="field"><span class="lbl">تیتر نامه</span><textarea name="hero_headline" rows="2" maxlength="200" class="headline-input"><?= e($h['headline']) ?></textarea></label>
<label class="field"><span class="lbl">متن نامه <em>هر خط، یک پاراگراف</em></span><textarea name="hero_paragraphs" rows="9" maxlength="8000"><?= e(implode("\n", $h['paragraphs'])) ?></textarea></label>
<label class="field"><span class="lbl">متن دکمهٔ باز کردن دفتر</span><input type="text" name="hero_cta" value="<?= e($h['cta']) ?>" maxlength="40"></label><div class="tip"><span>♡</span><p>از چیزی بنویس که فهمیده‌ای و کاری که می‌توانی انجام بدهی. این دفتر وقتی شبیه تو باشد، قشنگ‌تر می‌شود.</p></div></section>
<section class="panel" id="panel-p2" data-form="1" <?= $activeTab === 'p2' ? '' : 'hidden' ?>><div class="panel-heading"><span class="chapter">فصل دوم / میانِ صفحه‌ها</span><h1>هر خاطره، یک ورق.</h1><p>یک طرف حرف‌های تو، طرف دیگر عکس‌ها. ترتیب صفحه‌ها را با فلش‌ها عوض کن.</p></div>
<div class="grid2"><label class="field"><span class="lbl">عنوان فصل خاطره‌ها</span><input type="text" name="s2_title" value="<?= e($s2['title']) ?>" maxlength="160"></label><label class="field"><span class="lbl">زیرعنوان</span><input type="text" name="s2_sub" value="<?= e($s2['subtitle']) ?>" maxlength="240"></label></div>
<label class="field"><span class="lbl">متن رفتن به آخرین صفحه</span><input type="text" name="s2_cta" value="<?= e($s2['cta']) ?>" maxlength="40"></label>
<div class="list" id="cardList"><?php foreach ($c['cards'] as $i => $card) card_row($card, 'c' . $i, $i + 1); ?></div><button type="button" class="btn add" data-act="add-card">＋ یک خاطرهٔ دیگر</button><p class="helper">تعداد خاطره‌ها محدودیت ثابت ندارد. شمارهٔ نمایش را بنویس یا با فلش‌ها جابه‌جا کن؛ عکس و ویس همراه همان خاطره می‌ماند. جلد ورق ۱ است و دعوت همیشه در آخر می‌آید.</p><template id="cardTpl"><?php card_row([], '__KEY__'); ?></template></section>
<section class="panel" id="panel-media" data-form="1" <?= $activeTab === 'media' ? '' : 'hidden' ?>><div class="panel-heading"><span class="chapter">فصل سوم / قاب‌های ماندگار</span><h1>اینجا عکس‌ها جان می‌گیرند.</h1><p>برای هر خاطره چند عکس انتخاب کن؛ مثل عکس‌های چاپ‌شده روی کاغذ.</p></div>
<label class="field"><span class="lbl">عنوان بخش عکس‌ها</span><input type="text" name="gallery_title" value="<?= e($s2['gallery_title']) ?>" maxlength="120"></label>
<div class="list" id="galleryList"><?php foreach ($c['gallery'] as $g): if (!is_safe_upload_path($g['src'] ?? null)) continue; ?>
<article class="row g-row"><input type="hidden" name="g_src[]" value="<?= e($g['src']) ?>"><img class="thumb gallery-thumb" src="../<?= e($g['src']) ?>" alt="<?= e($g['caption'] ?: 'عکس خاطره') ?>" loading="lazy"><div class="grow"><label class="field"><span class="lbl">یادداشت زیر عکس</span><input type="text" name="g_cap[]" value="<?= e($g['caption'] ?? '') ?>" maxlength="120" placeholder="یک جملهٔ کوتاه…"></label><label class="field"><span class="lbl">کنار کدام خاطره؟</span><select name="g_page[]" data-page-select><?php page_options($c['cards'], (int) ($g['page'] ?? 0)); ?></select></label><label class="chk"><input type="checkbox" name="g_rm[]" value="<?= e($g['src']) ?>"> حذف این عکس پس از ذخیره</label></div><div class="mv"><button type="button" data-act="up" aria-label="انتقال عکس به بالا">↑</button><button type="button" data-act="down" aria-label="انتقال عکس به پایین">↓</button></div></article>
<?php endforeach; ?></div>
<label class="drop" id="drop"><input type="file" name="g_new[]" id="gNew" accept="image/jpeg,image/png,image/webp" multiple><span class="drop-symbol">＋</span><strong>چند عکس به دفتر اضافه کن</strong><span>انتخاب یا کشیدن فایل‌ها · JPG، PNG، WebP</span><small>بدون سقف ثابت تعداد عکس؛ هر عکس حداکثر ۱۲ مگابایت</small></label><div class="list new-photos" id="gPreviews" aria-live="polite"></div>
<hr><h2>چند کلمه با صدای تو</h2><p class="section-desc">یک پیام صوتی عمومی برای دفتر؛ صدای اختصاصی هر خاطره در صفحهٔ خودش تنظیم می‌شود.</p>
<label class="field"><span class="lbl">عنوان پیام صوتی</span><input type="text" name="voice_title" value="<?= e($c['voice']['title']) ?>" maxlength="80"></label><?php field_file('voice_file', 'audio/*,.mp3,.m4a,.ogg,.wav,.webm', $c['voice']['src'] ?? '', 'فایل پیام صوتی', true); ?><?php if (!empty($c['voice']['src'])): ?><label class="chk"><input type="checkbox" name="voice_rm" value="1"> حذف پیام صوتی فعلی</label><?php endif; ?>
</section>
<section class="panel" id="panel-music" data-form="1" <?= $activeTab === 'music' ? '' : 'hidden' ?>><div class="panel-heading"><span class="chapter">فصل چهارم / موسیقیِ میانِ کلمات</span><h1>آهنگِ این دفتر چیست؟</h1><p>موسیقی ملایمی که هنگام ورق زدن، همراه خاطره‌ها می‌ماند.</p></div>
<div class="music-illustration" aria-hidden="true"><div class="record"><span>♡</span></div><div><b>یک آهنگ، هزار خاطره</b><span>در تمام صفحه‌ها · با تکرار خودکار</span></div></div>
<label class="field"><span class="lbl">نام آهنگ یا توضیح کوتاه</span><input type="text" name="music_title" value="<?= e($c['music']['title'] ?? 'آهنگِ این دفتر') ?>" maxlength="80" placeholder="مثلاً: آهنگ همیشگی‌مان"></label><?php field_file('music_file', 'audio/*,.mp3,.m4a,.ogg,.wav', $c['music']['src'] ?? '', 'فایل آهنگ پس‌زمینه', true); ?>
<?php if (!empty($c['music']['src'])): ?><label class="chk"><input type="checkbox" name="music_rm" value="1"> حذف آهنگ فعلی</label><?php endif; ?>
<label class="field range-field"><span class="lbl">بلندی صدا <output id="volumeLabel"><?= (int) round(($c['music']['volume'] ?? .35) * 100) ?>٪</output></span><input type="range" name="music_volume" id="musicVolume" min="5" max="100" step="5" value="<?= (int) round(($c['music']['volume'] ?? .35) * 100) ?>"></label>
<div class="tip"><span>♫</span><p>موسیقی با لمس دکمهٔ پخش شروع می‌شود و تا زمان توقف، تکرار خواهد شد. هنگام پخش ویس، آهنگ متوقف می‌شود. حالت سایلنت، صدای رسانه و قوانین مرورگر در اختیار گوشی‌اند و سایت نمی‌تواند آن‌ها را دور بزند.</p></div></section>
<section class="panel" id="panel-p3" data-form="1" <?= $activeTab === 'p3' ? '' : 'hidden' ?>><div class="panel-heading"><span class="chapter">فصل پنجم / آخرین حرف</span><h1>دعوتی آرام، انتخابی آزاد.</h1><p>عکس آخر، درخواست دیدار و دل‌نوشته‌ای که بعد از پاسخ نمایش داده می‌شود.</p></div>
<?php field_file('couple_file', 'image/jpeg,image/png,image/webp', $f['photo'], 'عکس آخر دفتر'); ?>
<div class="grid2"><label class="field"><span class="lbl">مدت نمایش قلب شکسته (ثانیه)</span><input type="number" name="f_seconds" min="1" max="8" value="<?= (int) $f['sticker_seconds'] ?>"></label><label class="field"><span class="lbl">متن زیر قلب</span><input type="text" name="f_sticker_text" value="<?= e($f['sticker_text']) ?>" maxlength="80"></label></div>
<label class="field"><span class="lbl">نوشتهٔ کوچک بالای عنوان</span><input type="text" name="f_eyebrow" value="<?= e($f['eyebrow']) ?>" maxlength="80"></label><label class="field"><span class="lbl">عنوان دعوت</span><textarea name="f_title" rows="2" maxlength="200"><?= e($f['title']) ?></textarea></label><label class="field"><span class="lbl">متن دعوت <em>هر خط، یک پاراگراف</em></span><textarea name="f_paragraphs" rows="7" maxlength="8000"><?= e(implode("\n", $f['paragraphs'])) ?></textarea></label>
<div class="grid2"><label class="field"><span class="lbl">دکمهٔ قبول دعوت</span><input type="text" name="f_yes" value="<?= e($f['yes_label']) ?>" maxlength="60"></label><label class="field"><span class="lbl">دکمهٔ «هنوز آماده نیستم»</span><input type="text" name="f_no" value="<?= e($f['no_label']) ?>" maxlength="60"></label></div>
<hr><h2>دل‌نوشتهٔ بعد از پاسخ</h2><p class="section-desc">دو ثانیه پس از ثبت هر دو انتخاب، پنجره‌ای با پیام خودت باز می‌شود.</p><label class="field"><span class="lbl">عنوان پنجره</span><input type="text" name="f_reply_title" value="<?= e($f['reply_title'] ?? 'چند کلمه برای تو') ?>" maxlength="120"></label><label class="field"><span class="lbl">دل‌نوشته بعد از ارسال فرم</span><textarea name="f_yes_reply" rows="4" maxlength="1600"><?= e($f['yes_reply']) ?></textarea></label><label class="field"><span class="lbl">دل‌نوشته بعد از «هنوز آماده نیستم»</span><textarea name="f_no_reply" rows="4" maxlength="1600"><?= e($f['no_reply']) ?></textarea></label><label class="field"><span class="lbl">امضای نامه</span><input type="text" name="f_sign" value="<?= e($f['signature']) ?>" maxlength="120"></label></section>
<input type="hidden" name="form_complete" value="1"><div class="savebar" id="saveBar" <?= in_array($activeTab, ['p1','p2','media','music','p3'], true) ? '' : 'hidden' ?>><div><span class="save-status" id="saveStatus">با حوصله بنویس؛ وقتی آماده شد، ذخیره کن.</span><span id="dirty" hidden>تغییرات هنوز ذخیره نشده‌اند</span></div><button class="primary" type="submit">ذخیرهٔ دفتر <span aria-hidden="true">✓</span></button></div>
</form>
<section class="panel" id="panel-responses" data-form="0" <?= $activeTab === 'responses' ? '' : 'hidden' ?>><div class="panel-heading"><span class="chapter">نامه‌های رسیده</span><h1>پاسخش اینجاست.</h1><p>هر دو انتخاب ذخیره می‌شوند؛ وضعیت ارسال تلگرام هم کنارشان دیده می‌شود.</p></div>
<?php if (!$responses): ?><div class="empty"><span>✉</span><h3>هنوز نامه‌ای نرسیده.</h3><p>هر وقت پاسخی ثبت شود، همین‌جا می‌بینی.</p></div><?php else: ?><div class="resp-list"><?php foreach ($responses as $r): ?><article class="resp <?= ($r['type'] ?? '') === 'yes' ? 'yes' : 'no' ?>"><header><b><?= ($r['type'] ?? '') === 'yes' ? 'می‌خواهد دفتر را بگیرد' : 'هنوز آماده نیست' ?></b><time datetime="<?= e($r['at'] ?? '') ?>" data-fa-date><?= e($r['at'] ?? '') ?></time></header><?php foreach (['when'=>'زمان', 'where'=>'مکان', 'message'=>'پیام'] as $key=>$label): if (empty($r[$key])) continue; ?><p><span><?= $label ?>:</span> <?= nl2br(e($r[$key])) ?></p><?php endforeach; delivery_status($r); ?></article><?php endforeach; ?></div><form method="post" data-confirm="همهٔ پاسخ‌های ذخیره‌شده پاک شوند؟ این کار قابل برگشت نیست."><?php form_tokens('clear_responses'); ?><button class="btn danger-t">پاک کردن پاسخ‌ها</button></form><?php endif; ?></section>
<section class="panel" id="panel-visits" data-form="0" <?= $activeTab === 'visits' ? '' : 'hidden' ?>><div class="panel-heading"><span class="chapter">ردِ ورق زدن‌ها</span><h1>بازدیدهای دفتر.</h1><p>ورود بازدیدکننده و تلاش ورود مدیر، همراه IP، دستگاه، مرورگر، زمان و وضعیت تلگرام.</p></div><p class="helper">ثبت بازدید مستقل از اتصال تلگرام است. تازه‌سازی‌های یک نشست در بازهٔ ۳۰ دقیقه یک بازدید حساب می‌شوند؛ هویت فرد یا مدل دقیق گوشی از این اطلاعات مشخص نمی‌شود.</p>
<?php if (!$visits): ?><div class="empty"><span>◉</span><h3>هنوز بازدیدی ثبت نشده.</h3><p>تنظیمات اعلان را در بخش اتصال تلگرام پیدا می‌کنی.</p><a class="btn" href="#telegram">تنظیم اتصال ←</a></div><?php else: ?><div class="resp-list"><?php foreach ($visits as $v): ?><article class="resp visit"><header><b><?= e(['visit'=>'بازدید دفتر','login_success'=>'ورود موفق مدیر','login_failed'=>'ورود ناموفق مدیر'][$v['event'] ?? 'visit'] ?? 'بازدید') ?> · <?= e($v['device'] ?? 'دستگاه نامشخص') ?></b><time datetime="<?= e($v['at'] ?? '') ?>" data-fa-date><?= e($v['at'] ?? '') ?></time></header><div class="visit-meta"><span>IP: <bdi><?= e($v['ip'] ?? 'ثبت نشده') ?></bdi></span><span><?= e($v['browser'] ?? '') ?></span><span><?= e($v['os'] ?? '') ?></span></div><?php if (!empty($v['user_agent'])): ?><details><summary>جزئیات مرورگر</summary><p class="agent-string" dir="ltr"><?= e($v['user_agent']) ?></p></details><?php endif; delivery_status($v); ?></article><?php endforeach; ?></div><form method="post" data-confirm="همهٔ بازدیدهای ذخیره‌شده پاک شوند؟"><?php form_tokens('clear_visits'); ?><button class="btn danger-t">پاک کردن بازدیدها</button></form><?php endif; ?></section>
<section class="panel" id="panel-telegram" data-form="0" <?= $activeTab === 'telegram' ? '' : 'hidden' ?>><div class="panel-heading"><span class="chapter">یک راه کوتاه تا تو</span><h1>خبرها در تلگرام.</h1><p>پاسخ‌های صفحهٔ آخر و اعلان بازدید را به ربات خودت وصل کن.</p></div>
<form method="post" class="settings-form"><?php form_tokens('telegram'); ?><label class="field"><span class="lbl">توکن ربات</span><input type="password" name="telegram_token" autocomplete="new-password" dir="ltr" maxlength="180" placeholder="<?= !empty($settings['telegram_token']) ? '•••••••••••• — توکن ذخیره شده' : '123456789:AA…' ?>"><small class="helper"><?= !empty($settings['telegram_token']) ? 'برای حفظ توکن فعلی، این کادر را خالی بگذار.' : 'توکن را از BotFather در تلگرام دریافت کن.' ?></small></label><?php if (!empty($settings['telegram_token'])): ?><label class="chk"><input type="checkbox" name="telegram_token_rm" value="1"> حذف توکن ذخیره‌شده</label><?php endif; ?>
<label class="field"><span class="lbl">شناسهٔ چت مقصد (Chat ID)</span><input type="text" name="telegram_chat_id" value="<?= e($settings['telegram_chat_id'] ?? '') ?>" dir="ltr" maxlength="100" placeholder="123456789 یا ‎-100123456789"><small class="helper">قبل از آزمایش، در چت ربات روی Start بزن یا آن را به گروه مقصد اضافه کن.</small></label>
<label class="switch-row"><span><b>ارسال پاسخ‌ها</b><small>قبول دعوت، متن فرم و «هنوز آماده نیستم»</small></span><input type="checkbox" name="telegram_responses" value="1" <?= !empty($settings['telegram_responses']) ? 'checked' : '' ?>></label>
<label class="switch-row"><span><b>ثبت بازدید در پایگاه داده</b><small>ثبت ورود به دفتر مستقل از اتصال تلگرام؛ ورود مدیر همیشه ثبت می‌شود</small></span><input type="checkbox" name="visit_logging" value="1" <?= ($settings['visit_logging'] ?? true) ? 'checked' : '' ?>></label>
<label class="switch-row"><span><b>اعلان بازدید</b><small>IP، دستگاه، مرورگر و زمان ورود؛ ارسال خودکار برای هر بازدید جدید</small></span><input type="checkbox" name="telegram_visits" value="1" <?= ($settings['telegram_visits'] ?? true) ? 'checked' : '' ?>></label>
<p class="helper">ثبت اطلاعات در صفحهٔ عمومی اطلاع داده می‌شود. نام، موقعیت مکانی و شمارهٔ تلفن دریافت نمی‌شود. برای ارسال واقعی، توکن و شناسهٔ چت را ذخیره کن. IP پشت پراکسی ممکن است متعلق به پراکسی باشد.</p><button class="primary">ذخیرهٔ تنظیمات تلگرام</button></form>
<div class="test-connection"><div><b>اتصال را امتحان کن</b><p>این دکمه یک پیام واقعی با تنظیمات ذخیره‌شده می‌فرستد.</p></div><form method="post"><?php form_tokens('test_telegram'); ?><button class="btn">ارسال پیام آزمایشی ↗</button></form></div></section>
<section class="panel" id="panel-security" data-form="0" <?= $activeTab === 'security' ? '' : 'hidden' ?>><div class="panel-heading"><span class="chapter">کلیدِ این دفتر</span><h1>حساب کاربری تو.</h1><p>نام کاربری و رمز را همین‌جا تغییر بده.</p></div><form method="post" class="credentials-form"><?php form_tokens('credentials'); ?><label class="field"><span class="lbl">نام کاربری</span><input type="text" name="username" value="<?= e($auth['username'] ?? 'admin') ?>" dir="ltr" autocomplete="username" minlength="3" maxlength="60" pattern="[a-zA-Z0-9_.\-]{3,60}" required><small class="helper">حروف انگلیسی، عدد، نقطه، خط تیره یا زیرخط</small></label><label class="field"><span class="lbl">رمز فعلی</span><input type="password" name="cur" autocomplete="current-password" dir="ltr" maxlength="300" required></label><div class="grid2"><label class="field"><span class="lbl">رمز جدید</span><input type="password" name="new" autocomplete="new-password" dir="ltr" minlength="8" maxlength="300"></label><label class="field"><span class="lbl">تکرار رمز جدید</span><input type="password" name="new2" autocomplete="new-password" dir="ltr" minlength="8" maxlength="300"></label></div><p class="helper">برای تغییر فقط نام کاربری، کادرهای رمز جدید را خالی بگذار. رمز جدید دست‌کم ۸ کاراکتر باشد.</p><button class="primary">به‌روزرسانی اطلاعات ورود</button></form><div class="tip"><span>♡</span><p>این دفتر برای موتورهای جست‌وجو برچسب noindex دارد؛ این برچسب دسترسی را محدود نمی‌کند. هر کسی که لینک را داشته باشد می‌تواند آن را باز کند.</p></div></section>
<footer class="editor-footer">با حوصله، با توجه، برای یک نفر. <span>♡</span></footer>
</main></div><div class="toast" id="toast" role="status" hidden></div>
<noscript><p class="notice">منوها با بازکردن صفحه کار می‌کنند؛ برای افزودن و جابه‌جایی خاطره‌ها جاوااسکریپت را فعال کن.</p></noscript><script id="admin-app"><?php readfile(__DIR__ . '/admin.js'); ?></script></body></html>
