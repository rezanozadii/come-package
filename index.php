<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_app_session();
$c = load_content();
$h = $c['hero']; $f = $c['final'];
$csrf = public_csrf_token();
$submission = public_submission_token();
$settings = store_read('settings', []);
require_once __DIR__ . '/visitor.php';
record_entry();
$music = $c['music'] ?? ['src' => '', 'title' => 'آهنگ ما', 'volume' => .35];
$recipient = $c['meta']['recipient'] ?? 'طیبا';
$asset = static function (string $p): string {
    return e($p) . (is_file(__DIR__ . '/' . $p) ? '?v=' . filemtime(__DIR__ . '/' . $p) : '');
};
$validMedia = static fn ($p): bool => is_string($p) && is_safe_upload_path($p);
$gallery = array_values(array_filter($c['gallery'], static fn ($g) => $validMedia($g['src'] ?? '')));
$cards = $c['cards'];
$spreads = count($cards) + 2;
$fa = static fn ($n): string => strtr((string) $n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$demo = str_contains($h['notebook'], 'sample-') || str_contains($h['notebook'], 'assets/demo/');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function bookPhoto(string $src, string $caption, string $asset, string $class = ''): void { ?>
    <figure class="print <?= e($class) ?>">
      <span class="tape" aria-hidden="true"></span>
      <button type="button" class="photo-open" data-photo="<?= e($src) ?>" data-caption="<?= e($caption) ?>" aria-label="بزرگ‌نمایی: <?= e($caption ?: 'عکس خاطره') ?>">
        <img src="<?= $asset ?>" alt="<?= e($caption ?: 'عکس خاطره') ?>" loading="eager">
        <span class="zoom-hint" aria-hidden="true">↗</span>
      </button>
      <figcaption><?= e($caption) ?></figcaption>
    </figure>
<?php }
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#eee8df">
<title><?= e($c['meta']['title']) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="preload" href="assets/fonts/Vazirmatn-Variable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $asset('assets/style.css') ?>">
<noscript><style>.closed-book-stage{display:none!important}#bookInterior[hidden]{display:block!important}.spread[hidden]{display:grid!important}.book{height:auto!important}.spread{position:relative!important}.book-controls{display:none!important}</style></noscript>
</head>
<body>
<svg class="symbols" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><symbol id="i-heart" viewBox="0 0 24 24"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></symbol><symbol id="i-sound" viewBox="0 0 24 24"><path d="M11 5 6 9H3v6h3l5 4V5Zm4 3a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14"/></symbol><symbol id="i-book" viewBox="0 0 24 24"><path d="M12 5v16M3 3c4-1 6 0 9 2 3-2 5-3 9-2v15c-4-1-6 0-9 2-3-2-5-3-9-2V3Z"/></symbol><symbol id="i-arrow" viewBox="0 0 24 24"><path d="m14 6-6 6 6 6M8 12h12"/></symbol></svg>
<div class="ambient ambient-one" aria-hidden="true"></div><div class="ambient ambient-two" aria-hidden="true"></div>
<header class="site-header">
  <a class="wordmark" href="./" aria-label="شروع دفترچه"><span class="brand-icon"><svg><use href="#i-book"/></svg></span><span>برای تو<span class="wordmark-dot">.</span><small>یک دفترچه، از تمامِ دلم</small></span></a>
  <span class="header-note">بعضی حرف‌ها، روی کاغذ قشنگ‌ترند.</span>
  <button type="button" id="musicBtn" class="music-button" aria-label="پخش موسیقی" aria-pressed="false" <?= $validMedia($music['src']) ? '' : 'disabled' ?>><span class="music-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span id="musicLabel"><?= $validMedia($music['src']) ? e($music['title']) : 'در سکوت بخوان' ?></span></button>
</header>
<main id="main">
  <div class="introduction"><span class="little-rule"></span><p>برای <?= e($recipient) ?>، با تمامِ حرف‌های نگفته</p><span class="little-rule"></span></div>
  <section class="closed-book-stage" id="closedBook" aria-label="جلد دفترچه">
    <button type="button" class="closed-book" id="openBook" aria-label="باز کردن دفترچه برای <?= e($recipient) ?>" aria-controls="bookInterior" aria-expanded="false" aria-describedby="coverHint">
      <span class="closed-book-pages" aria-hidden="true"></span>
      <span class="closed-book-front">
        <span class="cover-border" aria-hidden="true"></span>
        <span class="cover-kicker">یک دفترچه، از تمامِ دلم</span>
        <span class="cover-title">برای <?= e($recipient) ?></span>
        <span class="cover-ornament" aria-hidden="true">— ♡ —</span>
        <?php if ($validMedia($h['notebook'])): ?>
        <span class="cover-keepsake"><span class="tape" aria-hidden="true"></span><img src="<?= $asset($h['notebook']) ?>" alt="عکس دفترچه‌ای که برایت ساختم"><span><?= e($h['notebook_caption']) ?></span></span>
        <?php else: ?><span class="cover-empty-heart" aria-hidden="true"><svg><use href="#i-heart"/></svg></span><?php endif; ?>
        <span class="cover-dedication">حرف‌هایی که با حوصله،<br>میان این صفحه‌ها گذاشته‌ام.</span>
        <span class="cover-open-label">باز کردن دفترچه <svg aria-hidden="true"><use href="#i-arrow"/></svg></span>
      </span>
    </button>
    <p class="cover-hint" id="coverHint">روی جلد بزن؛ از اولین صفحه شروع کنیم.</p>
  </section>
  <div id="bookInterior" hidden>
  <div class="book-area">
    <span class="outside-note" aria-hidden="true">این چند صفحه، فقط برای توست…</span>
    <div class="ribbon" aria-hidden="true"></div>
    <div class="book" id="book" aria-roledescription="دفترچهٔ خاطرات" aria-label="دفترچهٔ خاطرات" data-count="<?= $spreads ?>">
      <section class="spread active" data-spread="0" data-name="قبل از ورق زدن" aria-label="قبل از ورق زدن">
        <div class="paper paper-right letter-page">
          <div class="page-top"><span>یک نامه برای تو</span><svg><use href="#i-heart"/></svg></div>
          <div class="letter-body opening-letter">
            <span class="eyebrow"><?= e($h['eyebrow']) ?></span>
            <h1><?= e($h['headline']) ?></h1>
            <div class="body-copy"><?php foreach ($h['paragraphs'] as $p): ?><p><?= e($p) ?></p><?php endforeach; ?></div>
            <button class="text-button" type="button" data-next><?= e($h['cta']) ?><svg><use href="#i-arrow"/></svg></button>
          </div>
          <div class="page-bottom"><span>از اینجا شروع کنیم</span><span>۰۱</span></div>
        </div>
        <div class="paper paper-left photo-page cover-photo-page">
          <div class="page-top"><span>چیزی که با دست‌هایم ساختم</span><span class="tiny-flower">✳</span></div>
          <div class="photo-composition cover-composition">
            <div class="handwritten photo-note">برای تو؛ آرام، ورق به ورق.</div>
            <?php if ($validMedia($h['notebook'])) bookPhoto($h['notebook'], $h['notebook_caption'], $asset($h['notebook']), 'cover-print'); ?>
            <span class="paper-stamp" aria-hidden="true"><svg><use href="#i-heart"/></svg><span>با عشق<br>ساخته شده</span></span>
            <span class="doodle-arrow" aria-hidden="true">⤹</span>
            <div class="caption-note">این دفترچه منتظر تو مانده.</div>
          </div>
          <div class="page-bottom"><span><?= $demo ? 'تصویر نمونه · جای عکس دفترچهٔ تو' : 'یک تکه از دلم، میان این صفحه‌ها' ?></span><span>۰۲</span></div>
        </div>
      </section>
      <?php foreach ($cards as $i => $card):
          $photos = array_values(array_filter($gallery, static fn($g, $gi) => isset($g['page']) && (int)$g['page'] >= 0 ? (int)$g['page'] === $i : $gi % max(1,count($cards)) === $i, ARRAY_FILTER_USE_BOTH));
          $voice = $card['voice'] ?? '';
          if (!$voice && $i === 0) $voice = $c['voice']['src'] ?? '';
      ?>
      <section class="spread" data-spread="<?= $i+1 ?>" data-name="<?= e($card['title']) ?>" aria-label="<?= e($card['title']) ?>" hidden inert>
        <div class="paper paper-right letter-page memory-letter">
          <div class="page-top"><span><?= e($c['section2']['title']) ?></span><svg><use href="#i-heart"/></svg></div>
          <div class="letter-body">
            <span class="eyebrow">خاطرهٔ <?= $fa($i+1) ?> <span class="eyebrow-separator">/</span> <?= e($card['date'] ?? 'مانده در دلم') ?></span>
            <span class="memory-mark" aria-hidden="true"><?= e($card['icon'] ?? '♡') ?></span>
            <h2><?= e($card['title']) ?></h2>
            <div class="body-copy memory-copy"><?php foreach (preg_split('/\R/u', $card['text'] ?? '') as $p): if(trim($p)): ?><p><?= e($p) ?></p><?php endif; endforeach; ?></div>
            <div class="small-divider" aria-hidden="true">— ♡ —</div>
            <?php if ($validMedia($voice)): ?>
            <div class="voice-player"><span>این بار، با صدای من</span><audio class="voice-audio" controls preload="metadata" src="<?= $asset($voice) ?>" aria-label="<?= e($c['voice']['title']) ?>"></audio></div>
            <?php else: ?><p class="handwritten memory-footnote"><?= e($c['section2']['subtitle']) ?></p><?php endif; ?>
          </div>
          <div class="page-bottom"><span>حرف‌هایی که باید می‌گفتم</span><span><?= $fa(($i+1)*2+1) ?></span></div>
        </div>
        <div class="paper paper-left photo-page">
          <div class="page-top"><span><?= e($c['section2']['gallery_title']) ?></span><span class="tiny-flower">✳</span></div>
          <div class="photo-composition memory-photos count-<?= min(count($photos),3) ?>">
            <?php foreach($photos as $j=>$g) bookPhoto($g['src'], $g['caption'] ?? '', $asset($g['src']), 'memory-print print-'.($j%3)); ?>
            <?php if (!$photos): ?><div class="empty-photo"><svg><use href="#i-heart"/></svg><p>بعضی خاطره‌ها،<br>بدون عکس هم می‌مانند.</p></div><?php endif; ?>
          </div>
          <div class="page-bottom"><span>برای دیدن جزئیات، روی عکس بزن</span><span><?= $fa(($i+1)*2+2) ?></span></div>
        </div>
      </section>
      <?php endforeach; ?>
      <section class="spread final-spread" data-spread="<?= $spreads-1 ?>" data-name="یک حرف آخر" aria-label="یک حرف آخر" hidden inert>
        <div class="paper paper-right letter-page">
          <div class="page-top"><span>آخر دفتر؛ ادامه‌اش با انتخاب تو</span><svg><use href="#i-heart"/></svg></div>
          <div class="letter-body final-letter">
            <span class="eyebrow"><?= e($f['eyebrow']) ?></span>
            <h2><?= e($f['title']) ?></h2>
            <div class="body-copy"><?php foreach ($f['paragraphs'] as $p): ?><p><?= e($p) ?></p><?php endforeach; ?></div>
            <p class="privacy-note">پاسخت برای صاحب دفتر ذخیره می‌شود<?= !empty($settings['telegram_responses']) ? ' و به تلگرام او ارسال می‌شود' : '' ?>.</p><div class="choices" id="choices"><button type="button" class="button button-primary" id="yesBtn"><?= e($f['yes_label']) ?><svg><use href="#i-arrow"/></svg></button><button type="button" class="button button-outline" id="noBtn"><?= e($f['no_label']) ?></button></div>
            <form id="yesForm" class="response-form" hidden>
              <div class="form-heading"><span>هر وقت و هر جا که راحتی</span><button type="button" id="cancelForm" aria-label="بستن فرم">×</button></div>
              <div class="form-row"><label>چه زمانی؟<input name="when" maxlength="120" placeholder="مثلاً جمعه، عصر" required></label><label>کجا؟<input name="where" maxlength="160" placeholder="جایی که راحتی"></label></div>
              <label>اگر حرفی مانده…<textarea name="message" maxlength="600" rows="2" placeholder="می‌تونی فقط برای گرفتن دفترچه بیای."></textarea></label>
              <input name="website" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
              <button class="button button-primary" type="submit">ارسال حرفم <svg><use href="#i-arrow"/></svg></button>
            </form>
            <p class="response-status" id="responseStatus" role="status" aria-live="polite"></p>
            <p class="signature"><?= e($f['signature']) ?></p>
          </div>
          <div class="page-bottom"><span>ممنون که تا اینجا همراه شدی</span><span><?= $fa($spreads*2-1) ?></span></div>
        </div>
        <div class="paper paper-left photo-page final-photo-page">
          <div class="page-top"><span>یک قاب، هزار حرف</span><span class="tiny-flower">✳</span></div>
          <div class="photo-composition final-composition">
            <?php if ($validMedia($f['photo'])) bookPhoto($f['photo'], 'بعضی آدم‌ها، همیشه یک جای مخصوص دارند.', $asset($f['photo']), 'couple-print'); ?>
            <div class="handwritten final-photo-note">هنوز، با مهر.</div>
            <svg class="drawn-heart"><use href="#i-heart"/></svg>
          </div>
          <div class="page-bottom"><span>این صفحه، جای عکس ماست</span><span><?= $fa($spreads*2) ?></span></div>
        </div>
      </section>
    </div>
  </div>
  <nav class="book-controls" aria-label="ورق زدن دفترچه">
    <button type="button" class="nav-button" id="prevPage" disabled><svg class="flipped"><use href="#i-arrow"/></svg><span>صفحهٔ قبل</span></button>
    <div class="chapter-progress"><div class="chapter-dots" aria-label="فهرست صفحه‌ها"><?php for($i=0;$i<$spreads;$i++): ?><button type="button" data-goto="<?= $i ?>" aria-label="<?= $i===0?'قبل از ورق زدن':($i===$spreads-1?'یک حرف آخر':e($cards[$i-1]['title'])) ?>" <?= $i===0?'aria-current="step"':'' ?>></button><?php endfor; ?></div><span id="pageIndicator" aria-live="polite">۱ از <?= $fa($spreads) ?> <span>· قبل از ورق زدن</span></span></div>
    <button type="button" class="nav-button" id="nextPage"><span>ورق بزن</span><svg><use href="#i-arrow"/></svg></button>
  </nav>
  <p class="reading-hint"><span aria-hidden="true">↔</span> با دکمه‌ها یا کشیدن صفحه، دفترچه را ورق بزن</p>
  </div>
  <noscript><p class="notice">برای ورق زدن، پخش آهنگ و ارسال پاسخ، جاوااسکریپت مرورگر را روشن کن.</p></noscript>
</main>
<footer class="site-footer"><span>با حوصله ساخته شده؛ برای کسی که مهم است.</span><svg><use href="#i-heart"/></svg><span>هر صفحه، یک قدم نزدیک‌تر به حرف دلم</span></footer>
<?php if ($settings['visit_logging'] ?? true): ?><p class="privacy-note">برای صاحب دفتر، زمان ورود، IP و اطلاعات فنی دستگاه و مرورگر ثبت می‌شود<?= ($settings['telegram_visits'] ?? true) ? ' و در صورت اتصال ربات، به تلگرام او ارسال می‌شود' : '' ?>.</p><?php endif; ?>
<?php if ($validMedia($music['src'])): ?><audio id="bgMusic" src="<?= $asset($music['src']) ?>" preload="metadata" loop data-volume="<?= (float)($music['volume']??.35) ?>"></audio><?php endif; ?>
<dialog id="welcomeDialog" class="dialog welcome-dialog" aria-labelledby="welcomeTitle" aria-describedby="welcomeDescription">
  <button type="button" class="dialog-close" data-close aria-label="بستن راهنمای صدا">×</button>
  <div class="welcome-sound" aria-hidden="true"><svg><use href="#i-sound"/></svg><span class="sound-ring"></span><span class="sound-ring ring-two"></span></div>
  <span class="eyebrow">قبل از اولین ورق…</span>
  <h2 id="welcomeTitle">این دفتر را، با صدا بخوان.</h2>
  <p id="welcomeDescription">برای شنیدن آهنگ، صدای رسانهٔ گوشی‌ات را زیاد کن و گوشی را از حالت سایلنت خارج کن.</p>
  <?php if ($settings['visit_logging'] ?? true): ?><p class="welcome-privacy">زمان ورود، IP و نوع دستگاه برای صاحب دفتر ثبت می‌شود<?= ($settings['telegram_visits'] ?? true) ? ' و در صورت اتصال ربات به تلگرام او ارسال می‌شود' : '' ?>.</p><?php endif; ?>
  <p class="welcome-whisper">چند دقیقه آرامش؛ برای حرف‌هایی که با حوصله نوشته‌ام.</p>
  <div class="welcome-actions">
    <button type="button" class="button button-primary" id="startBook"><?= $validMedia($music['src']) ? 'شروع با موسیقی' : 'بریم سراغ دفتر' ?><svg><use href="#i-arrow"/></svg></button>
    <button type="button" class="welcome-quiet" data-close>ترجیح می‌دهم در سکوت بخوانم</button>
  </div>
</dialog>
<dialog id="heartDialog" class="dialog heart-dialog" aria-label="یک لحظه دلتنگی" data-seconds="<?= max(1,min(8,(int)($f['sticker_seconds']??3))) ?>">
  <button type="button" class="dialog-close" data-close aria-label="بستن">×</button>
  <svg class="broken-heart" viewBox="0 0 180 170" aria-hidden="true"><g class="heart-half"><path d="M90 40C65 4 15 15 18 59c2 34 43 66 71 88l-8-30 17-23-22-21 16-18Z" fill="#9b4052"/><path d="m42 63 19-6M46 77v5" stroke="#4d2930" stroke-width="4" stroke-linecap="round"/><path class="tear" d="M49 86c-14 19-5 25 1 25s15-7-1-25Z" fill="#aac9d0"/></g><g class="heart-half right-half"><path d="M95 39c25-35 74-24 71 20-2 34-43 66-71 88l-8-30 17-23-22-21 16-18Z" fill="#b25565"/><path d="m138 63-19-6m15 20v-5" stroke="#4d2930" stroke-width="4" stroke-linecap="round"/><path class="tear" d="M130 86c-14 19-5 25 1 25s15-7-1-25Z" fill="#aac9d0"/></g></svg>
  <p class="handwritten"><?= e($f['sticker_text']) ?></p><div class="sticker-timer"><i></i></div>
</dialog>
<dialog id="replyDialog" class="dialog reply-dialog" aria-labelledby="replyTitle"><button type="button" class="dialog-close" data-close aria-label="بستن">×</button><div class="reply-envelope" aria-hidden="true">♡</div><span class="eyebrow">یک دل‌نوشته، برای تو</span><h2 id="replyTitle"><?= e($f['reply_title']??'ممنون که حرف دلم را خواندی') ?></h2><p id="replyText"></p><span class="signature"><?= e($f['signature']) ?></span><button type="button" class="button button-outline" data-close>برگشت به دفترچه</button></dialog>
<dialog id="lightbox" class="dialog lightbox" aria-label="نمایش عکس"><button type="button" class="dialog-close" data-close aria-label="بستن عکس">×</button><figure><img src="" alt=""><figcaption></figcaption></figure></dialog>
<script id="bookConfig" type="application/json"><?= json_encode(['csrf'=>$csrf,'submissionId'=>$submission,'trackVisits'=>!empty($settings['telegram_visits']),'finalNext'=>$c['section2']['cta']], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= $asset('assets/app.js') ?>"></script>
</body></html>
