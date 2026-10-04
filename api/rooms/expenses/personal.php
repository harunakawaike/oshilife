<?php
/** personal.php の役割：本人用の反映内容を取得し、CSRF付きの明示操作だけで個人支出へ反映する。 */
declare(strict_types=1);
require_once __DIR__.'/../../../app/helpers/schedule_api.php';
require_once PROJECT_ROOT.'/app/services/room_personal_money_service.php';
$method=$_SERVER['REQUEST_METHOD'];
if (!in_array($method,['GET','POST'],true)) apiError('利用できないメソッドです。',405);
$userId=startScheduleApi($method);$raw=$method==='GET'?$_GET:readJson();
foreach(array_keys($raw) as $key) if (!in_array($key,['room_id','id','action','confirmation_token'],true)) apiError('指定できない項目です。',422);
$roomId=scheduleId($raw['room_id']??null);$id=scheduleId($raw['id']??null);
if ($method==='GET') apiSuccess(roomPersonalPreview(database(),$userId,$roomId,$id));
$action=$raw['action']??null;
if (!is_string($action) || !in_array($action,['create','update','delete'],true)) apiError('操作を確認してください。',422);
apiSuccess(['expense_id'=>reflectRoomPersonal($userId,$roomId,$id,$action,$raw['confirmation_token']??null)],$action==='create'?201:200);
