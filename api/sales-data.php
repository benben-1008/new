<?php
// APIとして呼び出された場合のみヘッダーを送信
if (!defined('SALES_DATA_INTERNAL')) {
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
}

$dataDir = __DIR__ . '/../data';
$file = $dataDir . '/sales-data.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

if (!file_exists($file)) {
    file_put_contents($file, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function readJsonSafe($file) {
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    if ($content === false || $content === '') return [];
    $json = json_decode($content, true);
    return is_array($json) ? $json : [];
}

function writeJsonSafe($file, $data) {
    $result = @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if ($result !== false) {
        @chmod($file, 0666);
    }
    return $result;
}

// 予約リストを取得（全予約を配列で返す）
function getAllReservations() {
    global $file;
    $salesData = readJsonSafe($file);
    $reservations = [];
    
    foreach ($salesData as $date => $dayData) {
        if (is_array($dayData) && isset($dayData['reservationList']) && is_array($dayData['reservationList'])) {
            foreach ($dayData['reservationList'] as $reservation) {
                $reservations[] = $reservation;
            }
        }
    }
    
    return $reservations;
}

// 予約を追加（詳細情報を含む）
function addReservationDetail($reservation) {
    global $file;
    
    $salesData = readJsonSafe($file);
    $date = $reservation['date'] ?? date('Y-m-d');
    
    // 日付が存在しない場合は初期化
    if (!isset($salesData[$date]) || !is_array($salesData[$date])) {
        $salesData[$date] = [
            'reservations' => 0,
            'people' => 0,
            'menuSales' => [],
            'reservationList' => []
        ];
    }
    
    // reservationListが存在しない場合は初期化
    if (!isset($salesData[$date]['reservationList']) || !is_array($salesData[$date]['reservationList'])) {
        $salesData[$date]['reservationList'] = [];
    }
    
    // 予約リストに追加
    $salesData[$date]['reservationList'][] = $reservation;
    
    // 予約数を+1
    $people = intval($reservation['people'] ?? 1);
    $salesData[$date]['reservations'] += $people;
    
    // 認証済みの場合は来客数も+1
    $verified = isset($reservation['verified']) && ($reservation['verified'] === true || $reservation['verified'] === 'true' || $reservation['verified'] === 1);
    if ($verified) {
        $salesData[$date]['people'] += $people;
        
        // メニュー別売上を+1
        $food = $reservation['food'] ?? '';
        if ($food) {
            if (!isset($salesData[$date]['menuSales'][$food])) {
                $salesData[$date]['menuSales'][$food] = 0;
            }
            $salesData[$date]['menuSales'][$food] += $people;
        }
    }
    
    return writeJsonSafe($file, $salesData);
}

// 予約リスト全体を保存（既存の予約リストを置き換え）
function saveAllReservations($reservations) {
    global $file;
    
    $salesData = readJsonSafe($file);
    
    // 既存の予約リストをクリア
    foreach ($salesData as $date => $dayData) {
        if (is_array($dayData) && isset($dayData['reservationList'])) {
            unset($salesData[$date]['reservationList']);
        }
    }
    
    // 新しい予約リストを追加
    foreach ($reservations as $reservation) {
        $date = $reservation['date'] ?? date('Y-m-d');
        
        if (!isset($salesData[$date]) || !is_array($salesData[$date])) {
            $salesData[$date] = [
                'reservations' => 0,
                'people' => 0,
                'menuSales' => [],
                'reservationList' => []
            ];
        }
        
        if (!isset($salesData[$date]['reservationList']) || !is_array($salesData[$date]['reservationList'])) {
            $salesData[$date]['reservationList'] = [];
        }
        
        $salesData[$date]['reservationList'][] = $reservation;
    }
    
    // 集計データを再計算
    foreach ($salesData as $date => $dayData) {
        if (is_array($dayData) && isset($dayData['reservationList'])) {
            $salesData[$date]['reservations'] = 0;
            $salesData[$date]['people'] = 0;
            $salesData[$date]['menuSales'] = [];
            
            foreach ($dayData['reservationList'] as $reservation) {
                $people = intval($reservation['people'] ?? 1);
                $salesData[$date]['reservations'] += $people;
                
                $verified = isset($reservation['verified']) && ($reservation['verified'] === true || $reservation['verified'] === 'true' || $reservation['verified'] === 1);
                if ($verified) {
                    $salesData[$date]['people'] += $people;
                    
                    $food = $reservation['food'] ?? '';
                    if ($food) {
                        if (!isset($salesData[$date]['menuSales'][$food])) {
                            $salesData[$date]['menuSales'][$food] = 0;
                        }
                        $salesData[$date]['menuSales'][$food] += $people;
                    }
                }
            }
        }
    }
    
    return writeJsonSafe($file, $salesData);
}

// 予約を追加（+1する）
function addReservationToSales($date, $food, $people = 1, $verified = false) {
    global $file;
    
    $salesData = readJsonSafe($file);
    
    // 日付が存在しない場合は初期化
    if (!isset($salesData[$date])) {
        $salesData[$date] = [
            'reservations' => 0,
            'people' => 0,
            'menuSales' => []
        ];
    }
    
    // 予約数を+1
    $salesData[$date]['reservations'] += $people;
    
    // 認証済みの場合は来客数も+1
    if ($verified) {
        $salesData[$date]['people'] += $people;
        
        // メニュー別売上を+1
        if ($food) {
            if (!isset($salesData[$date]['menuSales'][$food])) {
                $salesData[$date]['menuSales'][$food] = 0;
            }
            $salesData[$date]['menuSales'][$food] += $people;
        }
    }
    
    writeJsonSafe($file, $salesData);
    return true;
}

// 認証状態を更新（未認証→認証済みに変更）
function updateVerificationStatus($date, $food, $people = 1) {
    global $file;
    
    $salesData = readJsonSafe($file);
    
    if (!isset($salesData[$date])) {
        return false;
    }
    
    // 来客数を+1
    $salesData[$date]['people'] += $people;
    
    // メニュー別売上を+1
    if ($food) {
        if (!isset($salesData[$date]['menuSales'][$food])) {
            $salesData[$date]['menuSales'][$food] = 0;
        }
        $salesData[$date]['menuSales'][$food] += $people;
    }
    
    writeJsonSafe($file, $salesData);
    return true;
}

// 名前と予約番号で予約を検索して認証状態を更新
function verifyReservationByNameAndNumber($name, $reservationNumber) {
    global $file;
    
    $salesData = readJsonSafe($file);
    $found = false;
    
    foreach ($salesData as $date => $dayData) {
        if (is_array($dayData) && isset($dayData['reservationList']) && is_array($dayData['reservationList'])) {
            foreach ($dayData['reservationList'] as $index => $reservation) {
                if (isset($reservation['name']) && $reservation['name'] === $name &&
                    isset($reservation['reservationNumber']) && intval($reservation['reservationNumber']) === intval($reservationNumber)) {
                    // 認証状態を更新
                    if (!isset($reservation['verified']) || !$reservation['verified']) {
                        $salesData[$date]['reservationList'][$index]['verified'] = true;
                        $found = true;
                        
                        // 集計データを更新
                        $people = intval($reservation['people'] ?? 1);
                        $salesData[$date]['people'] = intval($salesData[$date]['people'] ?? 0) + $people;
                        
                        $food = $reservation['food'] ?? '';
                        if ($food) {
                            if (!isset($salesData[$date]['menuSales'][$food])) {
                                $salesData[$date]['menuSales'][$food] = 0;
                            }
                            $salesData[$date]['menuSales'][$food] += $people;
                        }
                    }
                    break 2;
                }
            }
        }
    }
    
    if ($found) {
        writeJsonSafe($file, $salesData);
        return true;
    }
    
    return false;
}

// APIとして呼び出された場合のみ処理を実行
if (!defined('SALES_DATA_INTERNAL')) {
    switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // クエリパラメータで予約リストのみを取得する場合
        if (isset($_GET['reservations']) && $_GET['reservations'] === 'true') {
            echo json_encode(getAllReservations(), JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(readJsonSafe($file), JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'POST':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!is_array($data) || !isset($data['action'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid request. action is required.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        
        $action = $data['action'];
        
        if ($action === 'add') {
            // 予約を追加（集計のみ）
            $date = $data['date'] ?? date('Y-m-d');
            $food = $data['food'] ?? '';
            $people = intval($data['people'] ?? 1);
            $verified = isset($data['verified']) && ($data['verified'] === true || $data['verified'] === 'true' || $data['verified'] === 1);
            
            if (addReservationToSales($date, $food, $people, $verified)) {
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to add reservation'], JSON_UNESCAPED_UNICODE);
            }
        } elseif ($action === 'add-detail') {
            // 予約を追加（詳細情報を含む）
            if (addReservationDetail($data)) {
                echo json_encode(['ok' => true, 'success' => true], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to add reservation detail'], JSON_UNESCAPED_UNICODE);
            }
        } elseif ($action === 'save-all') {
            // 予約リスト全体を保存
            if (!isset($data['reservations']) || !is_array($data['reservations'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid request. reservations array is required.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            if (saveAllReservations($data['reservations'])) {
                $allReservations = getAllReservations();
                echo json_encode([
                    'ok' => true,
                    'success' => true,
                    'message' => '予約が正常に保存されました',
                    'count' => count($allReservations)
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to save reservations'], JSON_UNESCAPED_UNICODE);
            }
        } elseif ($action === 'get-reservations') {
            // 予約リストを取得
            $reservations = getAllReservations();
            echo json_encode($reservations, JSON_UNESCAPED_UNICODE);
        } elseif ($action === 'verify') {
            // 認証状態を更新（名前と予約番号で）
            if (isset($data['name']) && isset($data['reservationNumber'])) {
                $name = trim($data['name']);
                $reservationNumber = intval($data['reservationNumber']);
                
                if (verifyReservationByNameAndNumber($name, $reservationNumber)) {
                    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => '予約が見つかりませんでした'], JSON_UNESCAPED_UNICODE);
                }
            } else {
                // 従来の方法（日付、メニュー、人数）
                $date = $data['date'] ?? date('Y-m-d');
                $food = $data['food'] ?? '';
                $people = intval($data['people'] ?? 1);
                
                if (updateVerificationStatus($date, $food, $people)) {
                    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to update verification'], JSON_UNESCAPED_UNICODE);
                }
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    }
}
?>

