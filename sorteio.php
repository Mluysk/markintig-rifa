<?php
$cfg = require __DIR__ . "/config.php";
$drawPassword = (string)($cfg["sorteio_senha"] ?? "9899");
$clearWinnerEnabled = !empty($cfg["limpar_ganhador_ativo"]);
$clearWinnerPassword = (string)($cfg["limpar_ganhador_senha"] ?? "");
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
      <?php if ($clearWinnerEnabled) : ?>
      <button id="clearWinnerBtn" class="btn btn-outline" type="button" hidden>Limpar ganhador</button>
      <?php endif; ?>
    </div>

    <div class="fireworks fireworks-left" id="drawFireworksLeft" hidden></div>
    <div class="fireworks fireworks-right" id="drawFireworksRight" hidden></div>
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
let confettiInterval = null;
let lastWinnerNumber = null;
let loadingWinner = false;
const drawTime = <?= json_encode($cfg["sorteio_hora"] ?? "19:00") ?>;
const clearWinnerEnabled = <?= json_encode($clearWinnerEnabled) ?>;
const clearWinnerPasswordEnabled = <?= json_encode($clearWinnerPassword !== "") ?>;
const confettiDurationMs = Math.max(400, Number(<?= json_encode($cfg["confete_duracao_ms"] ?? 1100) ?>) || 1100);
const confettiIntervalMs = Math.max(300, Number(<?= json_encode($cfg["confete_intervalo_ms"] ?? ($cfg["confete_duracao_ms"] ?? 1100)) ?>) || confettiDurationMs);
const fireworksIntensity = Math.max(1, Number(<?= json_encode($cfg["fogos_intensidade"] ?? 2) ?>) || 1);
const fireworksIntervalMs = Math.max(300, Number(<?= json_encode($cfg["fogos_intervalo_ms"] ?? 1200) ?>) || 1200);
let fireworksInterval = null;

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
    lastWinnerNumber = null;
    resultEl.hidden = true;
    stopConfetti();
    stopFireworks();
    updateDrawButton();
    return;
  }
  lastWinnerNumber = winner.num;
  resultEl.hidden = false;
  winnerNumberEl.textContent = `#${String(winner.num).padStart(4,"0")}`;
  winnerInfoEl.innerHTML = `
    <div class="winner-message">PARABÊNS PELA CONQUISTA.!!!</div>
    <div class="winner-name">🏆 ${winner.name}</div>
    <div>CPF: ${winner.cpf}</div>
    ${winner.whatsapp ? `<div>WhatsApp: ${winner.whatsapp}</div>` : ""}
  `;
  startFireworks();
  startConfetti(resultEl);
  updateDrawButton();
}

function createExplosion(container, x, y, hue){
  const explosion = document.createElement("span");
  explosion.className = "firework-explosion";
  explosion.style.left = `${x}%`;
  explosion.style.top = `${y}%`;
  const particles = 14 + Math.floor(Math.random() * 6);
  for(let i=0; i<particles; i+=1){
    const particle = document.createElement("span");
    particle.className = "firework-particle";
    const angle = (Math.PI * 2 * i) / particles;
    const distance = 40 + Math.random() * 45;
    const dx = Math.cos(angle) * distance;
    const dy = Math.sin(angle) * distance;
    particle.style.setProperty("--dx", `${dx}px`);
    particle.style.setProperty("--dy", `${dy}px`);
    particle.style.setProperty("--hue", hue);
    explosion.appendChild(particle);
  }
  container.appendChild(explosion);
  setTimeout(() => explosion.remove(), 1200);
}

function createFirework(container){
  const hue = Math.floor(Math.random() * 360);
  const rocket = document.createElement("span");
  rocket.className = "firework-rocket";
  const x = 10 + Math.random() * 80;
  const rise = 220 + Math.random() * 120;
  rocket.style.left = `${x}%`;
  rocket.style.setProperty("--hue", hue);
  rocket.style.setProperty("--rise", `${rise}px`);
  container.appendChild(rocket);
  setTimeout(() => {
    rocket.remove();
    const y = 20 + Math.random() * 40;
    createExplosion(container, x, y, hue);
  }, 900);
}

