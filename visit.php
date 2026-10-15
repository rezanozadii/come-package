<?php
declare(strict_types=1);
require __DIR__.'/lib.php';require __DIR__.'/visitor.php';start_app_session();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$in=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($in)||!is_string($in['csrf']??null)||!hash_equals(public_csrf_token(),$in['csrf'])){http_response_code(403);exit;}
record_entry();echo '{"ok":true}';
