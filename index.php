<?php
$cfg = require __DIR__ . "/config.php";
$valorFormatado = number_format($cfg["preco_centavos"] / 100, 2, ",", ".");
$whatsappNumero = preg_replace('/\D+/', '', (string)($cfg["whatsapp_numero"] ?? ""));
$whatsappLink = $whatsappNumero ? "https://wa.me/" . $whatsappNumero : "";
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= htmlspecialchars($cfg["site_nome"]) ?></title>
  <link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
  <div class="wrap">
    <div class="titlebar">
      <div class="titleblock">
        <h1><?= htmlspecialchars($cfg["site_nome"]) ?></h1>
        <p class="sub">Escolha um número de <b><?= $cfg["min_num"] ?></b> a <b><?= $cfg["max_num"] ?></b> • Valor <b>R$ <?= $valorFormatado ?></b></p>
        <div id="raffleStatus" class="raffle-status"></div>
        <div id="raffleTotal" class="raffle-total"></div>
      </div>
      <div class="title-actions">
        <div class="prize">
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
        <div class="action-buttons">
          <a class="btn btn-outline" href="sorteio.php">Ver sorteio</a>
          <a class="btn btn-outline" href="buyers.php">Ver compradores</a>
        </div>
      </div>
    </div>

    <div class="card winner" id="winnerCard" hidden>
      <div class="winner-title">Número sorteado</div>
      <div id="winnerContent" class="winner-content"></div>
    </div>
    <div class="fireworks fireworks-left" id="fireworksLeft" hidden></div>
    <div class="fireworks fireworks-right" id="fireworksRight" hidden></div>

    <div class="card">
      <div class="card-title">Cadastre para comprar a rifa</div>
      <div class="small card-desc">Informe as suas informações e escolha seus números da sorte e após aperte no botão comprar.</div>
      <div class="bar">
        <input id="name" placeholder="Seu nome e sobrenome (obrigatório)" maxlength="60">
        <input id="cpf" placeholder="Seu CPF (obrigatório)" maxlength="14">
        <input id="whatsapp" placeholder="Seu WhatsApp (obrigatório)" maxlength="15">
        <button id="buySelected">Comprar</button>
      </div>
      <div class="small">Reserva expira em <?= (int)$cfg["reserva_minutos"] ?> min se não confirmar.</div>
    </div>

    <div class="card">
      <div class="headrow">
        <h2>Últimos 4 compradores</h2>
      </div>
      <div id="buyersLatest" class="buyers"></div>
      <div class="small">CPF aparece só com os 4 últimos: ***********1234</div>
    </div>

    <div id="grid" class="grid"></div>
    <div class="grid-pagination">
      <button id="prevPage" class="page-nav" type="button">◀ Anterior</button>
      <div id="pageLabel" class="page-label"></div>
      <button id="nextPage" class="page-nav" type="button">Próximo ▶</button>
    </div>
    <div id="purchaseLinks" class="card purchase-links" hidden></div>
  </div>

<div class="modal" id="prizeModal" hidden>
  <div class="modal-backdrop" data-close="true"></div>
  <div class="modal-content">
    <button class="modal-close" type="button" data-close="true">×</button>
    <img src="img/premio.png" alt="Prêmio ampliado">
  </div>
</div>

<script>
function maskCpfInput(v){
  v = (v || "").replace(/\D/g,"").slice(0,11);
  if(v.length <= 3) return v;
  if(v.length <= 6) return v.replace(/(\d{3})(\d+)/, "$1.$2");
  if(v.length <= 9) return v.replace(/(\d{3})(\d{3})(\d+)/, "$1.$2.$3");
  return v.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2}).*/, "$1.$2.$3-$4");
}
document.getElementById("cpf").addEventListener("input", (e)=> e.target.value = maskCpfInput(e.target.value));
function maskWhatsappInput(v){
  v = (v || "").replace(/\D/g,"").slice(0,11);
  if(v.length <= 2) return v;
  if(v.length <= 6) return v.replace(/(\d{2})(\d+)/, "($1) $2");
  return v.replace(/(\d{2})(\d{5})(\d+)/, "($1) $2-$3");
}
document.getElementById("whatsapp").addEventListener("input", (e)=> e.target.value = maskWhatsappInput(e.target.value));