function startFireworks(){
  if(fireworksInterval){
    return;
  }
  const left = document.getElementById("drawFireworksLeft");
  const right = document.getElementById("drawFireworksRight");
  if(left){
    left.hidden = false;
  }
  if(right){
    right.hidden = false;
  }
  fireworksInterval = setInterval(() => {
    const maxBursts = Math.max(1, fireworksIntensity);
    const step = Math.max(120, Math.floor(fireworksIntervalMs / (maxBursts + 1)));
    for(let i=0; i<maxBursts; i+=1){
      const delay = i * step;
      if(left && !left.hidden){
        setTimeout(() => createFirework(left), delay);
      }
      if(right && !right.hidden){
        setTimeout(() => createFirework(right), delay + Math.floor(step / 2));
      }
    }
  }, fireworksIntervalMs);
}

function stopFireworks(){
  const left = document.getElementById("drawFireworksLeft");
  const right = document.getElementById("drawFireworksRight");
  if(left){
    left.hidden = true;
    left.innerHTML = "";
  }
  if(right){
    right.hidden = true;
    right.innerHTML = "";
  }
  if(fireworksInterval){
    clearInterval(fireworksInterval);
    fireworksInterval = null;
  }
}

async function loadWinner(){
  try{
    loadingWinner = true;
    const r = await fetch("api.php?action=winner", { cache:"no-store" });
    const j = await r.json();
    const list = j.winner && j.winner.winners ? j.winner.winners : [];
    renderWinner(list[0]);
  }catch(e){}
  finally{
    loadingWinner = false;
  }
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
  if(!clearWinnerBtn){
    return;
  }
  clearWinnerBtn.hidden = !(clearWinnerEnabled && hasWinner && raffleReset);
}
function updateDrawButton(){
  drawBtn.disabled = !(raffleOpen && passwordOk && !hasWinner);
}

function launchConfetti(target){
  if(confettiRunning){
    return;
  }
  confettiRunning = true;
  const container = document.createElement("div");
  container.className = "confetti";
  const drop = Math.max(120, target.offsetHeight + 20);
  container.style.setProperty("--confetti-drop", `${drop}px`);
  for(let i=0;i<28;i+=1){
    const piece = document.createElement("span");
    piece.className = "confetti-piece";
    piece.style.left = `${Math.random() * 100}%`;
    piece.style.background = `hsl(${Math.random() * 360}, 90%, 60%)`;
    piece.style.animationDelay = `${Math.random() * 0.2}s`;
    piece.style.animationDuration = `${confettiDurationMs}ms`;
    container.appendChild(piece);
  }
  target.appendChild(container);
  setTimeout(() => {
    confettiRunning = false;
    container.remove();
  }, confettiDurationMs + 200);
}

function startConfetti(target){
  if(confettiInterval){
    return;
  }
  launchConfetti(target);
  confettiInterval = setInterval(() => launchConfetti(target), confettiIntervalMs);
}

function stopConfetti(){
  if(confettiInterval){
    clearInterval(confettiInterval);
    confettiInterval = null;
  }
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
          renderWinner(list[0]);
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

if(clearWinnerBtn){
  clearWinnerBtn.addEventListener("click", async () => {
    if(!clearWinnerEnabled){
      return;
    }
  let pwd = "";
  if(clearWinnerPasswordEnabled){
    pwd = (window.prompt("Senha para limpar ganhador:") || "").trim();
  }
  try{
    const r = await fetch("api.php?action=clear_winner", {
      method: "POST",
      headers: { "Content-Type":"application/json" },
      body: JSON.stringify({ password: pwd })
    });
    const j = await r.json();
    if(j.ok){
      renderWinner(null);
      updateClearButton();
      return;
    }
    alert(j.error || "Não foi possível limpar o ganhador.");
  }catch(e){}
  });
}

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
