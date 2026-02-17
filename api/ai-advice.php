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
                'timeout' => 120,
                'connect_timeout' => 15,
            ];
        }
    }
    return $config;
}

// OpenAI APIを呼び出し
function callOpenAIAPI($messages, $apiConfig, $timeout = 120, $connectTimeout = 15) {
    $ch = curl_init();
    $url = $apiConfig['base_url'] . '/chat/completions';
    
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

// 来客数データを読み込む
function readAttendanceData() {
    global $dataDir;
    $jsonPath = $dataDir . '/attendance-data.json';
    if (file_exists($jsonPath)) {
        $content = file_get_contents($jsonPath);
        $data = json_decode($content, true);
        if (is_array($data) && isset($data['attendance'])) {
            return $data['attendance'];
        }
    }
    return [];
}

// 来客数データ（曜日情報付き）を読み込む
function readAttendanceDataWithDays() {
    global $dataDir;
    $jsonPath = $dataDir . '/attendance-data.json';
    if (file_exists($jsonPath)) {
        $content = file_get_contents($jsonPath);
        $data = json_decode($content, true);
        if (is_array($data) && isset($data['attendanceWithDays'])) {
            return $data['attendanceWithDays'];
        }
    }
    return [];
}

// 今日の曜日を取得
function getTodayDayOfWeek() {
    $dayOfWeek = date('w'); // 0=日曜日, 6=土曜日
    $days = ['日', '月', '火', '水', '木', '金', '土'];
    return $days[$dayOfWeek];
}

// 定食提案をキャッシュから読み込む
function getCachedMenuAdvice($date) {
    global $dataDir;
    $cacheFile = $dataDir . '/menu-advice-cache.json';
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cache) && isset($cache[$date])) {
            return $cache[$date];
        }
    }
    return null;
}

// 定食提案をキャッシュに保存
function saveCachedMenuAdvice($date, $advice) {
    global $dataDir;
    $cacheFile = $dataDir . '/menu-advice-cache.json';
    $cache = [];
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if (!is_array($cache)) {
            $cache = [];
        }
    }
    $cache[$date] = $advice;
    file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// 定食提案を生成