async function api(action, payload){
  const r = await fetch("api.php?action=" + encodeURIComponent(action), {
    method: payload ? "POST" : "GET",
    headers: payload ? { "Content-Type":"application/json" } : {},
    body: payload ? JSON.stringify(payload) : undefined,
    cache: "no-store"
  });
  const j = await r.json();
  if(!j.ok) throw new Error(j.error || "erro");
  return j;
}
function badge(status){
  if(status === "paid") return `<span class="b paid">Pago</span>`;
  if(status === "reserved") return `<span class="b reserved">Reservado</span>`;
  return `<span class="b free">Livre</span>`;
}
const selectedNumbers = new Set();
let currentPage = 0;
const pageSize = 101;
function renderGrid(list){
  const grid = document.getElementById("grid");
  const start = currentPage * pageSize;
  const pageItems = list.slice(start, start + pageSize);
  grid.innerHTML = pageItems.map(r => {
    const disabled = (r.status !== "free");
    const isSelected = selectedNumbers.has(Number(r.num));
    return `
      <button class="cell ${r.status} ${isSelected ? "selected" : ""}" data-num="${r.num}" ${disabled ? "disabled":""}>
        <div class="n">#${String(r.num).padStart(4,"0")}</div>
        ${badge(r.status)}
      </button>
    `;
  }).join("");
}
function updatePagination(list){
  const totalPages = Math.max(1, Math.ceil(list.length / pageSize));
  currentPage = Math.min(currentPage, totalPages - 1);
  const startNum = list[currentPage * pageSize]?.num ?? "";
  const endNum = list[Math.min(list.length - 1, (currentPage + 1) * pageSize - 1)]?.num ?? "";
  document.getElementById("pageLabel").textContent = `(${startNum} a ${endNum})`;
  document.getElementById("prevPage").disabled = currentPage === 0;
  document.getElementById("nextPage").disabled = currentPage >= totalPages - 1;
}
const currentGrid = [];
async function loadGrid(){
  const j = await api("status");
  currentGrid.length = 0;
  currentGrid.push(...j.data);
  updatePagination(currentGrid);
  renderGrid(currentGrid);
  updateRaffleStatus(currentGrid);
}
function updateRaffleStatus(list){
  const total = list.length;
  const remaining = list.filter(r => r.status === "free").length;
  const open = remaining > 0;
  const statusEl = document.getElementById("raffleStatus");
  statusEl.innerHTML = `
    <span class="raffle-badge ${open ? "open" : "closed"}">${open ? "Aberto" : "Fechado"}</span>
    <span class="raffle-remaining">Restam ${remaining} de ${total} números</span>
  `;
  const buyBtn = document.getElementById("buySelected");
  buyBtn.disabled = !open;
}
function esc(s){ return String(s||"").replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;").replaceAll('"',"&quot;").replaceAll("'","&#039;"); }
const precoCentavos = <?= (int)$cfg["preco_centavos"] ?>;
const moedaFormatter = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });

const buyersState = {
  list: []
};
function buyerStatusLabel(status){
  return status === "paid" ? "Pago" : "Reservado";
}
function buyerTimestamp(b){
  return Number(b.paid_at || b.created_at || 0);
}
function renderBuyersList(el, arr){
  if(!arr.length){
    el.innerHTML = `<div class="small">Ainda não há compradores.</div>`;
    return;
  }
  el.innerHTML = arr.map(b => `
      <div class="buyer">
        <b>#${String(b.num).padStart(4,"0")}</b>
        <span>${esc(b.name)}</span>
        <span class="cpf">CPF: ${esc(b.cpf)}</span>
        <span class="st ${b.status}">${buyerStatusLabel(b.status)}</span>
      </div>
  `).join("");
}
function renderLatestBuyers(){
  const el = document.getElementById("buyersLatest");
  const latest = buyersState.list
    .slice()
    .sort((a,b)=> buyerTimestamp(b) - buyerTimestamp(a))
    .slice(0, 4);
  renderBuyersList(el, latest);
}
function renderTotalCollected(){
  const totalPaid = buyersState.list.filter(b => b.status === "paid").length;
  const totalCents = totalPaid * precoCentavos;
  const totalEl = document.getElementById("raffleTotal");
  totalEl.textContent = `Valor arrecadado: ${moedaFormatter.format(totalCents / 100)}`;
}
async function loadBuyers(){
  const j = await api("buyers");
  buyersState.list = j.buyers || [];
  renderLatestBuyers();
  renderTotalCollected();
}

