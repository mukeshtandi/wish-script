<?php
/*
  OpenLiteSpeed Live Dashboard — Optimized + HTOP Accurate Memory
  - Reads /tmp/lshttpd.rtreport* for domains & PHP stats
  - Filters _AdminVHost and empty domains
  - Sends raw CPU counters for delta calc in browser
  - Memory formula now exactly matches htop
  - Auto-refresh every 1.5s
*/

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');

    $STATSDIR = '/tmp/lshttpd';
    $domain_snapshot = [];
    $total_req_processing = 0;
    $total_req_per_sec = 0.0;
    $total_tot_reqs = 0;
    $php_busy = 0;
    $php_idle = 0;
    $http_act = 0;
    $https_act = 0;
    $max_http = 0;
    $max_https = 0;

    foreach (glob("$STATSDIR/.rtreport*") as $file) {
        if (!is_file($file)) continue;
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Totals
            if (preg_match('/REQ_PROCESSING:\s*(\d+)/', $line, $m)) $total_req_processing += (int)$m[1];
            if (preg_match('/REQ_PER_SEC:\s*([\d.]+)/', $line, $m)) $total_req_per_sec += (float)$m[1];
            if (preg_match('/TOT_REQS:\s*(\d+)/', $line, $m)) $total_tot_reqs += (int)$m[1];
            if (preg_match('/INUSE_CONN:\s*(\d+)/', $line, $m)) $php_busy += (int)$m[1];
            if (preg_match('/IDLE_CONN:\s*(\d+)/', $line, $m)) $php_idle += (int)$m[1];
            if (preg_match('/PLAINCONN:\s*(\d+)/', $line, $m)) $http_act += (int)$m[1];
            if (preg_match('/SSLCONN:\s*(\d+)/', $line, $m)) $https_act += (int)$m[1];
            if (preg_match('/MAXCONN:\s*(\d+)/', $line, $m)) $max_http = max($max_http, (int)$m[1]);
            if (preg_match('/MAXSSL_CONN:\s*(\d+)/', $line, $m)) $max_https = max($max_https, (int)$m[1]);

            // Domains
            if (preg_match('/REQ_RATE\s+\[(.*?)\].*?REQ_PROCESSING:\s*(\d+).*?REQ_PER_SEC:\s*([\d.]+).*?TOT_REQS:\s*(\d+)/', $line, $m)) {
                $label = trim($m[1]);
                if ($label === '' || strcasecmp($label, '_AdminVHost') === 0 || strtolower($label) === 'example') continue;
                $domain_snapshot[$label]['RP'] = ($domain_snapshot[$label]['RP'] ?? 0) + (int)$m[2];
                $domain_snapshot[$label]['RPS'] = ($domain_snapshot[$label]['RPS'] ?? 0.0) + (float)$m[3];
                $domain_snapshot[$label]['TOT'] = ($domain_snapshot[$label]['TOT'] ?? 0) + (int)$m[4];
            }
        }
    }

    if (!empty($domain_snapshot)) {
        uksort($domain_snapshot, fn($a,$b)=>strcasecmp($a,$b));
    }

    // -------- System Metrics --------
    // CPU (raw counters)
    $cpu_stat = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $cpu_snapshot = [];
    foreach ($cpu_stat as $line) {
        if (preg_match('/^cpu\d+/', $line)) {
            $parts = preg_split('/\s+/', trim($line));
            $cpu_snapshot[$parts[0]] = array_map('intval', array_slice($parts,1));
        }
    }

    // ✅ Memory (exact HTOP formula)
    $meminfo = @file('/proc/meminfo') ?: [];
    $mem = [];
    foreach ($meminfo as $l) {
        if (preg_match('/^(\w+):\s+(\d+)/', $l, $mm)) $mem[$mm[1]] = (int)$mm[2];
    }

    $memTotal = $mem['MemTotal'] ?? 0;
    $memFree = $mem['MemFree'] ?? 0;
    $buffers = $mem['Buffers'] ?? 0;
    $cached = $mem['Cached'] ?? 0;
    $reclaim = $mem['SReclaimable'] ?? 0;
    $shmem = $mem['Shmem'] ?? 0;

    // htop-like used memory:
    // Used = MemTotal - (MemFree + Buffers + Cached + SReclaimable) + Shmem
    $used_kb = $memTotal - ($memFree + $buffers + $cached + $reclaim) + $shmem;

    $memPercent = $memTotal > 0 ? round(($used_kb / $memTotal) * 100, 1) : 0;
    $memUsedMB = intdiv($used_kb, 1024);
    $memTotalMB = intdiv($memTotal, 1024);

    // Uptime
    $uptime_raw = @file_get_contents('/proc/uptime') ?: '0';
    $uptime_sec = floatval(explode(' ', $uptime_raw)[0]);
    $days = floor($uptime_sec / 86400);
    $hours = floor(($uptime_sec % 86400) / 3600);
    $minutes = floor(($uptime_sec % 3600) / 60);
    $seconds = floor($uptime_sec % 60);
    $uptime_str = sprintf("%02d days, %02d:%02d:%02d", $days, $hours, $minutes, $seconds);

    // Load Avg
    $load_raw = @file_get_contents('/proc/loadavg') ?: '0 0 0 0/0 0';
    $parts = explode(' ', trim($load_raw));
    $load_1  = floatval($parts[0] ?? 0);
    $load_5  = floatval($parts[1] ?? 0);
    $load_15 = floatval($parts[2] ?? 0);
    $running_total = $parts[3] ?? '0/0';
    [$running, $total] = explode('/', $running_total);
    $load_str = "{$load_1}, {$load_5}, {$load_15} (1,5,15 min)\nProcesses: {$running}/{$total}";

    // Response JSON
    $resp = [
        'hostname' => explode('.', php_uname('n'))[0],
        'server_ip' => $_SERVER['HTTP_HOST'] ?? '',
        'php_version' => phpversion(),
        'os' => php_uname('s').' '.php_uname('r'),
        'uptime' => $uptime_str,
        'loadavg' => $load_str,
        'cpu_snapshot' => $cpu_snapshot,
        'memPercent' => $memPercent,
        'memUsedMB' => $memUsedMB,
        'memTotalMB' => $memTotalMB,
        'php_busy' => $php_busy,
        'php_idle' => $php_idle,
        'http_act' => $http_act,
        'https_act' => $https_act,
        'max_http' => $max_http,
        'max_https' => $max_https,
        'total_req_processing' => $total_req_processing,
        'total_req_per_sec' => round($total_req_per_sec, 1),
        'total_tot_reqs' => $total_tot_reqs,
        'domain_snapshot' => $domain_snapshot
    ];

    echo json_encode($resp, JSON_PRETTY_PRINT);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo explode('.', php_uname('n'))[0]; ?></title>