function generateMenuAdvice($dayOfWeek, $attendanceData = [], $date = null) {
    $config = getAIConfig();
    
    if (!($config['openai']['enabled'] ?? false) || empty($config['openai']['api_key'])) {
        return ['error' => 'OpenAI APIが設定されていません'];
    }
    
    // 日付を取得（指定がない場合は今日）
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    // キャッシュから読み込む（同じ日の提案は再利用）
    $cached = getCachedMenuAdvice($date);
    if ($cached !== null) {
        return $cached;
    }
    
    $dateStr = date('Y年m月d日', strtotime($date));
    
    // 予算とメニュー構成を設定
    $budget = 400;
    $menuStructure = '';
    $specialNote = '';
    if ($dayOfWeek === '月') {
        $menuStructure = 'どんぶり＋味噌汁';
        $specialNote = "\n\n【重要】月曜日は必ずどんぶり（丼物）を提案してください。どんぶりとは、キムチ丼、牛丼、親子丼、天丼、カツ丼、親子丼、中華丼、五目丼、鰻丼、鉄火丼、海鮮丼など、ご飯の上に具材を乗せた丼物のことです。";
    } else {
        $menuStructure = 'ごはん＋味噌汁＋主菜＋副菜';
    }
    
    // 来客数予測の情報
    $attendanceInfo = '';
    if (!empty($attendanceData)) {
        $avgAttendance = array_sum($attendanceData) / count($attendanceData);
        $attendanceInfo = "\n\n過去の来客数データから、今日は約" . round($avgAttendance) . "人の来客が予測されます。";
    }
    
    $systemPrompt = "あなたは学校食堂のメニュー提案と調理の専門家です。予算とメニュー構成に基づいて、栄養バランスが良く、生徒に人気のある定食メニューを提案してください。毎日異なるメニューを提案し、バリエーションを持たせてください。また、各メニューの作り方もわかりやすく説明してください。";
    
    $userPrompt = "今日は{$dateStr}（{$dayOfWeek}曜日）です。\n\n以下の条件で定食メニューを提案してください：\n\n";
    $userPrompt .= "- 一人当たり予算：{$budget}円程度\n";
    $userPrompt .= "- メニュー構成：{$menuStructure}\n";
    $userPrompt .= "- 栄養バランスを考慮\n";
    $userPrompt .= "- 生徒に人気のあるメニュー\n";
    $userPrompt .= "- 具体的なメニュー名と簡単な説明を提供\n";
    $userPrompt .= "- 今日の日付（{$dateStr}）を考慮して、この日特有のメニューを提案してください\n";
    $userPrompt .= "- **重要：各メニューの作り方も詳しく、わかりやすく説明してください**\n";
    $userPrompt .= $specialNote;
    $userPrompt .= $attendanceInfo;
    
    if ($dayOfWeek === '月') {
        $userPrompt .= "\n\n以下の形式で回答してください：\n\n【今日のおすすめ定食】\n\nどんぶり：[どんぶりメニュー名（例：キムチ丼、牛丼など）]\n- [簡単な説明]\n\n【作り方】\n1. [手順1]\n2. [手順2]\n3. [手順3]\n...\n\n味噌汁：[具材]\n- [簡単な説明]\n\n【作り方】\n1. [手順1]\n2. [手順2]\n...\n\n予算：約[金額]円\n\n※ 作り方は、学校食堂で実際に作れるように、具体的でわかりやすく、手順を明確に説明してください。";
    } else {
        $userPrompt .= "\n\n以下の形式で回答してください：\n\n【今日のおすすめ定食】\n\n主菜：[メニュー名]\n- [簡単な説明]\n\n【作り方】\n1. [手順1]\n2. [手順2]\n3. [手順3]\n...\n\n副菜：[メニュー名]\n- [簡単な説明]\n\n【作り方】\n1. [手順1]\n2. [手順2]\n...\n\n味噌汁：[具材]\n- [簡単な説明]\n\n【作り方】\n1. [手順1]\n2. [手順2]\n...\n\n予算：約[金額]円\n\n※ 作り方は、学校食堂で実際に作れるように、具体的でわかりやすく、手順を明確に説明してください。";
    }
    
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ];
    
    $response = callOpenAIAPI($messages, $config['openai'], $config['timeout'] ?? 120, $config['connect_timeout'] ?? 15);
    
    if ($response === false) {
        return ['error' => 'AI APIの呼び出しに失敗しました'];
    }
    
    $result = ['advice' => $response, 'dayOfWeek' => $dayOfWeek, 'date' => $date];
    
    // キャッシュに保存
    saveCachedMenuAdvice($date, $result);
    
    return $result;
}

// 曜日別の統計を計算
function calculateDayOfWeekStats($attendanceDataWithDays) {
    $stats = [];
    $days = ['日', '月', '火', '水', '木', '金', '土'];
    
    foreach ($days as $day) {
        $dayData = [];
        foreach ($attendanceDataWithDays as $item) {
            if (isset($item['day']) && $item['day'] === $day) {
                $dayData[] = $item['attendance'];
            }
        }
        
        if (!empty($dayData)) {
            $stats[$day] = [
                'count' => count($dayData),
                'avg' => round(array_sum($dayData) / count($dayData)),
                'max' => max($dayData),
                'min' => min($dayData),
                'total' => array_sum($dayData)
            ];
        }
    }
    
    return $stats;
}

