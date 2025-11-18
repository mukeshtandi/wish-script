<?php
/*
  Childserver Total Request Dashboard (Final Clean Version)
  -----------------------------------------------------------
  ✅ Master + dynamic Child Servers
  ✅ Shows red (+diff) only after first refresh (not on initial load)
  ✅ Total values normal color (green)
  ✅ Auto-refresh every 1 minute
  ✅ Responsive dark theme
  Author: Mukesh Tandi
*/

function getChildServers($file = '/etc/lsyncd/targets.conf') {
    $ips = [];
    if (is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $line, $m)) {
                $ips[] = $m[1];
            }
        }
    }
    return array_unique($ips);
}

// Proxy endpoint
if (isset($_GET['proxy'])) {
    $ip = $_GET['proxy'];
    if ($ip === 'master') {
        $path = __DIR__ . '/data.php';
        header('Content-Type: application/json; charset=utf-8');
        if (is_file($path)) include($path);
        else echo json_encode(['error'=>'Local data.php not found']);
        exit;
    }
    if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)) {
        http_response_code(400);
        echo json_encode(['error'=>'Invalid IP']);
        exit;
    }
    $url = "http://{$ip}/data.php";
    $ctx = stream_context_create(['http'=>['timeout'=>3]]);
    $data = @file_get_contents($url, false, $ctx);
    header('Content-Type: application/json; charset=utf-8');
    echo $data === false ? json_encode(['error'=>"Failed to fetch from $ip"]) : $data;
    exit;
}

