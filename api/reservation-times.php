<?php
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
$file = $dataDir . '/reservation-times.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

if (!file_exists($file)) {
    // デフォルトの予約時間設定（複数時間帯対応）
    $defaultSettings = [
        'enabled' => false,
        'timeSlots' => [
            ['startTime' => '11:30', 'endTime' => '12:45']
        ],
        'message' => '予約時間: 11:30-12:45'
    ];
    file_put_contents($file, json_encode($defaultSettings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function readJsonSafe($file) {
    $content = @file_get_contents($file);
    if ($content === false || $content === '') return [];
    $json = json_decode($content, true);
    return is_array($json) ? $json : [];
}

function writeJsonSafe($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $data = readJsonSafe($file);
        // 後方互換性: 古い形式（startTime/endTime）を新しい形式（timeSlots）に変換
        if (isset($data['startTime']) && isset($data['endTime']) && !isset($data['timeSlots'])) {
            $data['timeSlots'] = [
                ['startTime' => $data['startTime'], 'endTime' => $data['endTime']]
            ];
            unset($data['startTime']);
            unset($data['endTime']);
            // 変換したデータを保存
            writeJsonSafe($file, $data);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data'], JSON_UNESCAPED_UNICODE);
            break;
        }
        
        // 後方互換性: 古い形式（startTime/endTime）を新しい形式（timeSlots）に変換
        if (isset($input['startTime']) && isset($input['endTime']) && !isset($input['timeSlots'])) {
            $input['timeSlots'] = [
                ['startTime' => $input['startTime'], 'endTime' => $input['endTime']]
            ];
            unset($input['startTime']);
            unset($input['endTime']);
        }
        
        // 新しい形式のバリデーション
        if (!isset($input['timeSlots']) || !is_array($input['timeSlots']) || empty($input['timeSlots'])) {
            http_response_code(400);
            echo json_encode(['error' => 'timeSlots配列が必要です'], JSON_UNESCAPED_UNICODE);
            break;
        }
        
        // 各時間帯のバリデーション
        foreach ($input['timeSlots'] as $index => $slot) {
            if (!isset($slot['startTime']) || !isset($slot['endTime'])) {
                http_response_code(400);
                echo json_encode(['error' => "timeSlots[$index]にstartTimeとendTimeが必要です"], JSON_UNESCAPED_UNICODE);
                break 2;
            }
            if ($slot['startTime'] >= $slot['endTime']) {
                http_response_code(400);
                echo json_encode(['error' => "timeSlots[$index]の開始時間は終了時間より早く設定してください"], JSON_UNESCAPED_UNICODE);
                break 2;
            }
        }
        
        // enabledが設定されていない場合はtrueに設定
        if (!isset($input['enabled'])) {
            $input['enabled'] = true;
        }
        
        // メッセージが設定されていない場合は自動生成
        if (!isset($input['message']) || empty($input['message'])) {
            $timeStrings = array_map(function($slot) {
                return "{$slot['startTime']}-{$slot['endTime']}";
            }, $input['timeSlots']);
            $input['message'] = '予約時間: ' . implode(', ', $timeStrings);
        }
        
        writeJsonSafe($file, $input);
        echo json_encode(['success' => true, 'message' => '予約時間設定を更新しました'], JSON_UNESCAPED_UNICODE);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
}
?>



