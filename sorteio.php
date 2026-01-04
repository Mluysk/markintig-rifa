<?php
$cfg = require __DIR__ . "/config.php";
$drawPassword = (string)($cfg["sorteio_senha"] ?? "9899");
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Sorteio • <?= htmlspecialchars($cfg["site_nome"]) ?></title>
  <link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
  <div class="wrap">
    <div class="titlebar">
      <div class="titleblock">
        <h1>Sorteio</h1>
        <p class="sub">Clique em sortear para gerar o número vencedor.</p>
      </div>
      <div class="title-actions">
        <div class="action-buttons">
          <a class="btn btn-outline" href="index.php">Voltar</a>
        </div>
      </div>
    </div>

    <div class="card draw-card">
      <div id="drawStatus" class="draw-status locked">Sorteio bloqueado</div>
      <div class="draw-unlock">
        <input id="drawPassword" type="password" placeholder="Senha do sorteio">
        <button id="unlockBtn" class="btn-outline" type="button">Liberar sorteio</button>
      </div>
      <button id="drawBtn" class="btn draw-btn" type="button">Sortear</button>
      <div id="countdown" class="draw-countdown" hidden></div>
      <div id="result" class="draw-result" hidden>
        <div class="draw-number" id="winnerNumber"></div>
        <div class="draw-info" id="winnerInfo"></div>
      </div>
      <div class="prize draw-prize" id="winnerPrize" hidden>
        <div class="prize-content">
          <img src="img/premio.png" alt="Prêmio">
          <div class="prize-text">
            <div class="prize-title">Prêmio</div>
            <div class="prize-desc"><?= htmlspecialchars($cfg["premio_descricao"]) ?></div>
            <div class="prize-value"><?= htmlspecialchars($cfg["premio_valor"]) ?></div>
            <button class="btn btn-outline btn-small" id="openPrizeModal" type="button">Ver foto ampliada</button>
          </div>
        </div>
      </div>
      <button id="clearWinnerBtn" class="btn btn-outline" type="button" hidden>Limpar ganhador</button>
    </div>
  </div>

  <div class="modal" id="prizeModal" hidden>
    <div class="modal-backdrop" data-close="true"></div>
    <div class="modal-content">
      <button class="modal-close" type="button" data-close="true">×</button>
      <img src="img/premio.png" alt="Prêmio ampliado">
    </div>
  </div>

<script>
const drawBtn = document.getElementById("drawBtn");
const countdownEl = document.getElementById("countdown");
const resultEl = document.getElementById("result");
const winnerNumberEl = document.getElementById("winnerNumber");
const winnerInfoEl = document.getElementById("winnerInfo");
const drawStatusEl = document.getElementById("drawStatus");
const clearWinnerBtn = document.getElementById("clearWinnerBtn");
const drawPasswordEl = document.getElementById("drawPassword");
const unlockBtn = document.getElementById("unlockBtn");
const winnerPrizeEl = document.getElementById("winnerPrize");
const effects = ["effect-1","effect-2","effect-3","effect-4","effect-5"];
let raffleOpen = false;
let raffleReset = false;
let hasWinner = false;
let passwordOk = false;
let confettiRunning = false;
let confettiShown = false;
const drawTime = <?= json_encode($cfg["sorteio_hora"] ?? "19:00") ?>;

document.getElementById("openPrizeModal").addEventListener("click", () => {
  document.getElementById("prizeModal").hidden = false;
});
document.getElementById("prizeModal").addEventListener("click", (e) => {
  if(e.target && e.target.dataset && e.target.dataset.close){
    document.getElementById("prizeModal").hidden = true;
  }
});

function renderWinner(winner){
  hasWinner = !!winner;
  winnerPrizeEl.hidden = !winner;
  if(!winner){
    confettiShown = false;
    resultEl.hidden = true;
    updateDrawButton();
    return;
  }
  confettiShown = true;
  resultEl.hidden = false;
  winnerNumberEl.textContent = `#${String(winner.num).padStart(4,"0")}`;
  winnerInfoEl.innerHTML = `
    <div class="winner-name">🏆 ${winner.name}</div>
    <div>CPF: ${winner.cpf}</div>
    ${winner.whatsapp ? `<div>WhatsApp: ${winner.whatsapp}</div>` : ""}
  `;
  updateDrawButton();
}

