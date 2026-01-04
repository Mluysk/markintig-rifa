<?php
$cfg = require __DIR__ . "/config.php";

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if(!is_array($data)) $data = [];

// Ajuste conforme seu gateway:
$txid = trim((string)($data["txid"] ?? ""));
$status = (string)($data["status"] ?? "");
$secret = (string)($_SERVER["HTTP_X_WEBHOOK_SECRET"] ?? "");

if (!hash_equals($cfg["webhook_secret"], $secret)) {
  http_response_code(401);
  exit("unauthorized");
}

$paidStatuses = ["PAID","CONFIRMED","RECEIVED","COMPLETED","paid","confirmed"];
if ($txid === "" || !in_array($status, $paidStatuses, true)) {
  http_response_code(200);
  exit("ignored");
}

$payload = json_encode(["secret"=>$cfg["webhook_secret"], "txid"=>$txid], JSON_UNESCAPED_UNICODE);
$url = "https://" . $_SERVER["HTTP_HOST"] . rtrim(dirname($_SERVER["REQUEST_URI"]), "/\\") . "/api.php?action=paid_by_txid";

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
  CURLOPT_POSTFIELDS => $payload,
]);
curl_exec($ch);
curl_close($ch);

http_response_code(200);
echo "ok";