// ---- servers ----
$childs = getChildServers();
$master_ip = $_SERVER['HTTP_HOST'] ?? '';
$servers = array_merge(['master'], $childs);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Childserver Total Request Dashboard</title>
<style>
:root {
  --bg:#1a1a1a;--panel:#0d1112;--accent:#02a3b8;--muted:#9aa3a8;
  --text:#ccc;--red:#ff4c4c;--green:#00e6a8;
}
body{margin:0;background:var(--bg);color:var(--muted);font-family:ui-monospace,Menlo,Monaco,monospace;padding:10px;}
h1{text-align:center;color:var(--accent);font-weight:600;margin-bottom:14px;font-size:clamp(1rem,2.5vw,1.5rem);}
.table-container{overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:6px;}
.center-table{width:max-content;min-width:100%;border-collapse:collapse;font-size:clamp(11px,2.5vw,13px);}
.center-table th,.center-table td{border:1px solid rgba(255,255,255,.08);padding:6px 8px;text-align:center;vertical-align:middle;white-space:nowrap;}
.center-table thead th{background:var(--panel);color:var(--accent);}
.center-table tbody tr:nth-child(odd){background:rgba(255,255,255,.03);}
.loading{text-align:center;font-size:13px;color:#888;padding:10px;}
.header-ip{font-size:clamp(11px,2vw,13px);font-weight:600;color:var(--accent);}
.header-host{font-size:clamp(10px,2vw,12px);color:#aaa;}
.sub-header{font-size:11px;color:#6cd1dd;background:#0f1718;}
/* make only the very first header cell (Domain) sticky and left-aligned
   — do NOT target first-child on every row (that caused sub-header to left-align) */
.center-table thead tr:first-child th:first-child{
  position: sticky;
  left: 0;
  background: var(--panel);
  z-index: 10;
  text-align: left;
  padding-left: 10px;
  font-weight: 600;
  color: var(--accent);
}

/* keep tbody first column (domain cells) sticky but keep their text color */
.center-table tbody td:first-child{
  position: sticky;
  left: 0;
  background: #111417;
  color: var(--text);
  z-index: 9; /* below the header cell so header stays on top */
}

/* ensure sub-header cells are centered */
.center-table th.sub-header{
  text-align: center;
}
tfoot td{background:#0d1112;color:#02a3b8;font-weight:600;}
.footer{font-size:clamp(10px,1.8vw,12px);text-align:center;color:#666;margin-top:10px;}
@media(max-width:768px){.center-table th,.center-table td{padding:4px 6px;}}
.clickable-column {cursor: pointer;}
.clickable-column:hover {background: rgba(2,163,184,0.1);}
.diff{color:var(--red);font-weight:500;}
</style>
</head>
<body>
<h1>🌐 Childserver Total Request Dashboard</h1>

<div class="table-container">
<table class="center-table">
<thead>
  <tr>
    <th rowspan="2">Domain</th>
    <?php foreach ($servers as $ip): 
      $target_ip = $ip === 'master' ? $master_ip : $ip;
    ?>
      <th class="clickable-column" data-url="http://<?= htmlspecialchars($target_ip) ?>/">
        <div class="header-ip"><?= htmlspecialchars($target_ip) ?></div>
        <div class="header-host">(loading)</div>
      </th>
    <?php endforeach; ?>
    <th rowspan="2">Total</th>
  </tr>
  <tr>
    <?php foreach ($servers as $ip): ?>
      <th class="sub-header">Total Req</th>
    <?php endforeach; ?>
  </tr>
</thead>

  <tbody id="tbody">
    <tr><td colspan="<?= 1 + count($servers) + 1 ?>" class="loading">Fetching data...</td></tr>
  </tbody>
  <tfoot id="tfoot"></tfoot>
</table>
</div>

<div class="footer">
  Master + <?= count($childs) ?> Child Servers · Auto-refresh every 1 Minute · Swipe ↔️ for servers
</div>

<script>
const servers = <?php echo json_encode($servers); ?>;
const tbody = document.getElementById('tbody');
const tfoot = document.getElementById('tfoot');
const hostElems = document.querySelectorAll(".header-host");

let prevTotals = {};
let prevGrandTotal = 0;
let prevRowTotals = {};
let prevData = {};
let firstLoad = true; // 🚀 Track if it's the first refresh

async function fetchAll(){
  const fetchPromises = servers.map(async (ip, i) => {
    const url = `?proxy=${encodeURIComponent(ip)}&_=${Date.now()}`;
    try {
      const res = await fetch(url, { cache: "no-store" });
      const json = await res.json();
      const hostname = json.hostname || (ip === "master" ? "master" : ip);
      if (hostElems[i]) hostElems[i].innerText = hostname;
      return { ip, data: json.domains || {} };
    } catch (e) {
      console.error("❌", ip, e);
      if (hostElems[i]) hostElems[i].innerText = "error";
      return { ip, data: null };
    }
  });

  // 🚀 अब सभी requests एक साथ parallel जाएँगी
  const results = await Promise.all(fetchPromises);

  // डेटा collect करके render करो
  const allData = {};
  results.forEach(r => {
    allData[r.ip] = r.data;
  });

  renderTable(allData);
}

function renderTable(data){
  const domains = new Set();
  for(const ip in data){
    const srv = data[ip];
    if(!srv) continue;
    Object.keys(srv).forEach(d=>domains.add(d));
  }
  if(domains.size===0){
    tbody.innerHTML = `<tr><td colspan="${1+servers.length+1}" class="loading">No domain data found.</td></tr>`;
    tfoot.innerHTML = '';
    return;
  }

  const rows=[];
  const totals={};
  const currentRowTotals = {};

  for(const domain of Array.from(domains).sort()){
    let html=`<td>${domain}</td>`;
    let rowTotal = 0;

    for(const ip of servers){
      const srv=data[ip];
      if(!srv||!srv[domain]){
        html+=`<td style="color:#555">—</td>`;
      } else {
        const v=srv[domain].TOT_REQS??0;
        const prevVal = (prevData[ip]?.[domain]) || 0;
        const diff = v - prevVal;
        let diffHtml = (!firstLoad && diff>0) ? ` <span class='diff'>(+${diff})</span>` : "";
        html+=`<td>${v}${diffHtml}</td>`;
        if(!prevData[ip]) prevData[ip] = {};
        prevData[ip][domain] = v;
        totals[ip]=(totals[ip]||0)+v;
        rowTotal += v;
      }
    }

    const prevRow = prevRowTotals[domain] || 0;
    const diff = rowTotal - prevRow;
    let diffHtml = (!firstLoad && diff>0) ? ` <span class='diff'>(+${diff})</span>` : "";
    html += `<td style="font-weight:600;color:var(--green)">${rowTotal}${diffHtml}</td>`;
    currentRowTotals[domain] = rowTotal;

    rows.push(`<tr>${html}</tr>`);
  }

  tbody.innerHTML=rows.join("");

  let footHtml=`<td>Total</td>`;
  let grandTotal = 0;
  for(const ip of servers){
    const val = totals[ip]||0;
    grandTotal += val;
    const prev = prevTotals[ip]||0;
    const diff = val - prev;
    let diffHtml = (!firstLoad && diff>0) ? ` <span class='diff'>(+${diff})</span>` : "";
    footHtml+=`<td>${val}${diffHtml}</td>`;
  }

  const gdiff = grandTotal - prevGrandTotal;
  let gdiffHtml = (!firstLoad && gdiff>0) ? ` <span class='diff'>(+${gdiff})</span>` : "";
  footHtml += `<td style="font-weight:700;color:#02a3b8">${grandTotal}${gdiffHtml}</td>`;
  tfoot.innerHTML=`<tr>${footHtml}</tr>`;

  prevTotals = totals;
  prevGrandTotal = grandTotal;
  prevRowTotals = currentRowTotals;
  firstLoad = false; // ✅ After first complete load
}

async function autoRefresh(){
  await fetchAll();
  setTimeout(autoRefresh,60000);
}

function makeColumnsClickable(){
  const ths = document.querySelectorAll("th.clickable-column");
  ths.forEach((th, index)=>{
    const url = th.dataset.url;
    if(!url) return;
    const table = th.closest("table");
    const colIndex = index + 1;

    // ✅ Select only column cells (tbody + tfoot)
    const cells = table.querySelectorAll(
      `tbody tr td:nth-child(${colIndex+1}), tfoot tr td:nth-child(${colIndex+1})`
    );

    // Make these cells clickable
    cells.forEach(td=>{
      td.classList.add("clickable-column");
      td.addEventListener("click", ()=> window.open(url, "_blank"));
    });

    // ✅ Only the top header (IP/hostname) clickable
    th.addEventListener("click", ()=> window.open(url, "_blank"));
  });
}

setTimeout(makeColumnsClickable, 500);
autoRefresh();
</script>
</body>
</html>