<style>
:root{
  --bg:#1a1a1a; --panel:#101214; --accent:#028899; --muted:#9aa3a8; --good:#22c55e; --warn:#eab308; --bad:#ef4444;
  --box-h:260px;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:#9aa3a8;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, monospace;padding:14px;}
h1{margin:0;text-align:center;color:var(--accent);font-weight:600}
.topbar{display:flex;gap:18px;justify-content:center;align-items:stretch;flex-wrap:wrap;max-width:1200px;margin:14px auto;}
.card{background:var(--panel);border-radius:10px;padding:12px 14px;box-shadow:0 6px 18px rgba(0,0,0,.6);flex:1;min-width:260px;max-width:360px;height:var(--box-h);display:flex;flex-direction:column;justify-content:flex-start;}
.card h3{margin:0 0 8px 0;color:var(--accent);text-align:center;font-weight:600}
.card .body{flex:1;overflow:auto;padding:4px 2px}
.cols{display:flex;gap:12px;align-items:center}
.small{color:var(--muted);font-size:13px}
.info{color:var(--muted);font-size:13px;margin:8px 0}
.row{display:flex;justify-content:space-between;align-items:center;margin:6px 0}
.barwrap{background:#162021;border-radius:6px;overflow:hidden;height:14px;width:170px;display:inline-block;margin-left:8px;vertical-align:middle;border:1px solid rgba(255,255,255,.03)}
.fill{height:100%;transition:width .45s ease}
.center-table{width:45%;margin:26px auto 40px auto;border-collapse:collapse}
.center-table th,.center-table td{border:1px solid rgba(255,255,255,.06);padding:8px 10px;text-align:left}
.center-table thead th{background:#0d1112;color:var(--accent)}
.center-table tfoot td{background:#0d1112;color:var(--accent);font-weight:600}
.center-table tbody tr:nth-child(odd){background:rgba(255,255,255,.01)}
.center{display:flex;flex-direction:column;align-items:center;justify-content:center}
.footer{font-size:12px;color:var(--muted);text-align:center;margin-top:6px}
/* Mobile responsive */
@media (max-width: 980px) {
  /* Stack cards vertically and full width */
  .topbar {
    flex-direction: column;
    align-items: stretch;
  }
  .card {
    min-width: auto;
    max-width: 100%;
    height: auto; /* auto height for content */
  }
  :root {
    --box-h: auto;
  }

  /* Table width full screen */
  .center-table {
    width: 100%;
    table-layout: fixed; /* important: columns share space */
  }
  .center-table th, .center-table td {
    padding: 6px 6px;
    word-wrap: break-word; /* wrap text if needed */
    overflow-wrap: anywhere;
    text-align: center; /* optional, better fit */
  }
  .center-table td:first-child {
    text-align: left; /* domain column left align */
  }
}

</style>
</head>
<body>
<h1>🚀 OpenLiteSpeed Live Dashboard</h1>

<div class="topbar" role="region" aria-label="Top panels">
  <div class="card" id="card-summary">
    <h3>Summary</h3>
    <div class="body" id="summary-body"><div class="small">Loading summary...</div></div>
  </div>

  <div class="card center" id="card-server">
    <h3>Server Info</h3>
    <div class="body" id="server-body"><div class="small">Loading server info...</div></div>
  </div>

  <div class="card" id="card-system">
    <h3>System Metrics</h3>
    <div class="body" id="system-body"><div class="small">Loading system metrics...</div></div>
  </div>
</div>

<table class="center-table" aria-live="polite">
  <thead><tr><th>Domain</th><th>REQ_PROCESSING</th><th>REQ_PER_SEC</th><th>TOT_REQS</th></tr></thead>
  <tbody id="domain-rows"><tr><td colspan="4" class="small">Loading domains...</td></tr></tbody>
  <tfoot id="domain-foot"></tfoot>
</table>

<div class="footer">Data source: /tmp/lshttpd.rtreport* · Auto-refresh every 1.5s · Created by - Mukesh Tandi</div>

<script>
let prevCPUs = null;

function colorFor(p){
  if(p>75) return getComputedStyle(document.documentElement).getPropertyValue('--bad').trim();
  if(p>50) return getComputedStyle(document.documentElement).getPropertyValue('--warn').trim();
  return getComputedStyle(document.documentElement).getPropertyValue('--good').trim();
}
function barHTML(p){
  const color = colorFor(p);
  const w = Math.min(Math.max(p,0),100);
  return `<div class="barwrap"><div class="fill" style="width:${w}%;background:${color}"></div></div>`;
}

function calcCPU(snapshot){
  if(!prevCPUs){
    prevCPUs = snapshot;
    return {total:0, cores:[]};
  }
  let total_used = 0, total_total = 0;
  let cores = [];
  for(let core in snapshot){
    let prev = prevCPUs[core] || [];
    let curr = snapshot[core];
    let prev_total = prev.reduce((a,b)=>a+b,0);
    let curr_total = curr.reduce((a,b)=>a+b,0);
    let total_delta = curr_total - prev_total;
    let idle_delta = (curr[3] - (prev[3]||0));
    let percent = total_delta >0 ? ((total_delta - idle_delta)/total_delta)*100 : 0;
    cores.push({core:core, percent:percent});
    total_used += (total_delta - idle_delta);
    total_total += total_delta;
  }
  prevCPUs = snapshot;
  let total_percent = total_total>0 ? (total_used/total_total)*100 : 0;
  return {total:total_percent, cores:cores};
}

async function fetchAndRender(){
  try{
    const res = await fetch('?json=1&_='+Date.now());
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d = await res.json();

    // Summary
    const summaryBody = document.getElementById('summary-body');
    summaryBody.innerHTML = `
      <div class="row"><div class="small">Active PHP Requests</div><div>${d.total_req_processing}</div></div>
      <div class="row"><div class="small">LSAPI Busy / Idle</div><div>${d.php_busy} / ${d.php_idle}</div></div>
      <div class="row"><div class="small">HTTP (active/max)</div><div>${d.http_act} / ${d.max_http}</div></div>
      <div class="row"><div class="small">HTTPS (active/max)</div><div>${d.https_act} / ${d.max_https}</div></div>
      <div class="row"><div class="small">REQ/sec</div><div>${(d.total_req_per_sec).toFixed(1)}</div></div>
      <div class="row"><div class="small">Total Requests</div><div>${d.total_tot_reqs}</div></div>
    `;

    // Server
    const serverBody = document.getElementById('server-body');
    serverBody.innerHTML = `
      <div class="info">Hostname: <strong>${d.hostname}</strong></div>
      <div class="info">Server IP: <strong>${d.server_ip}</strong></div>
      <div class="info">OS: <strong>${d.os}</strong></div>
      <div class="info">PHP: <strong>${d.php_version}</strong></div>
      <div class="info">Uptime: <strong>${d.uptime}</strong></div>
      <div class="info">Load Avg: <strong>${d.loadavg}</strong></div>
    `;

    // System
    const systemBody = document.getElementById('system-body');
    const cpuData = calcCPU(d.cpu_snapshot);
    let coresHtml = cpuData.cores.map(c=>{
      return `<div class="row"><div class="small">${c.core}</div><div style="display:flex;align-items:center">${c.percent.toFixed(1)}% ${barHTML(c.percent)}</div></div>`;
    }).join('');
    systemBody.innerHTML = `
      ${coresHtml}
      <div class="row"><div class="small">CPU Total</div><div style="display:flex;align-items:center">${cpuData.total.toFixed(1)}% ${barHTML(cpuData.total)}</div></div>
      <div style="height:8px"></div>
      <div class="row" style="align-items:flex-start;">
        <div class="small" style="width:80px;">Memory</div>
        <div style="flex:1; display:flex; flex-direction:column;">
          <div>${barHTML(d.memPercent)}</div>
          <div style="margin-top:4px; font-size:12px; color:#9aa3a8;">
            ${d.memPercent.toFixed(1)}% (${d.memUsedMB}/${d.memTotalMB}MB)
          </div>
        </div>
      </div>
    `;

    // Domains
    const tbody = document.getElementById('domain-rows');
    tbody.innerHTML = '';
    const snapshot = d.domain_snapshot || {};
    const keys = Object.keys(snapshot);
    let totalRP=0, totalRPS=0, totalTOT=0;
    if(keys.length===0){
      tbody.innerHTML='<tr><td colspan="4" class="small">No domain data found.</td></tr>';
    } else {
      keys.forEach((k,idx)=>{
        const v=snapshot[k];
        const rp=v.RP??0;
        const rps=v.RPS??0;
        const tot=v.TOT??0;
        totalRP+=rp; totalRPS+=rps; totalTOT+=tot;
        const tr=document.createElement('tr');
        if(idx%2===1) tr.style.background='rgba(255,255,255,0.01)';
        tr.innerHTML=`<td>${k}</td><td>${rp}</td><td>${rps.toFixed(1)}</td><td>${tot}</td>`;
        tbody.appendChild(tr);
      });
    }

    // Footer totals
    const foot=document.getElementById('domain-foot');
    foot.innerHTML=`<tr><td>Total</td><td>${totalRP}</td><td>${totalRPS.toFixed(1)}</td><td>${totalTOT}</td></tr>`;

  } catch(err){
    console.error('Dashboard fetch error', err);
  }
}

fetchAndRender();
setInterval(fetchAndRender, 1500);
</script>
</body>
</html>
