<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin • Confirmar Pagamento</title>
  <link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
  <div class="wrap">
    <h1>Admin • Confirmar Pagamento</h1>
    <p class="sub">Enquanto não tiver gateway, confirme manualmente aqui.</p>

    <div class="card">
      <div class="bar">
        <input id="pin" type="password" placeholder="PIN admin">
        <input id="num" type="number" min="0" max="1000" placeholder="Número">
        <button id="mark">Marcar como pago</button>
      </div>
      <div class="small">PIN está no config.php</div>
    </div>
  </div>

<script>
async function api(action, payload){
  const r = await fetch("api.php?action=" + encodeURIComponent(action), {
    method:"POST",
    headers:{ "Content-Type":"application/json" },
    body: JSON.stringify(payload),
    cache:"no-store"
  });
  const j = await r.json();
  if(!j.ok) throw new Error(j.error || "erro");
  return j;
}
document.getElementById("mark").onclick = async () => {
  const pin = document.getElementById("pin").value;
  const num = Number(document.getElementById("num").value);
  try{ await api("mark_paid", { pin, num }); alert("Confirmado como pago."); }
  catch(e){ alert(e.message); }
};
</script>
</body>
</html>
