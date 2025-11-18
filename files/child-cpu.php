<?php
// ---------------------------
// CPU USAGE (persistent snapshot logic)
// ---------------------------
$cpu_stat = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$cpu_snapshot = [];
foreach ($cpu_stat as $line) {
    if (preg_match('/^cpu\d+/', $line)) {
        $parts = preg_split('/\s+/', trim($line));
        $cpu_snapshot[$parts[0]] = array_map('intval', array_slice($parts, 1));
    }
}

// previous snapshot (store in tmp)
$prev_file = '/tmp/cpu_prev.json';
$prev_data = @json_decode(@file_get_contents($prev_file), true) ?: [];
$result = [];

$total_used = 0;
$total_total = 0;

foreach ($cpu_snapshot as $core => $vals) {
    $prev = $prev_data[$core] ?? $vals;
    $curr_total = array_sum($vals);
    $prev_total = array_sum($prev);
    $total_delta = $curr_total - $prev_total;
    $idle_delta = ($vals[3] ?? 0) - ($prev[3] ?? 0);

    $usage = $total_delta > 0 ? (($total_delta - $idle_delta) / $total_delta) * 100 : 0;
    $result['cpu'][$core] = round($usage, 1);

    $total_used += max(0, $total_delta - $idle_delta);
    $total_total += max(0, $total_delta);
}

file_put_contents($prev_file, json_encode($cpu_snapshot));
$result['cpu_total'] = $total_total > 0 ? round(($total_used / $total_total) * 100, 1) : 0;

// ---------------------------
// MEMORY (htop-accurate)
// ---------------------------
$meminfo = @file('/proc/meminfo') ?: [];
$mem = [];
foreach ($meminfo as $line) {
    if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
        $mem[$m[1]] = $m[2];
    }
}

$MemTotal = $mem['MemTotal'] ?? 0;
$MemFree = $mem['MemFree'] ?? 0;
$Buffers = $mem['Buffers'] ?? 0;
$Cached = $mem['Cached'] ?? 0;
$SReclaimable = $mem['SReclaimable'] ?? 0;
$Shmem = $mem['Shmem'] ?? 0;

// 💡 htop formula:
// Used = MemTotal - MemFree - Buffers - Cached - SReclaimable + Shmem
$MemUsed = $MemTotal - $MemFree - $Buffers - $Cached - $SReclaimable + $Shmem;
$MemPercent = $MemTotal > 0 ? round(($MemUsed / $MemTotal) * 100, 1) : 0;

// Convert to MB
$result['memTotalMB'] = intdiv($MemTotal, 1024);
$result['memUsedMB']  = intdiv($MemUsed, 1024);
$result['memPercent'] = $MemPercent;

// Extra details like htop
$result['memDetails'] = [
    'MemFreeMB'       => intdiv($MemFree, 1024),
    'BuffersMB'       => intdiv($Buffers, 1024),
    'CachedMB'        => intdiv($Cached, 1024),
    'SReclaimableMB'  => intdiv($SReclaimable, 1024),
    'ShmemMB'         => intdiv($Shmem, 1024)
];

// Swap calculation
$SwapTotal = $mem['SwapTotal'] ?? 0;
$SwapFree = $mem['SwapFree'] ?? 0;
$SwapUsed = $SwapTotal - $SwapFree;
$result['swapTotalMB'] = intdiv($SwapTotal, 1024);
$result['swapUsedMB']  = intdiv($SwapUsed, 1024);
$result['swapPercent'] = $SwapTotal > 0 ? round(($SwapUsed / $SwapTotal) * 100, 1) : 0;

// ---------------------------
// Uptime
// ---------------------------
$uptime = @file_get_contents('/proc/uptime');
$uptime_sec = floatval(explode(' ', $uptime)[0]);
$days = floor($uptime_sec / 86400);
$hrs = floor(($uptime_sec % 86400) / 3600);
$mins = floor(($uptime_sec % 3600) / 60);
$secs = floor($uptime_sec % 60);
$result['uptime_str'] = sprintf("%02d days, %02d:%02d:%02d", $days, $hrs, $mins, $secs);

// ---------------------------
// Loadavg
// ---------------------------
$loadavg = @file_get_contents('/proc/loadavg');
$result['loadavg'] = trim($loadavg);

// ---------------------------
// Hostname
// ---------------------------
$result['hostname'] = explode('.', php_uname('n'))[0];

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
