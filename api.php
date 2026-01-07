<?php
header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . "/config.php";
$dbFile = __DIR__ . "/data/db.json";
$winnerFile = __DIR__ . "/data/winner.json";

if (!is_dir(__DIR__ . "/data")) @mkdir(__DIR__ . "/data", 0775, true);
if (!file_exists($dbFile)) @file_put_contents($dbFile, "[]");
if (!file_exists($winnerFile)) @file_put_contents($winnerFile, "{}");

function onlyDigits(string $s): string { return preg_replace('/\D+/', '', $s); }

function isValidCPF(string $cpf): bool {
  $cpf = onlyDigits($cpf);
  if (strlen($cpf) !== 11) return false;
  if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;

  $sum=0;
  for($i=0;$i<9;$i++) $sum += (int)$cpf[$i] * (10-$i);
  $d1 = 11 - ($sum % 11); if($d1>=10) $d1=0;
  if($d1 !== (int)$cpf[9]) return false;

  $sum=0;
  for($i=0;$i<10;$i++) $sum += (int)$cpf[$i] * (11-$i);
  $d2 = 11 - ($sum % 11); if($d2>=10) $d2=0;
  if($d2 !== (int)$cpf[10]) return false;

  return true;
}

function maskCPF_last4(string $cpf11): string {
  $cpf11 = onlyDigits($cpf11);
  if (strlen($cpf11) !== 11) return "***********";
  return "***********" . substr($cpf11, -4);
}

function db_read_lock($dbFile){
  $fp = fopen($dbFile, "c+");
  if(!$fp) return [null, []];
  flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  $arr = json_decode($raw ?: "[]", true);
  if(!is_array($arr)) $arr = [];
  return [$fp, $arr];
}
function db_write_unlock($fp, $arr){
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
}

