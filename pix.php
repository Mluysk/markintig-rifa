<?php
$cfg = require __DIR__ . "/config.php";

$txid = trim((string)($_GET["txid"] ?? ""));
$numsParam = trim((string)($_GET["nums"] ?? ""));
$nums = [];
if ($numsParam !== "") {
  $parts = array_filter(array_map("trim", explode(",", $numsParam)), fn($v)=>$v !== "");
  foreach($parts as $p){ $nums[] = (int)$p; }
} else {
  $nums[] = (int)($_GET["num"] ?? -1);
}
$nums = array_values(array_filter($nums, fn($n)=>$n >= $cfg["min_num"] && $n <= $cfg["max_num"]));

if (count($nums) === 0 || $txid === "") {
  http_response_code(400);
  echo "Dados inválidos.";
  exit;
}

function emv($id, $value){
  $len = str_pad((string)strlen($value), 2, "0", STR_PAD_LEFT);
  return $id . $len . $value;
}
function crc16($payload) {
  $payload .= "6304";
  $poly = 0x1021;
  $crc = 0xFFFF;
  for ($i=0; $i<strlen($payload); $i++){
    $crc ^= (ord($payload[$i]) << 8);
    for ($j=0; $j<8; $j++){
      $crc = ($crc & 0x8000) ? (($crc << 1) ^ $poly) & 0xFFFF : ($crc << 1) & 0xFFFF;
    }
  }
  return strtoupper(str_pad(dechex($crc), 4, "0", STR_PAD_LEFT));
}

$qtd = count($nums);
$valorTotal = $cfg["preco_centavos"] * $qtd;
$valor = number_format($valorTotal/100, 2, ".", "");
$valorFormatado = number_format($valorTotal / 100, 2, ",", ".");

$pixKey = preg_replace('/\s+/', '', trim((string)$cfg["pix_chave"]));
$pixBase = $cfg["pix_desc"] ?: "Sorteio";
if ($qtd === 1) {
  $pixDescRaw = $pixBase . " #" . $nums[0];
} else {
  $pixDescRaw = $pixBase . " " . $qtd . " numeros";
}
$pixDescRaw = preg_replace('/[^A-Za-z0-9# ]+/', ' ', $pixDescRaw);
$pixDescRaw = preg_replace('/\s+/', ' ', trim($pixDescRaw));
$pixDesc = mb_substr($pixDescRaw, 0, 35);

$merchantAccount =
  emv("00", "BR.GOV.BCB.PIX") .
  emv("01", $pixKey) .
  emv("02", $pixDesc);

$additional = emv("05", mb_substr($txid, 0, 25));

$payload =
  emv("00","01") .
  emv("01","11") .
  emv("26", $merchantAccount) .
  emv("52","0000") .
  emv("53","986") .
  emv("54",$valor) .
  emv("58","BR") .
  emv("59", mb_substr($cfg["pix_nome"], 0, 25)) .
  emv("60", mb_substr($cfg["pix_cidade"], 0, 15)) .
  emv("62", $additional);

