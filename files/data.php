<?php
/*
  OpenLiteSpeed Domain Stats — JSON Only (HTOP Accurate + Hostname)
  -------------------------------------------------------
  Output: Pure JSON
  Fields: hostname, domain, REQ_PROCESSING, REQ_PER_SEC, TOT_REQS
  Reads: /tmp/lshttpd/.rtreport*
  Author: Mukesh Tandi
*/

header('Content-Type: application/json; charset=utf-8');

// Directory where OLS runtime stats are stored
$STATSDIR = '/tmp/lshttpd';
$domain_snapshot = [];

// Collect all realtime report files
foreach (glob("$STATSDIR/.rtreport*") as $file) {
    if (!is_file($file)) continue;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/REQ_RATE\s+\[(.*?)\].*?REQ_PROCESSING:\s*(\d+).*?REQ_PER_SEC:\s*([\d.]+).*?TOT_REQS:\s*(\d+)/', $line, $m)) {
            $label = trim($m[1]);
            // Filter out internal/system vhosts
            if ($label === '' || strcasecmp($label, '_AdminVHost') === 0 || strtolower($label) === 'example') continue;
            $domain_snapshot[$label]['REQ_PROCESSING'] = ($domain_snapshot[$label]['REQ_PROCESSING'] ?? 0) + (int)$m[2];
            $domain_snapshot[$label]['REQ_PER_SEC']     = ($domain_snapshot[$label]['REQ_PER_SEC'] ?? 0.0) + (float)$m[3];
            $domain_snapshot[$label]['TOT_REQS']         = ($domain_snapshot[$label]['TOT_REQS'] ?? 0) + (int)$m[4];
        }
    }
}

// Sort alphabetically
if (!empty($domain_snapshot)) {
    uksort($domain_snapshot, fn($a,$b)=>strcasecmp($a,$b));
}

// Totals
$total = ['REQ_PROCESSING'=>0, 'REQ_PER_SEC'=>0.0, 'TOT_REQS'=>0];
foreach ($domain_snapshot as $d) {
    $total['REQ_PROCESSING'] += $d['REQ_PROCESSING'];
    $total['REQ_PER_SEC']    += $d['REQ_PER_SEC'];
    $total['TOT_REQS']       += $d['TOT_REQS'];
}

// Hostname
$hostname = explode('.', php_uname('n'))[0] ?? php_uname('n');

// Response JSON
$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'hostname'  => $hostname,
    'source'    => '/tmp/lshttpd/.rtreport*',
    'domains'   => $domain_snapshot,
    'totals'    => $total
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
?>