function winner_read($winnerFile){
  $raw = @file_get_contents($winnerFile);
  $data = json_decode($raw ?: "{}", true);
  return is_array($data) ? $data : [];
}
function winner_write($winnerFile, $data){
  @file_put_contents($winnerFile, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

function maybe_clear_winner($cfg, $winnerFile){
  $data = winner_read($winnerFile);
  if (empty($data) || empty($data["drawn_at"])) {
    return $data;
  }
  $days = (int)($cfg["limpar_ganhador_auto_dias"] ?? 0);
  if ($days <= 0) {
    return $data;
  }
  $cutoff = time() - ($days * 86400);
  if ((int)$data["drawn_at"] <= $cutoff) {
    winner_write($winnerFile, []);
    return [];
  }
  return $data;
}

function ensure_initialized(&$arr, $cfg){
  $min = (int)$cfg["min_num"];
  $max = (int)$cfg["max_num"];
  $map = [];
  foreach($arr as $item){
    if(!isset($item["num"])) continue;
    $num = (int)$item["num"];
    if($num < $min || $num > $max) continue;
    $map[$num] = $item;
  }

  $arr = [];
  for($i=$min; $i<=$max; $i++){
    if(isset($map[$i])){
      $arr[] = $map[$i];
      continue;
    }
    $arr[] = [
      "num"=>$i,
      "status"=>"free",     // free | reserved | paid
      "name"=>"",
      "whatsapp"=>"",
      "cpf_mask"=>"",
      "cpf_hash"=>"",
      "txid"=>"",
      "created_at"=>0,
      "paid_at"=>0
    ];
  }
}

function cleanup_reservations(&$arr, $cfg){
  $ttl = (int)$cfg["reserva_minutos"] * 60;
  $now = time();
  foreach($arr as &$r){
    if($r["status"] === "reserved" && $r["created_at"] > 0 && ($now - (int)$r["created_at"]) > $ttl){
      $r["status"]="free";
      $r["name"]="";
      $r["whatsapp"]="";
      $r["cpf_mask"]="";
      $r["cpf_hash"]="";
      $r["txid"]="";
      $r["created_at"]=0;
    }
  }
}

function pick_weighted_index(array $items, array $weightsByNum): int {
  $total = 0.0;
  $weights = [];
  foreach ($items as $idx => $item) {
    $num = (int)($item["num"] ?? 0);
    $weight = $weightsByNum[$num] ?? 1.0;
    $weight = max(0.0, (float)$weight);
    $weights[$idx] = $weight;
    $total += $weight;
  }
  if ($total <= 0 || empty($weights)) {
    return (int)array_key_first($items);
  }
  $rand = (mt_rand() / mt_getrandmax()) * $total;
  $acc = 0.0;
  foreach ($weights as $idx => $weight) {
    $acc += $weight;
    if ($rand <= $acc) {
      return (int)$idx;
    }
  }
  return (int)array_key_last($weights);
}

$action = $_GET["action"] ?? "";

/* ===== GET ===== */
if ($action === "status") {
  [$fp, $arr] = db_read_lock($dbFile);
  ensure_initialized($arr, $cfg);
  cleanup_reservations($arr, $cfg);
  db_write_unlock($fp, $arr);

  $out = array_map(fn($r)=>["num"=>$r["num"],"status"=>$r["status"]], $arr);
  echo json_encode(["ok"=>true,"data"=>$out], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === "buyers") {
  [$fp, $arr] = db_read_lock($dbFile);
  ensure_initialized($arr, $cfg);
  cleanup_reservations($arr, $cfg);
  db_write_unlock($fp, $arr);

  $buyers = [];
  foreach($arr as $r){
    if($r["status"] === "reserved" || $r["status"] === "paid"){
      $buyers[] = [
        "num"=>$r["num"],
        "name"=>$r["name"],
        "whatsapp"=>$r["whatsapp"] ?? "",
        "cpf"=>$r["cpf_mask"],
        "status"=>$r["status"],
        "created_at"=>$r["created_at"],
        "paid_at"=>$r["paid_at"]
      ];
    }
  }
  echo json_encode(["ok"=>true,"buyers"=>$buyers], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === "check_paid") {
  $txid = trim((string)($_GET["txid"] ?? ""));
  if ($txid === "") { echo json_encode(["ok"=>false]); exit; }

  [$fp, $arr] = db_read_lock($dbFile);
  ensure_initialized($arr, $cfg);
  cleanup_reservations($arr, $cfg);
  db_write_unlock($fp, $arr);

  $paid = false;
  foreach($arr as $r){
    if(($r["txid"] ?? "") === $txid){
      $paid = ($r["status"] === "paid");
      break;
    }
  }
  echo json_encode(["ok"=>true,"paid"=>$paid], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === "winner") {
  $winner = maybe_clear_winner($cfg, $winnerFile);
  echo json_encode(["ok"=>true,"winner"=>$winner], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===== POST ===== */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok"=>false,"error"=>"Método inválido"], JSON_UNESCAPED_UNICODE);
  exit;
}
$body = json_decode(file_get_contents("php://input"), true);
if(!is_array($body)) $body = [];

if ($action === "reserve_bulk") {
  $nums = $body["nums"] ?? [];
  $name = trim((string)($body["name"] ?? ""));
  $cpf_raw = (string)($body["cpf"] ?? "");
  $cpf = onlyDigits($cpf_raw);
  $whatsapp = onlyDigits((string)($body["whatsapp"] ?? ""));

  if (!is_array($nums)) { $nums = []; }
  $nums = array_values(array_unique(array_map("intval", $nums)));

  if (count($nums) === 0) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"Selecione ao menos um número"], JSON_UNESCAPED_UNICODE); exit;
  }
  foreach($nums as $num){
    if ($num < $cfg["min_num"] || $num > $cfg["max_num"]) {
      http_response_code(422);
      echo json_encode(["ok"=>false,"error"=>"Número inválido"], JSON_UNESCAPED_UNICODE); exit;
    }
  }
  if ($name === "" || mb_strlen($name) < 2) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"Nome inválido"], JSON_UNESCAPED_UNICODE); exit;
  }
  if (!isValidCPF($cpf)) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"CPF inválido"], JSON_UNESCAPED_UNICODE); exit;
  }
  if ($whatsapp === "" || strlen($whatsapp) < 10) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"WhatsApp inválido"], JSON_UNESCAPED_UNICODE); exit;
  }

  [$fp, $arr] = db_read_lock($dbFile);
  ensure_initialized($arr, $cfg);
  cleanup_reservations($arr, $cfg);

  $idxs = [];
  foreach($nums as $num){
    $idx = null;
    foreach($arr as $k=>$r){ if((int)$r["num"] === $num){ $idx=$k; break; } }
    if($idx === null){
      db_write_unlock($fp, $arr);
      http_response_code(500);
      echo json_encode(["ok"=>false,"error"=>"Base inconsistente"], JSON_UNESCAPED_UNICODE); exit;
    }
    if($arr[$idx]["status"] === "paid"){
      db_write_unlock($fp, $arr);
      http_response_code(409);
      echo json_encode(["ok"=>false,"error"=>"Número já pago"], JSON_UNESCAPED_UNICODE); exit;
    }
    if($arr[$idx]["status"] === "reserved"){
      db_write_unlock($fp, $arr);
      http_response_code(409);
      echo json_encode(["ok"=>false,"error"=>"Número já reservado"], JSON_UNESCAPED_UNICODE); exit;
    }
    $idxs[] = $idx;
  }

  $txid = $cfg["txid_prefix"] . substr((string)time(), -6) . substr((string)mt_rand(1000, 9999), -4);
  $txid = substr(preg_replace('/[^A-Za-z0-9]/', '', $txid), 0, 25);

  foreach($idxs as $idx){
    $arr[$idx]["status"] = "reserved";
    $arr[$idx]["name"] = mb_substr($name, 0, 60);
    $arr[$idx]["whatsapp"] = $whatsapp;
    $arr[$idx]["cpf_mask"] = maskCPF_last4($cpf);
    $arr[$idx]["cpf_hash"] = hash("sha256", $cpf);
    $arr[$idx]["txid"] = $txid;
    $arr[$idx]["created_at"] = time();
  }

  db_write_unlock($fp, $arr);
  echo json_encode(["ok"=>true,"txid"=>$txid,"nums"=>$nums], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === "reserve") {
  $num = (int)($body["num"] ?? -1);
  $name = trim((string)($body["name"] ?? ""));
  $cpf_raw = (string)($body["cpf"] ?? "");
  $cpf = onlyDigits($cpf_raw);
  $whatsapp = onlyDigits((string)($body["whatsapp"] ?? ""));

  if ($num < $cfg["min_num"] || $num > $cfg["max_num"]) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"Número inválido"], JSON_UNESCAPED_UNICODE); exit;
  }
  if ($name === "" || mb_strlen($name) < 2) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"Nome inválido"], JSON_UNESCAPED_UNICODE); exit;
  }
  if (!isValidCPF($cpf)) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"CPF inválido"], JSON_UNESCAPED_UNICODE); exit;
  }
  if ($whatsapp === "" || strlen($whatsapp) < 10) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"WhatsApp inválido"], JSON_UNESCAPED_UNICODE); exit;
  }

  [$fp, $arr] = db_read_lock($dbFile);
  ensure_initialized($arr, $cfg);
  cleanup_reservations($arr, $cfg);

  $idx = null;
  foreach($arr as $k=>$r){ if((int)$r["num"] === $num){ $idx=$k; break; } }
  if($idx === null){
    db_write_unlock($fp, $arr);
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"Base inconsistente"], JSON_UNESCAPED_UNICODE); exit;
  }

  if($arr[$idx]["status"] === "paid"){
    db_write_unlock($fp, $arr);
    http_response_code(409);
    echo json_encode(["ok"=>false,"error"=>"Número já pago"], JSON_UNESCAPED_UNICODE); exit;
  }
  if($arr[$idx]["status"] === "reserved"){
    db_write_unlock($fp, $arr);
    http_response_code(409);
    echo json_encode(["ok"=>false,"error"=>"Número já reservado"], JSON_UNESCAPED_UNICODE); exit;
  }

  $txid = $cfg["txid_prefix"] . str_pad((string)$num, 4, "0", STR_PAD_LEFT) . substr((string)time(), -6);
  $txid = substr(preg_replace('/[^A-Za-z0-9]/', '', $txid), 0, 25);

  $arr[$idx]["status"] = "reserved";
  $arr[$idx]["name"] = mb_substr($name, 0, 60);
  $arr[$idx]["whatsapp"] = $whatsapp;
  $arr[$idx]["cpf_mask"] = maskCPF_last4($cpf);
  $arr[$idx]["cpf_hash"] = hash("sha256", $cpf);
  $arr[$idx]["txid"] = $txid;
  $arr[$idx]["created_at"] = time();

  db_write_unlock($fp, $arr);
  echo json_encode(["ok"=>true,"txid"=>$txid], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === "mark_paid") {
  $pin = (string)($body["pin"] ?? "");
  $num = (int)($body["num"] ?? -1);

  if ($pin !== $cfg["admin_pin"]) {
    http_response_code(403);
    echo json_encode(["ok"=>false,"error"=>"PIN inválido"], JSON_UNESCAPED_UNICODE); exit;
  }

  [$fp, $arr] = db_read_lock($dbFile);
  ensure_initialized($arr, $cfg);

  foreach($arr as &$r){
    if((int)$r["num"] === $num){
      $r["status"] = "paid";
      $r["paid_at"] = time();
      break;
    }
  }
  db_write_unlock($fp, $arr);
  echo json_encode(["ok"=>true], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === "confirm_paid") {
  $txid = trim((string)($body["txid"] ?? ""));
  if ($txid === "") {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"TXID inválido"], JSON_UNESCAPED_UNICODE); exit;
  }

  [$fp, $arr] = db_read_lock($dbFile);
  ensure_initialized($arr, $cfg);

  $found = false;
  foreach($arr as &$r){
    if(($r["txid"] ?? "") === $txid){
      $r["status"] = "paid";
      $r["paid_at"] = time();
      $found = true;
    }
  }
  db_write_unlock($fp, $arr);

  echo json_encode(["ok"=>true,"found"=>$found], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === "paid_by_txid") {
  $secret = (string)($body["secret"] ?? "");
  $txid = trim((string)($body["txid"] ?? ""));

  if (!hash_equals($cfg["webhook_secret"], $secret)) {
    http_response_code(403);
    echo json_encode(["ok"=>false,"error"=>"Secret inválido"], JSON_UNESCAPED_UNICODE); exit;
  }
  if ($txid === "") {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"TXID inválido"], JSON_UNESCAPED_UNICODE); exit;
  }

  [$fp, $arr] = db_read_lock($dbFile);
  ensure_initialized($arr, $cfg);

  $found = false;
  foreach($arr as &$r){
    if(($r["txid"] ?? "") === $txid){
      $r["status"] = "paid";
      $r["paid_at"] = time();
      $found = true;
    }
  }
  db_write_unlock($fp, $arr);

  echo json_encode(["ok"=>true,"found"=>$found], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === "clear_winner") {
  if (empty($cfg["limpar_ganhador_ativo"])) {
    http_response_code(403);
    echo json_encode(["ok"=>false,"error"=>"Limpeza desativada"], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $body = json_decode(file_get_contents("php://input"), true) ?: [];
  $pwd = trim((string)($body["password"] ?? ""));
  $expected = (string)($cfg["limpar_ganhador_senha"] ?? "");
  if ($expected === "" || !hash_equals($expected, $pwd)) {
    http_response_code(403);
    echo json_encode(["ok"=>false,"error"=>"Senha inválida"], JSON_UNESCAPED_UNICODE);
    exit;
  }
  winner_write($winnerFile, []);
  echo json_encode(["ok"=>true], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === "draw_winner") {
  [$fp, $arr] = db_read_lock($dbFile);
  ensure_initialized($arr, $cfg);
  cleanup_reservations($arr, $cfg);
  db_write_unlock($fp, $arr);

  $eligible = array_values(array_filter($arr, fn($r)=>$r["status"] === "paid"));
  if (count($eligible) === 0) {
    $eligible = array_values(array_filter($arr, fn($r)=>$r["status"] === "reserved"));
  }
  if (count($eligible) === 0) {
    http_response_code(422);
    echo json_encode(["ok"=>false,"error"=>"Nenhum número elegível"], JSON_UNESCAPED_UNICODE); exit;
  }

  $qty = max(1, (int)($cfg["sorteio_quantidade"] ?? 1));
  $weightsByNum = [];
  if (!empty($cfg["numero_da_sorte_ativo"]) && !empty($cfg["numero_da_sorte"]) && is_array($cfg["numero_da_sorte"])) {
    foreach ($cfg["numero_da_sorte"] as $num => $percent) {
      if (!is_numeric($num) || !is_numeric($percent)) {
        continue;
      }
      $weightsByNum[(int)$num] = max(0.0, (float)$percent);
    }
  }

  $remaining = array_values($eligible);
  $winners = [];
  $limit = min($qty, count($remaining));
  for ($i = 0; $i < $limit; $i++) {
    if (empty($remaining)) {
      break;
    }
    if (!empty($weightsByNum)) {
      $idx = pick_weighted_index($remaining, $weightsByNum);
    } else {
      $idx = array_rand($remaining);
    }
    $winners[] = $remaining[$idx];
    array_splice($remaining, (int)$idx, 1);
  }
  $out = [
    "drawn_at"=>time(),
    "winners"=>array_map(fn($r)=>[
      "num"=>$r["num"],
      "name"=>$r["name"],
      "whatsapp"=>$r["whatsapp"] ?? "",
      "cpf"=>$r["cpf_mask"],
      "status"=>$r["status"]
    ], $winners)
  ];
  winner_write($winnerFile, $out);

  echo json_encode(["ok"=>true,"winner"=>$out], JSON_UNESCAPED_UNICODE);
  exit;
}

http_response_code(400);
echo json_encode(["ok"=>false,"error"=>"Ação inválida"], JSON_UNESCAPED_UNICODE);
