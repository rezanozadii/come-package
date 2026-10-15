<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
start_app_session();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function answer(array $data, int $code=200): never {http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')answer(['ok'=>false],405);
$in=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($in)||!is_string($in['csrf']??null)||!hash_equals(public_csrf_token(),$in['csrf']))answer(['ok'=>false,'error'=>'صفحه را تازه کن و دوباره تلاش کن.'],403);
if(!is_string($in['submissionId']??null)||!hash_equals(public_submission_token(),$in['submissionId']))answer(['ok'=>false],403);
if(!empty($in['website']))answer(['ok'=>false],400);
$type=$in['type']??'';if(!in_array($type,['yes','no'],true))answer(['ok'=>false],422);
$clip=static fn($key,$len)=>mb_substr(trim(is_string($in[$key]??null)?$in[$key]:''),0,$len);
if($type==='yes'&&$clip('when',120)==='')answer(['ok'=>false,'error'=>'زمان پیشنهادی را بنویس.'],422);
try {
    $settings=store_read('settings');$enabled=!empty($settings['telegram_responses']);$id=public_submission_token();$created=false;
    $store=store_update('responses',static function($data) use($id,$type,$clip,$enabled,&$created){
        $rows=$data['items']??[];
        foreach($rows as $r)if($r['id']===$id)return $data;
        if(time()-(int)($_SESSION['last_response']??0)<5)throw new RuntimeException('rate');
        $created=true;
        array_unshift($rows,['id'=>$id,'at'=>date('c'),'type'=>$type,'when'=>$type==='yes'?$clip('when',120):'','where'=>$type==='yes'?$clip('where',160):'','message'=>$type==='yes'?$clip('message',600):'','telegram_status'=>$enabled?'pending':'disabled']);
        return ['items'=>array_slice($rows,0,1000)];
    });
    $record=current(array_filter($store['items'],static fn($r)=>$r['id']===$id));
    if($created){$_SESSION['last_response']=time();notify_record('responses',$id,"پاسخ دفتر خاطرات\n".($type==='yes'?"دیدار دوستانه: بله\nزمان: ".$record['when']."\nمکان: ".$record['where']."\nپیام: ".$record['message']:'تمایلی به دیدار ندارد.')."\n".$record['at'],$enabled);}
    answer(['ok'=>true,'reply'=>load_content()['final'][$record['type']==='yes'?'yes_reply':'no_reply']]);
} catch(Throwable $e){answer(['ok'=>false,'error'=>'ثبت پاسخ انجام نشد؛ چند لحظه بعد دوباره تلاش کن.'],503);}