// 来客数予測を生成（改善版：曜日別分析を含む）
function predictAttendance($attendanceData = [], $attendanceDataWithDays = []) {
    $config = getAIConfig();
    
    if (!($config['openai']['enabled'] ?? false) || empty($config['openai']['api_key'])) {
        return ['error' => 'OpenAI APIが設定されていません'];
    }
    
    if (empty($attendanceData)) {
        return ['prediction' => 'データ不足のため予測できません', 'confidence' => 'low'];
    }
    
    $todayDayOfWeek = getTodayDayOfWeek();
    
    // 全体統計
    $avgAttendance = array_sum($attendanceData) / count($attendanceData);
    $maxAttendance = max($attendanceData);
    $minAttendance = min($attendanceData);
    $medianAttendance = 0;
    $sortedData = $attendanceData;
    sort($sortedData);
    $count = count($sortedData);
    if ($count > 0) {
        $medianAttendance = $count % 2 === 0 
            ? ($sortedData[$count/2 - 1] + $sortedData[$count/2]) / 2 
            : $sortedData[($count-1)/2];
    }
    
    // 曜日別統計
    $dayOfWeekStats = [];
    if (!empty($attendanceDataWithDays)) {
        $dayOfWeekStats = calculateDayOfWeekStats($attendanceDataWithDays);
    }
    
    // 最近の傾向（直近7日分の平均）
    $recentAvg = 0;
    if (count($attendanceData) >= 7) {
        $recentData = array_slice($attendanceData, -7);
        $recentAvg = round(array_sum($recentData) / count($recentData));
    }
    
    $systemPrompt = "あなたは来客数予測の専門家です。過去のデータを多角的に分析して、今日の来客数を正確に予測してください。曜日別の傾向、最近の傾向、統計的な分析を総合的に考慮してください。";
    
    $userPrompt = "【過去の来客数データ - 全体統計】\n";
    $userPrompt .= "- 平均：約" . round($avgAttendance) . "人\n";
    $userPrompt .= "- 中央値：約" . round($medianAttendance) . "人\n";
    $userPrompt .= "- 最大：{$maxAttendance}人\n";
    $userPrompt .= "- 最小：{$minAttendance}人\n";
    $userPrompt .= "- データ数：" . count($attendanceData) . "件\n";
    if ($recentAvg > 0) {
        $userPrompt .= "- 直近7日平均：約{$recentAvg}人\n";
    }
    
    // 曜日別統計を追加
    if (!empty($dayOfWeekStats)) {
        $userPrompt .= "\n【曜日別統計】\n";
        foreach ($dayOfWeekStats as $day => $stat) {
            $userPrompt .= "- {$day}曜日：平均{$stat['avg']}人（最大{$stat['max']}人、最小{$stat['min']}人、データ数{$stat['count']}件）\n";
        }
        
        // 今日の曜日の統計を強調
        if (isset($dayOfWeekStats[$todayDayOfWeek])) {
            $todayStat = $dayOfWeekStats[$todayDayOfWeek];
            $userPrompt .= "\n【今日（{$todayDayOfWeek}曜日）の過去データ】\n";
            $userPrompt .= "- 平均：{$todayStat['avg']}人\n";
            $userPrompt .= "- 最大：{$todayStat['max']}人\n";
            $userPrompt .= "- 最小：{$todayStat['min']}人\n";
            $userPrompt .= "- データ数：{$todayStat['count']}件\n";
        }
    }
    
    $userPrompt .= "\n【予測の観点】\n";
    $userPrompt .= "以下の観点から総合的に分析して予測してください：\n";
    $userPrompt .= "1. 曜日別の傾向（同じ曜日の過去データ）\n";
    $userPrompt .= "2. 最近の傾向（直近の来客数）\n";
    $userPrompt .= "3. 統計的な分析（平均、中央値、最大、最小）\n";
    $userPrompt .= "4. 季節や時期による変動\n";
    $userPrompt .= "5. データの信頼性（データ数が多いほど信頼性が高い）\n\n";
    $userPrompt .= "今日は{$todayDayOfWeek}曜日です。\n\n";
    $userPrompt .= "これらのデータを基に、多角的な分析を行い、今日の来客数を予測してください。\n\n";
    $userPrompt .= "以下の形式で回答してください：\n\n【来客数予測】\n\n予測来客数：約[人数]人\n\n【分析根拠】\n";
    $userPrompt .= "1. 曜日別分析：[同じ曜日の傾向]\n";
    $userPrompt .= "2. 最近の傾向：[直近の来客数の傾向]\n";
    $userPrompt .= "3. 統計的分析：[平均値、中央値などの統計]\n";
    $userPrompt .= "4. その他の要因：[季節、時期などの要因]\n\n";
    $userPrompt .= "【信頼度】\nデータ数と分析の質に基づいた信頼度：[高/中/低]";
    
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ];
    
    $response = callOpenAIAPI($messages, $config['openai'], $config['timeout'] ?? 120, $config['connect_timeout'] ?? 15);
    
    if ($response === false) {
        // AI APIが失敗した場合、曜日別統計があればそれを使用
        if (!empty($dayOfWeekStats) && isset($dayOfWeekStats[$todayDayOfWeek])) {
            $prediction = $dayOfWeekStats[$todayDayOfWeek]['avg'];
            return [
                'prediction' => $prediction,
                'confidence' => 'medium',
                'method' => 'day_of_week_statistical',
                'details' => "{$todayDayOfWeek}曜日の過去平均値（{$prediction}人）を基に予測しました。"
            ];
        }
        
        // 曜日別統計がない場合は全体平均を使用
        $prediction = round($avgAttendance);
        return [
            'prediction' => $prediction,
            'confidence' => 'medium',
            'method' => 'statistical',
            'details' => "過去の平均値（{$prediction}人）を基に予測しました。"
        ];
    }
    
    return ['prediction' => $response, 'confidence' => 'high', 'method' => 'ai_advanced'];
}

