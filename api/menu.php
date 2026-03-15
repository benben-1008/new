<?php
// Simple JSON storage API for menus
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

$dataDir = __DIR__ . '/../data';
$file = $dataDir . '/menu.json';
$lastResetFile = $dataDir . '/last-stock-reset.json';
if (!is_dir($dataDir)) {
  mkdir($dataDir, 0777, true);
}
if (!file_exists($file)) {
  file_put_contents($file, json_encode([] , JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function read_json($file) {
  $content = @file_get_contents($file);
  if ($content === false || $content === '') return [];
  $json = json_decode($content, true);
  return is_array($json) ? $json : [];
}

function write_json($file, $data) {
  return file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// 日付が変わったとき、または手動リセット時にメニュー残数を initialStock に戻す
function maybe_reset_stock_by_day($menus, $file, $lastResetFile, $force = false) {
  $today = date('Y-m-d');
  $lastDate = '';
  if (!$force && file_exists($lastResetFile)) {
    $raw = @file_get_contents($lastResetFile);
    if ($raw !== false) {
      $data = json_decode($raw, true);
      if (is_array($data) && !empty($data['date'])) {
        $lastDate = $data['date'];
      }
    }
  }
  if (!$force && $lastDate === $today) {
    return $menus;
  }
  $changed = false;
  foreach ($menus as $i => $m) {
    $initial = isset($m['initialStock']) ? (int)$m['initialStock'] : (isset($m['stock']) ? (int)$m['stock'] : 0);
    $current = isset($m['stock']) ? (int)$m['stock'] : 0;
    if ($current !== $initial) {
      $menus[$i]['stock'] = $initial;
      $changed = true;
    }
  }
  if ($changed || $force || $lastDate !== $today) {
    write_json($file, $menus);
    write_json($lastResetFile, ['date' => $today]);
  }
  return $menus;
}

switch ($_SERVER['REQUEST_METHOD']) {
  case 'GET':
    $menus = read_json($file);
    $forceReset = isset($_GET['reset']) && $_GET['reset'] === '1';
    $menus = maybe_reset_stock_by_day($menus, $file, $lastResetFile, $forceReset);
    echo json_encode($menus, JSON_UNESCAPED_UNICODE);
    break;
  case 'POST':
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) {
      http_response_code(400);
      echo json_encode([ 'error' => 'Invalid JSON array' ], JSON_UNESCAPED_UNICODE);
      break;
    }
    write_json($file, $data);
    echo json_encode([ 'ok' => true ], JSON_UNESCAPED_UNICODE);
    break;
  default:
    http_response_code(405);
    echo json_encode([ 'error' => 'Method not allowed' ], JSON_UNESCAPED_UNICODE);
}
?>

