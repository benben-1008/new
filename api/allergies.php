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
$allergiesFile = $dataDir . '/allergies.json';
$dailyMenuFile = $dataDir . '/daily-menu.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}
if (!file_exists($allergiesFile)) {
    file_put_contents($allergiesFile, json_encode(['allergies' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function readJson($file) {
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    if ($content === false || $content === '') return [];
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function writeJson($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// 定食に毎回つくもの: お米・お味噌汁 のアレルギー
function getBaseSetMealAllergens() {
    return ['米', '大豆']; // ごはん(米)、味噌汁(大豆)
}

// 主菜名からアレルギーを取得（既存allergiesにあればそれを使う、なければマッピング）
function getMainDishAllergens($mainDishName, $existingAllergies) {
    $name = trim($mainDishName);
    foreach ($existingAllergies as $item) {
        $menu = trim($item['menu'] ?? '');
        if ($menu !== '' && (strpos($name, $menu) !== false || strpos($menu, $name) !== false)) {
            return $item['allergens'] ?? [];
        }
    }
    // 特定原材料等28品目を考慮したマッピング（義務: 卵・乳・小麦・えび・かに・そば・落花生・くるみ / 推奨: 豚肉・牛肉・鶏肉・大豆・さけ・さば・ごま・アーモンド等）
    $map = [
        'とんかつ' => ['小麦', '卵', '豚肉'],
        'から揚げ' => ['小麦', '卵', '鶏肉'],
        '親子丼' => ['卵', '大豆', '鶏肉'],
        '牛丼' => ['大豆', '牛肉'],
        '天丼' => ['小麦', '卵', 'エビ'],
        'カツ丼' => ['小麦', '卵', '豚肉'],
        'カレー' => ['小麦', '大豆'],
        'ハヤシ' => ['小麦', '大豆', '乳', '牛肉'],
        '焼き魚' => ['さけ', 'さば'],
        '刺身' => ['さけ', 'さば'],
        '豚の生姜焼き' => ['小麦', '大豆', '豚肉'],
        '肉じゃが' => ['大豆', '牛肉'],
        '麻婆豆腐' => ['大豆', '卵'],
        'オムライス' => ['卵', '乳'],
        'グラタン' => ['小麦', '乳'],
        'スパゲッティ' => ['小麦', '卵'],
        '餃子' => ['小麦', '卵', '豚肉'],
        '春巻き' => ['小麦', '卵', 'エビ'],
        '天ぷら' => ['小麦', '卵', 'エビ'],
        'コロッケ' => ['小麦', '卵', '乳'],
        'メンチカツ' => ['小麦', '卵', '牛肉'],
        '魚のフライ' => ['小麦', '卵', 'さけ', 'さば'],
        'エビフライ' => ['小麦', '卵', 'エビ'],
        '酢豚' => ['小麦', '卵', '豚肉'],
        '八宝菜' => ['小麦', '卵', 'エビ'],
        'ラーメン' => ['小麦', '大豆', '卵'],
        'うどん' => ['小麦'],
        'そば' => ['そば', '小麦'],
        'キムチ丼' => ['卵', '大豆', '豚肉'],
        '五目丼' => ['卵', '大豆'],
        '中華丼' => ['小麦', '卵', 'エビ'],
    ];
    foreach ($map as $key => $allergens) {
        if (mb_strpos($name, $key) !== false) {
            return $allergens;
        }
    }
    return ['小麦', '卵'];
}

function syncAllergiesFromDailyMenu($allergiesFile, $dailyMenuFile) {
    $dailyMenus = readJson($dailyMenuFile);
    $allergiesData = readJson($allergiesFile);
    $allergies = isset($allergiesData['allergies']) && is_array($allergiesData['allergies']) ? $allergiesData['allergies'] : [];

    $baseAllergens = getBaseSetMealAllergens();
    $setMealEntries = [];

    foreach ($dailyMenus as $item) {
        $food = isset($item['food']) ? trim($item['food']) : '';
        if ($food === '') continue;
        $mainName = preg_replace('/定食$/', '', $food);
        if ($mainName === '') $mainName = $food;

        $mainAllergens = getMainDishAllergens($mainName, $allergies);
        $combined = array_values(array_unique(array_merge($baseAllergens, $mainAllergens)));
        sort($combined);

        $setMealName = (mb_strpos($food, '定食') !== false) ? $food : $food . '定食';
        $setMealEntries[$setMealName] = $combined;
    }

    $other = array_filter($allergies, function ($item) {
        $menu = $item['menu'] ?? '';
        if ($menu === '') return true;
        return mb_strlen($menu) < 2 || mb_substr($menu, -2) !== '定食';
    });
    $other = array_values($other);

    $newSetMealList = [];
    foreach ($setMealEntries as $menu => $allergens) {
        $newSetMealList[] = ['menu' => $menu, 'allergens' => $allergens];
    }

    $newAllergies = array_merge($other, $newSetMealList);
    $allergiesData['allergies'] = $newAllergies;
    writeJson($allergiesFile, $allergiesData);
    return ['ok' => true, 'synced' => count($newSetMealList)];
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $data = readJson($allergiesFile);
        if (!isset($data['allergies'])) {
            $data = ['allergies' => []];
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    case 'POST':
        $input = file_get_contents('php://input');
        $body = json_decode($input, true);
        if (!is_array($body)) {
            $body = [];
        }
        if (isset($body['action']) && $body['action'] === 'sync_from_daily_menu') {
            syncAllergiesFromDailyMenu($allergiesFile, $dailyMenuFile);
            echo json_encode(['ok' => true, 'message' => '定食のアレルギー情報を反映しました'], JSON_UNESCAPED_UNICODE);
            break;
        }
        if (isset($body['allergies']) && is_array($body['allergies'])) {
            $data = ['allergies' => $body['allergies']];
            writeJson($allergiesFile, $data);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode(['error' => 'Invalid request'], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
}
