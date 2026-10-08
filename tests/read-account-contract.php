<?php
// Test helper: use the browser's existing server session, without another login
// or disclosing its access token to test output.
declare(strict_types=1);
$id=$argv[1]??'';
if(!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$id))throw new RuntimeException('Invalid session ID');
$path=dirname(__DIR__).'/var/sessions/prod';
if(!is_file($path.'/sess_'.$id))throw new RuntimeException('Browser session missing');
ini_set('session.save_path',$path);
session_id($id);
session_start(['read_and_close'=>true,'use_cookies'=>false,'use_strict_mode'=>true]);
$token=$_SESSION['_sf2_attributes']['auth_tokens']['accessToken']??null;
if(!is_string($token))throw new RuntimeException('Browser is not authenticated');
$context=stream_context_create(['http'=>['header'=>'Authorization: Bearer '.$token,'timeout'=>10]]);
$result=file_get_contents('http://nginx/auth/user/me',false,$context);
if($result===false)throw new RuntimeException('Account API failed');
echo $result;
