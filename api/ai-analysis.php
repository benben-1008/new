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

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

function readJsonSafe($file) {
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    if ($content === false || $content === '') return [];
    $json = json_decode($content, true);
    return is_array($json) ? $json : [];
}

// AI設定を読み込む
function getAIConfig() {
    static $config = null;
    if ($config === null) {
        $configPath = __DIR__ . '/ollama-config.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
        } else {
            $config = [
                'openai' => ['enabled' => false, 'api_key' => '', 'model' => 'gpt-3.5-turbo', 'base_url' => 'https://api.openai.com/v1'],
                'gemini' => ['enabled' => false, 'api_key' => '', 'model' => 'gemini-pro'],
                'groq' => ['enabled' => false, 'api_key' => '', 'model' => 'llama-3.1-8b-instant'],
                'timeout' => 120,
                'connect_timeout' => 15,
            ];
        }
    }
    return $config;
}

// OpenAI APIを呼び出し
function callOpenAIAPI($messages, $apiConfig, $timeout, $connectTimeout) {
    $ch = curl_init();
    $url = ($apiConfig['base_url'] ?? 'https://api.openai.com/v1') . '/chat/completions';
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    $requestBody = [
        'model' => $apiConfig['model'] ?? 'gpt-3.5-turbo',
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 2000,
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiConfig['api_key']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("OpenAI API error: " . $error);
        return false;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }
    }
    
    error_log("OpenAI API failed: HTTP $httpCode");
    return false;
}

// Gemini APIを呼び出し
function callGeminiAPI($prompt, $apiConfig, $timeout, $connectTimeout) {
    $ch = curl_init();
    $model = $apiConfig['model'] ?? 'gemini-pro';
    $baseUrl = $apiConfig['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta';
    $url = $baseUrl . '/models/' . $model . ':generateContent?key=' . $apiConfig['api_key'];
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    $requestBody = [
        'contents' => [['parts' => [['text' => $prompt]]]]
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Gemini API error: " . $error);
        return false;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return trim($data['candidates'][0]['content']['parts'][0]['text']);
        }
    }
    
    error_log("Gemini API failed: HTTP $httpCode");
    return false;
}

// Groq APIを呼び出し
function callGroqAPI($messages, $apiConfig, $timeout, $connectTimeout) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, ($apiConfig['base_url'] ?? 'https://api.groq.com/openai/v1') . '/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => $apiConfig['model'] ?? 'llama-3.1-8b-instant',
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 2000,
    ], JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiConfig['api_key']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Groq API error: " . $error);
        return false;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }
    }
    
    error_log("Groq API failed: HTTP $httpCode");
    return false;
}