async function loadWinner(){
  try{
    const r = await fetch("api.php?action=winner", { cache:"no-store" });
    const j = await r.json();
    const list = j.winner && j.winner.winners ? j.winner.winners : [];
    renderWinner(list[0]);
  }catch(e){}
}
async function loadStatus(){
  try{
    const r = await fetch("api.php?action=status", { cache:"no-store" });
    const j = await r.json();
    const list = j.data || [];
    const remaining = list.filter(item => item.status === "free").length;
    const total = list.length;
    raffleOpen = remaining === 0 && total > 0;
    raffleReset = remaining === total && total > 0;
    updateDrawButton();
    drawStatusEl.className = `draw-status ${raffleOpen ? "open" : "locked"}`;
    if(raffleOpen){
      const drawDate = new Date();
      drawDate.setDate(drawDate.getDate() + 1);
      const dateLabel = drawDate.toLocaleDateString("pt-BR");
      drawStatusEl.textContent = `Sorteio liberado • Data do sorteio: ${dateLabel} ${drawTime}`;
    }else{
      drawStatusEl.textContent = `Sorteio bloqueado • Restam ${remaining} de ${total}`;
    }
    updateClearButton();
  }catch(e){}
}

function updateClearButton(){
  clearWinnerBtn.hidden = !(hasWinner && raffleReset);
}
function updateDrawButton(){
  drawBtn.disabled = !(raffleOpen && passwordOk && !hasWinner);
}

function launchConfetti(target){
  if(confettiRunning || confettiShown){
    return;
  }
  confettiRunning = true;
  confettiShown = true;
  const container = document.createElement("div");
  container.className = "confetti";
  for(let i=0;i<28;i+=1){
    const piece = document.createElement("span");
    piece.className = "confetti-piece";
    piece.style.left = `${Math.random() * 100}%`;
    piece.style.background = `hsl(${Math.random() * 360}, 90%, 60%)`;
    piece.style.animationDelay = `${Math.random() * 0.2}s`;
    container.appendChild(piece);
  }
  target.appendChild(container);
  setTimeout(() => {
    confettiRunning = false;
    container.remove();
  }, 1400);
}

drawBtn.addEventListener("click", async () => {
  if(!raffleOpen || hasWinner){
    return;
  }
  drawBtn.disabled = true;
  resultEl.hidden = true;
  winnerPrizeEl.hidden = true;
  let remaining = 5;
  countdownEl.hidden = false;
  const tick = async () => {
    const idx = Math.max(0, remaining - 1);
    countdownEl.className = `draw-countdown ${effects[idx]}`;
    countdownEl.textContent = String(remaining);
    if(remaining <= 0){
      countdownEl.hidden = true;
      try{
        const r = await fetch("api.php?action=draw_winner", {
          method: "POST",
          headers: { "Content-Type":"application/json" },
          body: JSON.stringify({})
        });
        const j = await r.json();
        const list = j.winner && j.winner.winners ? j.winner.winners : [];
        if(list.length){
          confettiShown = false;
          renderWinner(list[0]);
          launchConfetti(resultEl);
        }
      }catch(e){}
      drawBtn.disabled = false;
      return;
    }
    remaining -= 1;
    setTimeout(tick, 1000);
  };
  tick();
});

clearWinnerBtn.addEventListener("click", async () => {
  const pwd = (drawPasswordEl.value || "").trim();
  if(pwd !== <?= json_encode($drawPassword) ?>){
    alert("Senha inválida.");
    return;
  }
  try{
    const r = await fetch("api.php?action=clear_winner", {
      method: "POST",
      headers: { "Content-Type":"application/json" },
      body: JSON.stringify({})
    });
    const j = await r.json();
    if(j.ok){
      renderWinner(null);
      updateClearButton();
    }
  }catch(e){}
});

unlockBtn.addEventListener("click", () => {
  const pwd = (drawPasswordEl.value || "").trim();
  passwordOk = pwd === <?= json_encode($drawPassword) ?>;
  if(!passwordOk){
    alert("Senha inválida.");
  }
  updateDrawButton();
});

loadWinner().finally(loadStatus);
</script>
</body>
</html>