document.getElementById("openPrizeModal").addEventListener("click", () => {
  document.getElementById("prizeModal").hidden = false;
});
document.getElementById("prizeModal").addEventListener("click", (e) => {
  if(e.target && e.target.dataset && e.target.dataset.close){
    document.getElementById("prizeModal").hidden = true;
  }
});

document.addEventListener("click", async (e) => {
  const pageButton = e.target.closest(".page-btn");
  if(pageButton) return;
  const btn = e.target.closest(".cell");
  if(!btn) return;

  const num = Number(btn.dataset.num);
  if(selectedNumbers.has(num)){
    selectedNumbers.delete(num);
  }else{
    selectedNumbers.add(num);
  }
  renderGrid(currentGrid);
});
document.getElementById("prevPage").addEventListener("click", () => {
  currentPage = Math.max(0, currentPage - 1);
  updatePagination(currentGrid);
  renderGrid(currentGrid);
});
document.getElementById("nextPage").addEventListener("click", () => {
  const totalPages = Math.max(1, Math.ceil(currentGrid.length / pageSize));
  currentPage = Math.min(totalPages - 1, currentPage + 1);
  updatePagination(currentGrid);
  renderGrid(currentGrid);
});

function renderWinner(winner){
  const card = document.getElementById("winnerCard");
  const content = document.getElementById("winnerContent");
  const fireworksLeft = document.getElementById("fireworksLeft");
  const fireworksRight = document.getElementById("fireworksRight");
  const list = (winner && winner.winners) ? winner.winners : [];
  if(!list.length){
    card.hidden = true;
    content.innerHTML = "";
    stopFireworks();
    return;
  }
  card.hidden = false;
  content.innerHTML = list.map(w => `
    <div class="winner-item">
      <div class="winner-number">#${String(w.num).padStart(4,"0")}</div>
      <div class="winner-info">
        <div><strong>${esc(w.name)}</strong></div>
        <div>CPF: ${esc(w.cpf)}</div>
        ${w.whatsapp ? `<div>WhatsApp: ${esc(w.whatsapp)}</div>` : ""}
      </div>
    </div>
  `).join("");
  fireworksLeft.hidden = false;
  fireworksRight.hidden = false;
  startFireworks();
}

let fireworksInterval = null;
const fireworksIntensity = Math.max(1, Number(<?= json_encode($cfg["fogos_intensidade"] ?? 2) ?>) || 1);

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
  fireworksInterval = setInterval(() => {
    const left = document.getElementById("fireworksLeft");
    const right = document.getElementById("fireworksRight");
    if(left && !left.hidden){
      for(let i=0; i<fireworksIntensity; i+=1){
        createFirework(left);
      }
    }
    if(right && !right.hidden){
      for(let i=0; i<fireworksIntensity; i+=1){
        createFirework(right);
      }
    }
  }, 1200);
}

function stopFireworks(){
  const left = document.getElementById("fireworksLeft");
  const right = document.getElementById("fireworksRight");
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
    const j = await api("winner");
    renderWinner(j.winner || {});
  }catch(e){}
}
document.getElementById("buySelected").onclick = async () => {
  const name = (document.getElementById("name").value || "").trim();
  const cpf = (document.getElementById("cpf").value || "").trim();
  const whatsapp = (document.getElementById("whatsapp").value || "").trim();
  if(!name){ alert("Digite seu nome."); return; }
  if(!cpf){ alert("Digite seu CPF."); return; }
  if(!whatsapp){ alert("Digite seu WhatsApp."); return; }
  if(selectedNumbers.size === 0){ alert("Selecione ao menos um número."); return; }

  const purchaseLinks = document.getElementById("purchaseLinks");
  purchaseLinks.hidden = true;
  purchaseLinks.innerHTML = "";

  const nums = Array.from(selectedNumbers);
  try{
    const res = await api("reserve_bulk", { nums, name, cpf, whatsapp });
    selectedNumbers.clear();
    await loadGrid();
    await loadBuyers();

    const numsParam = encodeURIComponent(nums.join(","));
    location.href = `pix.php?nums=${numsParam}&txid=${encodeURIComponent(res.txid)}`;
  }catch(err){
    alert(err.message);
  }
};

loadGrid();
loadBuyers();
loadWinner();
setInterval(loadGrid, 8000);
setInterval(loadBuyers, 12000);
</script>
</body>
</html>