// AI分析を実行
function performAIAnalysis($year, $month) {
    $dataDir = __DIR__ . '/../data';
    
    // レビューデータを取得
    $reviews = readJsonSafe($dataDir . '/reviews.json');
    
    // 月間レポートを生成（関数を直接定義）
    $reservationsFile = $dataDir . '/reservations.json';
    $archiveFile = $dataDir . '/reservations-archive.json';
    $holidaysFile = $dataDir . '/holidays.json';
    
    // 予約データを読み込み（現在の予約 + アーカイブ）
    $currentReservations = readJsonSafe($reservationsFile);
    $archivedReservations = readJsonSafe($archiveFile);
    $allReservations = array_merge($archivedReservations, $currentReservations);
    
    // 休業日データを読み込み
    $allHolidays = readJsonSafe($holidaysFile);
    
    // 指定された月の日数を計算
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    
    // 指定された月の休業日を抽出（登録されている休業日 + 土日）
    $monthHolidays = [];
    foreach ($allHolidays as $holiday) {
        $holidayDate = $holiday['date'] ?? '';
        $holidayYear = intval(substr($holidayDate, 0, 4));
        $holidayMonth = intval(substr($holidayDate, 5, 2));
        
        if ($holidayYear === $year && $holidayMonth === $month) {
            $monthHolidays[] = $holidayDate;
        }
    }
    
    // 今日の日付を取得
    $today = new DateTime();
    $todayYear = intval($today->format('Y'));
    $todayMonth = intval($today->format('n'));
    $todayDay = intval($today->format('j'));
    
    // 土日を休業日として追加
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $timestamp = mktime(0, 0, 0, $month, $day, $year);
        $dayOfWeek = date('w', $timestamp); // 0=日曜日, 6=土曜日
        
        if (($dayOfWeek == 0 || $dayOfWeek == 6) && !in_array($dateStr, $monthHolidays)) {
            $monthHolidays[] = $dateStr;
        }
    }
    
    // 営業日数を計算（その日までのカレンダーで白のところの総合計）
    $totalDays = 0;
    
    // 指定された月が現在の月より未来の場合は0
    if ($year > $todayYear || ($year == $todayYear && $month > $todayMonth)) {
        $totalDays = 0;
    } else {
        // カウントする最終日を決定
        $endDay = $daysInMonth;
        if ($year == $todayYear && $month == $todayMonth) {
            // 現在の月の場合は今日まで
            $endDay = $todayDay;
        }
        
        // 1日から最終日まで、休業日でない日をカウント
        for ($day = 1; $day <= $endDay; $day++) {
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            // 休業日でない場合（カレンダーで白のところ）のみカウント
            if (!in_array($dateStr, $monthHolidays)) {
                $totalDays++;
            }
        }
    }
    
    // 指定された月の予約を抽出
    $monthReservations = [];
    foreach ($allReservations as $reservation) {
        $reservationDate = $reservation['date'] ?? '';
        $reservationYear = intval(substr($reservationDate, 0, 4));
        $reservationMonth = intval(substr($reservationDate, 5, 2));
        
        if ($reservationYear === $year && $reservationMonth === $month) {
            $monthReservations[] = $reservation;
        }
    }
    
    // レポートデータを初期化
    $report = [
        'year' => $year,
        'month' => $month,
        'totalDays' => $totalDays,
        'totalReservations' => 0,
        'totalPeople' => 0,
        'menuSales' => [],
        'dailySales' => [],
        'averageDailyPeople' => 0,
    ];
    
    // 日別データを初期化
    $dailyData = [];
    
    // 予約データを集計
    foreach ($monthReservations as $reservation) {
        $date = $reservation['date'] ?? '';
        $people = intval($reservation['people'] ?? 1);
        $food = $reservation['food'] ?? '';
        $verified = isset($reservation['verified']) && ($reservation['verified'] === true || $reservation['verified'] === 'true' || $reservation['verified'] === 1);
        
        if (!isset($dailyData[$date])) {
            $dailyData[$date] = [
                'date' => $date,
                'reservations' => 0,
                'people' => 0
            ];
        }
        
        $dailyData[$date]['reservations'] += $people;
        $report['totalReservations'] += $people;
        
        if ($verified) {
            $dailyData[$date]['people'] += $people;
            $report['totalPeople'] += $people;
            
            if ($food) {
                if (!isset($report['menuSales'][$food])) {
                    $report['menuSales'][$food] = 0;
                }
                $report['menuSales'][$food] += $people;
            }
        }
    }
    
    // 日別データを配列に変換
    ksort($dailyData);
    $report['dailySales'] = array_values($dailyData);
    
    // 平均値を計算
    if ($report['totalDays'] > 0) {
        $report['averageDailyPeople'] = round($report['totalPeople'] / $report['totalDays'], 1);
    }
    
    // 分析用のデータを準備
    $reviewsText = '';
    if (!empty($reviews)) {
        $reviewsText = "レビュー一覧:\n";
        foreach (array_slice($reviews, -20) as $review) { // 最新20件
            $name = $review['name'] ?? '匿名';
            $comment = $review['comment'] ?? '';
            $date = $review['date'] ?? '';
            $reviewsText .= "- {$name} ({$date}): {$comment}\n";
        }
    } else {
        $reviewsText = "レビューはまだありません。\n";
    }
    
    $menuSalesText = '';
    if (!empty($report['menuSales'])) {
        $menuSalesText = "メニュー別売上数:\n";
        arsort($report['menuSales']);
        foreach ($report['menuSales'] as $menu => $quantity) {
            $menuSalesText .= "- {$menu}: {$quantity}個\n";
        }
    } else {
        $menuSalesText = "メニュー別売上データはありません。\n";
    }
    
    $dailySalesText = '';
    if (!empty($report['dailySales'])) {
        $dailySalesText = "日別売上データ:\n";
        foreach ($report['dailySales'] as $daily) {
            $dailySalesText .= "- {$daily['date']}: 予約数 {$daily['reservations']}人、来客数 {$daily['people']}人\n";
        }
    }
    
    // AIプロンプトを作成
    $systemPrompt = "あなたは学校食堂の経営分析の専門家です。提供されたデータを分析して、食堂の改善点と良い点をわかりやすく説明してください。";
    
    $userPrompt = "以下のデータを分析して、食堂の改善点と良い点を具体的に教えてください。

{$reviewsText}

{$menuSalesText}

{$dailySalesText}

総営業日数: {$report['totalDays']}日
総予約数: {$report['totalReservations']}人
総来客数: {$report['totalPeople']}人
1日平均来客数: {$report['averageDailyPeople']}人

以下の形式で回答してください：

## 📊 分析結果

### ✅ 良い点
- [具体的な良い点を3-5個挙げてください]

### 🔧 改善点
- [具体的な改善点を3-5個挙げてください]

### 💡 推奨事項
- [改善のための具体的な推奨事項を3-5個挙げてください]

回答は日本語で、わかりやすく、具体的に書いてください。";

    $config = getAIConfig();
    $timeout = $config['timeout'] ?? 120;
    $connectTimeout = $config['connect_timeout'] ?? 15;
    
    // 有効なAPIを順番に試行
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ];
    
    // OpenAIを試行
    if (($config['openai']['enabled'] ?? false) && !empty($config['openai']['api_key'])) {
        $response = callOpenAIAPI($messages, $config['openai'], $timeout, $connectTimeout);
        if ($response !== false) {
            return ['analysis' => $response, 'api' => 'openai'];
        }
    }
    
    // Geminiを試行
    if (($config['gemini']['enabled'] ?? false) && !empty($config['gemini']['api_key'])) {
        $response = callGeminiAPI($userPrompt, $config['gemini'], $timeout, $connectTimeout);
        if ($response !== false) {
            return ['analysis' => $response, 'api' => 'gemini'];
        }
    }
    
    // Groqを試行
    if (($config['groq']['enabled'] ?? false) && !empty($config['groq']['api_key'])) {
        $response = callGroqAPI($messages, $config['groq'], $timeout, $connectTimeout);
        if ($response !== false) {
            return ['analysis' => $response, 'api' => 'groq'];
        }
    }
    
    return ['error' => 'AI APIが利用できません。設定を確認してください。'];
}

// API処理
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    
    $result = performAIAnalysis($year, $month);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
}
?>

