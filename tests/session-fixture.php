<?php
// Test-only helper; no HTTP route. Modify only a synthetic account's server session.
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$id=$argv[1]??'';
if(!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$id))throw new RuntimeException('Invalid test session');
$env=getenv('APP_ENV')?:'dev';
session_save_path(dirname(__DIR__).'/var/sessions/'.$env);session_id($id);session_start();
if(!preg_match('/^chat-(candidate|employer|stranger)-[a-f0-9-]+@example\.invalid$/D',$_SESSION['_sf2_attributes']['auth_email']??''))throw new RuntimeException('Not a disposable test session');
$tokens=&$_SESSION['_sf2_attributes']['auth_tokens'];
$parts=explode('.',$tokens['accessToken']);$claims=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true);$claims['exp']=time()-3600;
$parts[1]=rtrim(strtr(base64_encode(json_encode($claims)),'+/','-_'),'=');$tokens['accessToken']=implode('.',$parts);
session_write_close();echo "Synthetic access token expired\n";
