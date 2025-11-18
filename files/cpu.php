<?php
$targets_file = '/etc/lsyncd/targets.conf';
$childServers = [];

// Master server info
$masterIP = '127.0.0.1';  // अपनी master server IP डालें
$masterURL = "http://{$masterIP}/child-cpu.php?json=1";

// Child servers
if (file_exists($targets_file)) {
    $lines = file($targets_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $ip = trim($line);
        if ($ip === '' || $ip === $masterIP) continue; // master skip
        $childServers[$ip] = "http://{$ip}/child-cpu.php?json=1";
    }
}

function fetch_json($url){
    $opts = ["http" => ["method" => "GET", "timeout" => 3, "header" => "User-Agent: master-dashboard/1.0\r\n"]];
    $context = stream_context_create($opts);
    $json = @file_get_contents($url, false, $context);
    if ($json) {
        $d = json_decode($json, true);
        if (is_array($d)) return $d;
    }
    return ['error' => 'Unable to fetch data'];
}

if (isset($_GET['ajax'])) {
    $all = [];
    // Master first
    $all['MASTER'] = fetch_json($masterURL);
    // Then children
    foreach ($childServers as $ip => $url) {
        $all[$ip] = fetch_json($url);
    }
    header('Content-Type: application/json');
    echo json_encode($all);
    exit;
}
?>
<!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Master Dashboard — CPU & Memory</title>
<style>
body{
    margin:0;
    padding:12px;
    font-family:monospace;
    background:#fff;
    color:#d0d7da;
}
h1{
    color:#29a1b2;
    text-align:center;
    margin:6px 0 14px;
}
.dashboard{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
    grid-gap:12px;
}
.card{
    background:#0b0c0d;
    border-radius:8px;
    padding:6px;
    box-shadow:0 4px 12px rgba(0,0,0,.6);
}
.ip{
    font-weight:700;
    color:#ffffff;
    font-size:14px;
    margin-bottom:4px;
}
.small{
    color:#99a3a8;
    font-size:11px;
    margin:2px 0;
}
.barwrap{
    background:#122022;
    border-radius:6px;
    height:8px;
    width:100%;
    display:inline-block;
    vertical-align:middle;
    border:1px solid rgba(255,255,255,.1);
    overflow:hidden;
}
.fill{
    height:100%;
    display:block;
    transition: width 0.3s ease;
}
.good{background:#22c55e;}
.warn{background:#eab308;}
.bad{background:#ef4444;}

/* Hover effect for clickable cards */
.card-clickable {
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.card-clickable:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.7);
}

/* Master card style */
.card-master {
    cursor: default;
    opacity: 0.95;
}

/* Mobile adjustments */
@media (max-width: 600px) {
    .card{
        padding:8px;
    }
    .ip{
        font-size:14px;
    }
    .small{
        font-size:12px;
    }
    .barwrap{
        height:10px;
    }
}
</style>
</head>
<body>
<h1>🌐 Master Dashboard — CPU & Memory</h1>
<div class="dashboard" id="dashboard"></div>

<script>
function barHTML(percent){
    const pct = Math.max(0, Math.min(100, parseFloat(percent) || 0));
    const cls = pct > 75 ? 'bad' : (pct > 50 ? 'warn' : 'good');
    return `<span class="barwrap"><span class="fill ${cls}" style="width:${pct}%"></span></span>`;
}

function renderCard(ip, data){
    if(!data || data.error){
        return `<div class="card"><div class="ip">${ip}</div><div class="small" style="color:#ff7b7b">Unable to fetch data</div></div>`;
    }

    let hostname = data.hostname || '';
    if(hostname){
        const parts = hostname.split('.')[0].split('-');
        if(parts.length >= 2) hostname = parts[0]+'-'+parts[1];
    }

    const uptimeStr = data.uptime_str || '0 days, 00:00:00';

    let loadParts = (data.loadavg||'-').split(' ');
    let load1 = loadParts[0] || '-';
    let load5 = loadParts[1] || '-';
    let load15 = loadParts[2] || '-';
    let processes = loadParts[3] ? `Processes: ${loadParts[3]}` : '';
    const loadStr = `${load1}, ${load5}, ${load15} (1,5,15 min) ${processes}`;

    let cpuHtml = '', cpuTotal = 0, cores = 0;
    if(data.cpu){
        for(const core in data.cpu){
            const pct = parseFloat(data.cpu[core]) || 0;
            cpuHtml += `<div class="small">${core}: ${pct.toFixed(1)}% ${barHTML(pct)}</div>`;
            cpuTotal += pct; 
            cores++;
        }
    }
    const cpuAvg = cores > 0 ? cpuTotal / cores : 0;

    const memUsed = parseFloat(data.memUsedMB) || 0;
    const memTotal = parseFloat(data.memTotalMB) || 0;
    const memPct = parseFloat(data.memPercent) || (memTotal>0 ? memUsed*100/memTotal : 0);

    const isMaster = (ip === 'MASTER');
    const cardClass = isMaster ? 'card card-master' : 'card card-clickable';
    const hrefStart = isMaster ? '' : `<a href="http://${ip}/" target="_blank" style="text-decoration:none;color:inherit">`;
    const hrefEnd = isMaster ? '' : `</a>`;

    return `${hrefStart}<div class="${cardClass}">
        <div class="ip">${ip} — ${hostname}</div>
        <div class="small">Uptime: ${uptimeStr}</div>
        <div class="small">Load Avg: ${loadStr}</div>
        <div class="small"><strong>CPU Usage</strong></div>
        ${cpuHtml}
        <div class="small">Total CPU: ${cpuAvg.toFixed(1)}% ${barHTML(cpuAvg)}</div>
        <div class="small">Memory: ${memUsed}/${memTotal} MB (${memPct.toFixed(1)}%) ${barHTML(memPct)}</div>
    </div>${hrefEnd}`;
}

async function fetchAndRender(){
    const out = document.getElementById('dashboard');
    try{
        const res = await fetch('?ajax=1&_=' + Date.now(), {cache:'no-store'});
        const all = await res.json();
        let html = '';
        for(const ip in all) html += renderCard(ip, all[ip]);
        out.innerHTML = html;
    }catch(e){
        out.innerHTML = '<div class="small" style="color:#ff7b7b">Unable to fetch from master</div>';
        console.error(e);
    }
}

fetchAndRender();
setInterval(fetchAndRender, 2000);
</script>
</body>
</html>
