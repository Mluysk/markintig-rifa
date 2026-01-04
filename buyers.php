<?php
$cfg = require __DIR__ . "/config.php";
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Compradores • <?= htmlspecialchars($cfg["site_nome"]) ?></title>
  <link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
  <div class="wrap">
    <div class="titlebar">
      <h1>Compradores</h1>
      <a class="btn btn-outline" href="index.php">Voltar</a>
    </div>

    <div class="card">
      <div class="headrow">
        <h2>Lista de compradores</h2>
        <button id="reloadBuyers">Recarregar</button>
      </div>
      <div id="buyersList" class="buyers"></div>
      <div id="buyersPagination" class="pagination"></div>
      <div class="small">Mostrando 100 compradores por página.</div>
    </div>
  </div>

<script>
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
function esc(s){ return String(s||"").replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;").replaceAll('"',"&quot;").replaceAll("'","&#039;"); }

const buyersState = {
  list: [],
  page: 1,
  perPage: 100
};
function buyerStatusLabel(status){
  return status === "paid" ? "Pago" : "Reservado";
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
function renderPagination(){
  const el = document.getElementById("buyersPagination");
  const total = buyersState.list.length;
  const totalPages = Math.max(1, Math.ceil(total / buyersState.perPage));
  if(total <= buyersState.perPage){
    el.innerHTML = "";
    return;
  }
  const pages = [];
  for(let i=1;i<=totalPages;i++){
    pages.push(`<button class="page-btn ${i === buyersState.page ? "active" : ""}" data-page="${i}">${i}</button>`);
  }
  el.innerHTML = `
    <button class="page-btn" data-page="${buyersState.page - 1}" ${buyersState.page === 1 ? "disabled" : ""}>&lt;</button>
    ${pages.join("")}
    <button class="page-btn" data-page="${buyersState.page + 1}" ${buyersState.page === totalPages ? "disabled" : ""}>&gt;</button>
  `;
}
function renderPaginatedBuyers(){
  const el = document.getElementById("buyersList");
  const sorted = buyersState.list.slice().sort((a,b)=> Number(a.num) - Number(b.num));
  const start = (buyersState.page - 1) * buyersState.perPage;
  const pageItems = sorted.slice(start, start + buyersState.perPage);
  renderBuyersList(el, pageItems);
  renderPagination();
}
async function loadBuyers(){
  const j = await api("buyers");
  buyersState.list = j.buyers || [];
  buyersState.page = 1;
  renderPaginatedBuyers();
}

document.getElementById("reloadBuyers").onclick = loadBuyers;
document.getElementById("buyersPagination").addEventListener("click", (e)=>{
  const btn = e.target.closest(".page-btn");
  if(!btn || btn.disabled) return;
  const page = Number(btn.dataset.page);
  if(!Number.isFinite(page)) return;
  const totalPages = Math.max(1, Math.ceil(buyersState.list.length / buyersState.perPage));
  buyersState.page = Math.min(Math.max(1, page), totalPages);
  renderPaginatedBuyers();
});

loadBuyers();
setInterval(loadBuyers, 12000);
</script>
</body>
</html>
