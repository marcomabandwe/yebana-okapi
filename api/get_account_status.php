<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../Config/database.php';
$id=$_GET['user_id']??''; if(!$id){http_response_code(400);echo json_encode(['status'=>'error']);exit;}
try{$pdo=(new Database())->getConnection();$st=$pdo->prepare('SELECT statut_compte FROM users WHERE id = ? LIMIT 1');$st->execute([$id]);$value=$st->fetchColumn();if($value===false){http_response_code(404);echo json_encode(['status'=>'error']);}else{echo json_encode(['status'=>'success','statut_compte'=>$value]);}}
catch(Throwable $e){http_response_code(500);echo json_encode(['status'=>'error']);}