$payloadFinal = $payload . "6304" . crc16($payload);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Pix • <?= htmlspecialchars($cfg["site_nome"]) ?></title>
  <link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
  <div class="wrap">
    <h1>Pagamento Pix • <?= htmlspecialchars($cfg["site_nome"]) ?></h1>
    <?php
      $numsDisplay = array_slice($nums, 0, 5);
      $numsLabel = implode(", ", array_map(fn($n)=>"#" . str_pad((string)$n, 4, "0", STR_PAD_LEFT), $numsDisplay));
      if ($qtd > 5) { $numsLabel .= "…"; }
    ?>
    <p class="sub">
      <?= $qtd === 1 ? "Número" : "Números" ?>
      <b><?= htmlspecialchars($numsLabel) ?></b>
      • Valor <b>R$ <?= $valorFormatado ?></b>
    </p>

    <div class="card">
      <div class="card-title">Informações da compra</div>
      <div class="row2">
        <div class="qr-section">
          <div class="label">QR Code</div>
          <div id="qrcode" class="qrbox"></div>
          <div class="small">Abra o app do banco → Pix → Ler QR</div>
        </div>

        <div class="copy">
          <div class="label">Pix Copia e Cola</div>
          <textarea id="payload" readonly><?= htmlspecialchars($payloadFinal) ?></textarea>
          <button id="copyBtn" class="btn-outline">Copiar</button>

          <div id="statusBox" class="status waiting">Aguardando pagamento...</div>
          <div class="small">Assim que o pagamento confirmar, irar aparecer o botão de confirmar o pagamento aguarde para confirmar.</div>
          <div id="confirmTimer" class="small">Você poderá confirmar em 30s.</div>
          <button id="confirmBtn" class="btn-outline confirm-btn" style="display:none;" type="button">Confirmar pagamento</button>
          <div class="small"><b>⚠️ Não saia desta tela</b> até confirmar o pagamento no botão acima para registrar seus números.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- QRCode via CDN -->
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
  <script>
    const payload = document.getElementById("payload").value.trim();
    const qrEl = document.getElementById("qrcode");
    qrEl.innerHTML = "";
    new QRCode(qrEl, { text: payload, width: 260, height: 260, correctLevel: QRCode.CorrectLevel.M });

    document.getElementById("copyBtn").onclick = async () => {
      try { await navigator.clipboard.writeText(payload); alert("Copiado!"); }
      catch(e){ document.getElementById("payload").select(); document.execCommand("copy"); alert("Copiado!"); }
    };

    async function checkPaid(){
      try{
        const txid = "<?= htmlspecialchars($txid) ?>";
        const r = await fetch("api.php?action=check_paid&txid=" + encodeURIComponent(txid), { cache:"no-store" });
        const j = await r.json();
        if(j.ok && j.paid === true){
          const box = document.getElementById("statusBox");
          box.className = "status ok";
          box.innerHTML = "✅ Pagamento confirmado! Obrigado por participar e boa sorte 🍀";
          setTimeout(()=> location.href="index.php", 5000);
          return;
        }
      }catch(e){}
      setTimeout(checkPaid, 2500);
    }
    checkPaid();

    let remaining = 30;
    const timerEl = document.getElementById("confirmTimer");
    const confirmBtn = document.getElementById("confirmBtn");

    const tick = () => {
      remaining -= 1;
      if(remaining <= 0){
        timerEl.textContent = "Se já pagou, confirme abaixo.";
        confirmBtn.style.display = "inline-flex";
        return;
      }
      timerEl.textContent = `Você poderá confirmar em ${remaining}s.`;
      setTimeout(tick, 1000);
    };
    setTimeout(tick, 1000);

    const finalizePaid = () => {
      const box = document.getElementById("statusBox");
      box.className = "status ok";
      box.innerHTML = "✅ Pagamento confirmado! Obrigado por participar e boa sorte 🍀";
      setTimeout(()=> location.href="index.php", 5000);
    };

    confirmBtn.addEventListener("click", async () => {
      confirmBtn.disabled = true;
      confirmBtn.textContent = "Verificando...";
      confirmBtn.classList.add("is-verifying");
      await new Promise(resolve => setTimeout(resolve, 3000));
      try{
        const txid = "<?= htmlspecialchars($txid) ?>";
        const r = await fetch("api.php?action=confirm_paid", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ txid })
        });
        const j = await r.json();
        if(j.ok){
          confirmBtn.textContent = "Concluído";
          confirmBtn.classList.remove("is-verifying");
          confirmBtn.classList.add("is-done");
          launchConfetti(confirmBtn);
          finalizePaid();
          return;
        }
      }catch(e){}
      confirmBtn.disabled = false;
      confirmBtn.classList.remove("is-verifying");
      confirmBtn.textContent = "Confirmar pagamento";
    });

    function launchConfetti(anchor){
      const container = document.createElement("div");
      container.className = "confetti";
      for(let i=0;i<24;i+=1){
        const piece = document.createElement("span");
        piece.className = "confetti-piece";
        piece.style.left = `${Math.random() * 100}%`;
        piece.style.background = `hsl(${Math.random() * 360}, 90%, 60%)`;
        piece.style.animationDelay = `${Math.random() * 0.2}s`;
        container.appendChild(piece);
      }
      anchor.appendChild(container);
      setTimeout(() => container.remove(), 1400);
    }
  </script>
</body>
</html>
