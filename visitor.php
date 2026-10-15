<?php
declare(strict_types=1);
// Standard first-party access log. Forwarded IP headers are deliberately not trusted.
function request_device(): array {
    $ua=mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,400);
    $tablet=preg_match('/iPad|Tablet|Android(?!.*Mobile)/i',$ua);
    $device=$tablet?'تبلت':(preg_match('/Mobile|iPhone|Android/i',$ua)?'موبایل':'رایانه / سایر');
    $browser=preg_match('/Edg(?:e|A|iOS)?\//i',$ua)?'Edge':(preg_match('/OPR\//i',$ua)?'Opera':(preg_match('/Firefox|FxiOS/i',$ua)?'Firefox':(preg_match('/Chrome|CriOS/i',$ua)?'Chrome':(preg_match('/Safari/i',$ua)?'Safari':'نامشخص'))));
    $os=preg_match('/Android/i',$ua)?'Android':(preg_match('/iPhone|iPad/i',$ua)?'iOS':(preg_match('/Windows/i',$ua)?'Windows':(preg_match('/Macintosh|Mac OS X/i',$ua)?'macOS':(preg_match('/Linux/i',$ua)?'Linux':'نامشخص'))));
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    return ['ip'=>filter_var($ip,FILTER_VALIDATE_IP)?$ip:'نامشخص','device'=>$device,'browser'=>$browser,'os'=>$os,'user_agent'=>$ua];
}
function record_entry(string $event='visit'): ?array {
    try {
        $s=store_read('settings');
        if ($event==='visit' && !($s['visit_logging']??true)) return null;
        $details=request_device();
        $now=time();
        // Refreshes are one visit for 30 minutes. Authentication attempts remain separate.
        $previous=$_SESSION['last_visit']??[];
        if ($event==='visit' && ($previous['ip']??'')===$details['ip'] && $now-(int)($previous['at']??0)<1800) return null;
        $enabled=(bool)($s['telegram_visits']??true);
        $configured=!empty($s['telegram_token'])&&!empty($s['telegram_chat_id']);
        $row=$details+['id'=>bin2hex(random_bytes(16)),'at'=>date('c'),'event'=>$event,'telegram_status'=>!$enabled?'disabled':($configured?'pending':'unconfigured')];
        store_update('visits',static function($data)use($row){$rows=$data['items']??[];array_unshift($rows,$row);return ['items'=>array_slice($rows,0,1000)];});
        if($event==='visit')$_SESSION['last_visit']=['ip'=>$details['ip'],'at'=>$now];
        if($enabled&&$configured) {
            $title=['visit'=>'بازدید از دفتر','login_success'=>'ورود موفق مدیر','login_failed'=>'تلاش ناموفق ورود مدیر'][$event]??'ورود به سایت';
            $message=$title."\nIP: ".$row['ip']."\nدستگاه: ".$row['device']."\nسیستم‌عامل: ".$row['os']."\nمرورگر: ".$row['browser']."\nزمان: ".$row['at'];
            register_shutdown_function(static function()use($row,$message){
                if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
                if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
                try{notify_record('visits',$row['id'],$message,true);}catch(Throwable $e){error_log('Visit notification status update failed');}
            });
        }
        return $row;
    }catch(Throwable $e){error_log('Visit storage failed');return null;}
}