// 来客数予測の数値を取得（簡易版：統計的な予測）
function getAttendancePredictionNumber($attendanceData = [], $attendanceDataWithDays = []) {
    if (empty($attendanceData)) {
        return null;
    }
    
    $todayDayOfWeek = getTodayDayOfWeek();
    
    // 曜日別統計があればそれを使用
    if (!empty($attendanceDataWithDays)) {
        $dayOfWeekStats = calculateDayOfWeekStats($attendanceDataWithDays);
        if (isset($dayOfWeekStats[$todayDayOfWeek])) {
            return $dayOfWeekStats[$todayDayOfWeek]['avg'];
        }
    }
    
    // 曜日別統計がない場合は全体平均を使用
    $avgAttendance = array_sum($attendanceData) / count($attendanceData);
    return round($avgAttendance);
}

// 混雑度を判定（来客数から）
function getCongestionLevel($attendanceNumber) {
    if ($attendanceNumber === null) {
        return ['level' => 'unknown', 'text' => 'データ不足', 'color' => '#6c757d'];
    }
    
    if ($attendanceNumber >= 45) {
        return ['level' => 'very_crowded', 'text' => '非常に混雑しそう', 'color' => '#dc3545'];
    } else if ($attendanceNumber >= 30) {
        return ['level' => 'crowded', 'text' => '混雑しそう', 'color' => '#fd7e14'];
    } else if ($attendanceNumber >= 15) {
        return ['level' => 'moderate', 'text' => 'やや混雑しそう', 'color' => '#ffc107'];
    } else {
        return ['level' => 'quiet', 'text' => '空いていそう', 'color' => '#28a745'];
    }
}

// メイン処理
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'menu';
    
    if ($action === 'menu') {
        // 定食提案
        $dayOfWeek = getTodayDayOfWeek();
        $attendanceData = readAttendanceData();
        $date = date('Y-m-d'); // 今日の日付
        
        $result = generateMenuAdvice($dayOfWeek, $attendanceData, $date);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'attendance') {
        // 来客数予測
        $attendanceData = readAttendanceData();
        $attendanceDataWithDays = readAttendanceDataWithDays();
        
        $result = predictAttendance($attendanceData, $attendanceDataWithDays);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'prediction-number') {
        // 来客数予測の数値のみを返す（簡易版）
        $attendanceData = readAttendanceData();
        $attendanceDataWithDays = readAttendanceDataWithDays();
        
        $predictionNumber = getAttendancePredictionNumber($attendanceData, $attendanceDataWithDays);
        $congestion = getCongestionLevel($predictionNumber);
        
        echo json_encode([
            'prediction' => $predictionNumber,
            'congestion' => $congestion
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
}
?>
