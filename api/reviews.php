<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

$dataDir = __DIR__ . '/../data';
$file = $dataDir . '/reviews.json';
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

switch ($_SERVER['REQUEST_METHOD']) {
  case 'GET':
    $reviews = read_json($file);
    // 新しい順にソート（idで降順）
    usort($reviews, function($a, $b) {
      $idA = isset($a['id']) ? $a['id'] : 0;
      $idB = isset($b['id']) ? $b['id'] : 0;
      return $idB - $idA;
    });
    echo json_encode($reviews, JSON_UNESCAPED_UNICODE);
    break;
    
  case 'POST':
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // 新しいレビューを追加する場合
    if (isset($data['comment'])) {
      $reviews = read_json($file);
      $newReview = [
        'id' => time() * 1000 + rand(0, 999), // ミリ秒単位のタイムスタンプ + ランダム
        'name' => isset($data['name']) ? trim($data['name']) : '匿名',
        'comment' => trim($data['comment']),
        'date' => date('Y-m-d H:i:s'),
        'timestamp' => time()
      ];
      
      if (empty($newReview['comment'])) {
        http_response_code(400);
        echo json_encode([ 'error' => 'コメントを入力してください' ], JSON_UNESCAPED_UNICODE);
        break;
      }
      
      $reviews[] = $newReview;
      write_json($file, $reviews);
      echo json_encode([ 'ok' => true, 'review' => $newReview ], JSON_UNESCAPED_UNICODE);
    } 
    // 既存のレビュー配列を置き換える場合（管理者用）
    else if (is_array($data)) {
      write_json($file, $data);
      echo json_encode([ 'ok' => true ], JSON_UNESCAPED_UNICODE);
    } else {
      http_response_code(400);
      echo json_encode([ 'error' => 'Invalid JSON data' ], JSON_UNESCAPED_UNICODE);
    }
    break;
    
  case 'DELETE':
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!isset($data['id'])) {
      http_response_code(400);
      echo json_encode([ 'error' => 'ID is required' ], JSON_UNESCAPED_UNICODE);
      break;
    }
    
    $reviews = read_json($file);
    $reviews = array_filter($reviews, function($review) use ($data) {
      return !isset($review['id']) || $review['id'] != $data['id'];
    });
    $reviews = array_values($reviews); // インデックスを再構築
    
    write_json($file, $reviews);
    echo json_encode([ 'ok' => true ], JSON_UNESCAPED_UNICODE);
    break;
    
  default:
    http_response_code(405);
    echo json_encode([ 'error' => 'Method not allowed' ], JSON_UNESCAPED_UNICODE);
}
?>

