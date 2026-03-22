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
                'gemini' => ['enabled' => false, 'api_key' => '', 'model' => 'gemini-1.5-flash', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta'],
                'timeout' => 120,
                'connect_timeout' => 15,
            ];
        }
    }
    return $config;
}

// Gemini APIを呼び出し
function callGeminiAPI($prompt, $apiConfig, $timeout = 120, $connectTimeout = 15) {
    $ch = curl_init();
    $model = $apiConfig['model'] ?? 'gemini-1.5-flash';
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

// 来客予測向け: Gemini API詳細呼び出し（成功/失敗理由を返す）
function callGeminiAPIWithStatus($prompt, $apiConfig, $timeout = 120, $connectTimeout = 15) {
    $ch = curl_init();
    $model = $apiConfig['model'] ?? 'gemini-1.5-flash';
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
        return ['ok' => false, 'text' => null, 'reason' => 'curl_error', 'httpCode' => $httpCode];
    }

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return ['ok' => true, 'text' => trim($text), 'reason' => null, 'httpCode' => $httpCode];
        }
        return ['ok' => false, 'text' => null, 'reason' => 'empty_response', 'httpCode' => $httpCode];
    }

    return ['ok' => false, 'text' => null, 'reason' => 'http_error', 'httpCode' => $httpCode];
}

// 来客予測レスポンスから予測人数を抽出
function extractAttendancePredictionNumber($text) {
    if (!is_string($text) || trim($text) === '') {
        return null;
    }

    if (preg_match('/予測来客数[:：]?\s*約?\s*(\d+)\s*人/u', $text, $m)) {
        return intval($m[1]);
    }
    if (preg_match('/来客数予測[^\n]*約?\s*(\d+)\s*人/u', $text, $m)) {
        return intval($m[1]);
    }
    if (preg_match('/約?\s*(\d+)\s*人/u', $text, $m)) {
        return intval($m[1]);
    }

    return null;
}

// 来客予測用: Gemini/OpenAIの両方を呼び出して結果を返す
function callAIForAttendancePrediction($messages, $config) {
    $timeout = $config['timeout'] ?? 120;
    $connectTimeout = $config['connect_timeout'] ?? 15;
    $responses = [];
    $statuses = [];

    // 1) Gemini
    if (($config['gemini']['enabled'] ?? false) && !empty($config['gemini']['api_key'])) {
        $flatPrompt = '';
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';
            if ($role === 'system') {
                $flatPrompt .= "[システム指示]\n{$content}\n\n";
            } else {
                $flatPrompt .= "[ユーザー]\n{$content}\n\n";
            }
        }
        $geminiResult = callGeminiAPIWithStatus($flatPrompt, $config['gemini'], $timeout, $connectTimeout);
        $statuses['gemini'] = [
            'attempted' => true,
            'ok' => $geminiResult['ok'],
            'httpCode' => $geminiResult['httpCode'],
            'reason' => $geminiResult['reason']
        ];
        if ($geminiResult['ok']) {
            $responses['gemini'] = $geminiResult['text'];
        }
    } else {
        $statuses['gemini'] = ['attempted' => false, 'ok' => false, 'httpCode' => null, 'reason' => 'not_configured'];
    }

    // 2) OpenAI
    if (($config['openai']['enabled'] ?? false) && !empty($config['openai']['api_key'])) {
        $openaiResult = callOpenAIAPIWithStatus($messages, $config['openai'], $timeout, $connectTimeout);
        $statuses['openai'] = [
            'attempted' => true,
            'ok' => $openaiResult['ok'],
            'httpCode' => $openaiResult['httpCode'],
            'reason' => $openaiResult['reason']
        ];
        if ($openaiResult['ok']) {
            $responses['openai'] = $openaiResult['text'];
        }
    } else {
        $statuses['openai'] = ['attempted' => false, 'ok' => false, 'httpCode' => null, 'reason' => 'not_configured'];
    }

    return ['responses' => $responses, 'statuses' => $statuses];
}

function readHolidays() {
    global $dataDir;
    $file = $dataDir . '/holidays.json';
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    if ($content === false || $content === '') return [];
    $json = json_decode($content, true);
    return is_array($json) ? $json : [];
}

function readEvents() {
    global $dataDir;
    $file = $dataDir . '/events.json';
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    if ($content === false || $content === '') return [];
    $json = json_decode($content, true);
    return is_array($json) ? $json : [];
}

// OpenAI互換の messages を Gemini 用の単一プロンプトにまとめる
function flattenMessagesForGeminiPrompt($messages) {
    $blocks = [];
    foreach ($messages as $m) {
        if (!is_array($m)) {
            continue;
        }
        $role = $m['role'] ?? 'user';
        $content = isset($m['content']) ? (string) $m['content'] : '';
        if ($content === '') {
            continue;
        }
        if ($role === 'system') {
            $blocks[] = "[システム指示]\n" . $content;
        } elseif ($role === 'assistant') {
            $blocks[] = "[アシスタント]\n" . $content;
        } else {
            $blocks[] = "[ユーザー]\n" . $content;
        }
    }
    return implode("\n\n", $blocks);
}

// 定食アドバイス用: OpenAI を試し、失敗時は Gemini（ai-analysis と同様のフォールバック）
function callMenuAdviceAI($messages, $config, $timeout = 120, $connectTimeout = 15, $temperature = null) {
    $openaiCfg = $config['openai'] ?? [];
    if (($openaiCfg['enabled'] ?? false) && !empty($openaiCfg['api_key'])) {
        $text = callOpenAIAPI($messages, $openaiCfg, $timeout, $connectTimeout, $temperature);
        if ($text !== false && trim($text) !== '') {
            return $text;
        }
    }
    $geminiCfg = $config['gemini'] ?? [];
    if (($geminiCfg['enabled'] ?? false) && !empty($geminiCfg['api_key'])) {
        $prompt = flattenMessagesForGeminiPrompt($messages);
        if ($prompt !== '') {
            $text = callGeminiAPI($prompt, $geminiCfg, $timeout, $connectTimeout);
            if ($text !== false && trim($text) !== '') {
                return $text;
            }
        }
    }
    return false;
}

function menuAdviceAIConfigured($config) {
    $o = $config['openai'] ?? [];
    $g = $config['gemini'] ?? [];
    $openaiOk = ($o['enabled'] ?? false) && !empty($o['api_key']);
    $geminiOk = ($g['enabled'] ?? false) && !empty($g['api_key']);
    return $openaiOk || $geminiOk;
}

// OpenAI APIを呼び出し（$temperature を null のままなら 0.7）
function callOpenAIAPI($messages, $apiConfig, $timeout = 120, $connectTimeout = 15, $temperature = null) {
    $ch = curl_init();
    $url = ($apiConfig['base_url'] ?? 'https://api.openai.com/v1') . '/chat/completions';
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    $requestBody = [
        'model' => $apiConfig['model'] ?? 'gpt-3.5-turbo',
        'messages' => $messages,
        'temperature' => $temperature !== null ? (float) $temperature : 0.7,
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

// 来客予測向け: OpenAI API詳細呼び出し（成功/失敗理由を返す）
function callOpenAIAPIWithStatus($messages, $apiConfig, $timeout = 120, $connectTimeout = 15) {
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
        return ['ok' => false, 'text' => null, 'reason' => 'curl_error', 'httpCode' => $httpCode];
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return ['ok' => true, 'text' => trim($text), 'reason' => null, 'httpCode' => $httpCode];
        }
        return ['ok' => false, 'text' => null, 'reason' => 'empty_response', 'httpCode' => $httpCode];
    }
    
    return ['ok' => false, 'text' => null, 'reason' => 'http_error', 'httpCode' => $httpCode];
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

// 今日の曜日を取得（引数で日付指定可能）
function getDayOfWeekForDate($dateStr) {
    $ts = strtotime($dateStr);
    if ($ts === false) {
        $ts = time();
    }
    $dayOfWeek = date('w', $ts);
    $days = ['日', '月', '火', '水', '木', '金', '土'];
    return $days[$dayOfWeek];
}

function getTodayDayOfWeek() {
    return getDayOfWeekForDate(date('Y-m-d'));
}

// 定食設定（日別メニュー）を読み込む
function readDailyMenu() {
    global $dataDir;
    $file = $dataDir . '/daily-menu.json';
    if (!file_exists($file)) {
        return [];
    }
    $content = @file_get_contents($file);
    if ($content === false || $content === '') {
        return [];
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function getHolidayEntryForDate($date) {
    foreach (readHolidays() as $h) {
        if (isset($h['date']) && $h['date'] === $date) {
            return $h;
        }
    }
    return null;
}

// 指定日付の定食が既に設定されているか取得
function getExistingFoodForDate($dailyMenus, $date) {
    foreach ($dailyMenus as $m) {
        if (isset($m['date']) && $m['date'] === $date) {
            return isset($m['food']) ? trim($m['food']) : '';
        }
    }
    return null;
}

// 指定月で、指定日以前の定食設定一覧を取得（献立の流れ用）
function getMonthlyMenusBeforeDate($dailyMenus, $date) {
    $dateTs = strtotime($date);
    $yearMonth = date('Y-m', $dateTs);
    $result = [];
    foreach ($dailyMenus as $m) {
        if (empty($m['date']) || empty($m['food'])) {
            continue;
        }
        $d = $m['date'];
        if (strpos($d, $yearMonth) !== 0) {
            continue;
        }
        if ($d <= $date) {
            $result[] = ['date' => $d, 'food' => trim($m['food'])];
        }
    }
    usort($result, function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    return $result;
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

// 既に設定されている定食メニューについて、作り方・アドバイスを生成
function generateAdviceForDish($date, $foodName, $dayOfWeek) {
    $config = getAIConfig();
    if (!menuAdviceAIConfigured($config)) {
        return ['error' => 'AI APIが設定されていません（OpenAI または Gemini を有効にしてください）'];
    }
    $cached = getCachedMenuAdvice($date);
    if ($cached !== null && isset($cached['isAlreadySet']) && $cached['isAlreadySet'] && ($cached['existingFood'] ?? '') === $foodName) {
        return $cached;
    }
    $dateStr = date('Y年m月d日', strtotime($date));
    $systemPrompt = "あなたは学校食堂の調理の専門家です。指定された定食メニューについて、作り方・調理のコツ・盛り付け・栄養面のアドバイスを、現場で使いやすい形でわかりやすく説明してください。";
    $userPrompt = "以下の日付・メニューについて、作り方とアドバイスを詳しく説明してください。\n\n";
    $userPrompt .= "日付：{$dateStr}（{$dayOfWeek}曜日）\n";
    $userPrompt .= "定食メニュー：{$foodName}\n\n";
    $userPrompt .= "以下の形式で回答してください：\n\n【{$foodName}】\n\n- [メニューの簡単な説明・特徴]\n\n【作り方】\n1. [手順1]\n2. [手順2]\n3. [手順3]\n...\n\n【ポイント・アドバイス】\n- [調理のコツ、盛り付け、注意点など]\n\n※ 学校食堂で実際に作れるように、具体的でわかりやすく説明してください。";
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ];
    $timeout = $config['timeout'] ?? 120;
    $connectTimeout = $config['connect_timeout'] ?? 15;
    $response = callMenuAdviceAI($messages, $config, $timeout, $connectTimeout, null);
    if ($response === false) {
        return ['error' => 'AI APIの呼び出しに失敗しました（OpenAI・Gemini ともに応答がありませんでした）'];
    }
    $result = [
        'advice' => $response,
        'date' => $date,
        'existingFood' => $foodName,
        'isAlreadySet' => true,
        'dayOfWeek' => $dayOfWeek
    ];
    saveCachedMenuAdvice($date, $result);
    return $result;
}

// 日付（と別案時の除外）からシードし、献立は守りつつ毎日ちがう方向性のヒントを返す
function getMenuVarietyHintForDate($date, $dayOfWeek, $excludeFoods = []) {
    $salt = '';
    if (is_array($excludeFoods) && count($excludeFoods) > 0) {
        $salt = '|x' . (string) crc32(implode("\0", $excludeFoods));
    }
    $seed = abs((int) crc32($date . '|' . $dayOfWeek . $salt));
    if ($dayOfWeek === '月') {
        $options = [
            '海鮮・魚介を使った丼',
            '豚肉・鶏肉・牛肉など肉を主役にした丼',
            '卵とじ・親子・他人丼のような卵系',
            '野菜・きのこ・豆腐を多めにした丼',
            'フライ・唐揚げ・カツをのせる丼',
            '煮込み・角煮・すき焼き風などじっくり味の丼',
        ];
    } else {
        $options = [
            '焼き魚・煮魚・ムニエルなど魚介を主菜に',
            '鶏肉（照焼・ソテー・蒸しなど、から揚げ以外も可）',
            '豚の生姜焼き・角煮・ロース焼き・回鍋肉風など',
            '牛肉・ハンバーグ・ミンチを使った主菜',
            '豆腐・厚揚げ・卵・納豆料理を主菜の中心に',
            '洋食系（チキン南蛮、メンチカツ、クリーム系など）の定食',
        ];
    }
    $n = count($options);
    $idx = $n > 0 ? ($seed % $n) : 0;
    $hint = $options[$idx];
    return "\n\n【バリエーションの指針（このリクエスト専用）】過去の献立データの分析・重複回避は必ず守ったうえで、今回は「{$hint}」という方向を優先して検討してください。"
        . "いつも同じ定番（例：から揚げ定食・とんかつ定食だけ）に寄せず、日替わりとして幅のあるメニューから1案に絞って提案してください。";
}

// その月の献立の流れを考慮して定食を提案し、作り方・アドバイスを生成
// $forceRegenerate: true のときキャッシュを使わず再生成（別メニュー提案用）
// $excludeFoods: これまでに出した提案メニュー名の配列（今回は提案しない）
function generateMenuSuggestionWithAdvice($date, $dayOfWeek, $monthMenus, $attendanceData = [], $forceRegenerate = false, $excludeFoods = []) {
    $config = getAIConfig();
    if (!menuAdviceAIConfigured($config)) {
        return ['error' => 'AI APIが設定されていません（OpenAI または Gemini を有効にしてください）'];
    }
    if (!$forceRegenerate) {
        $cached = getCachedMenuAdvice($date);
        if ($cached !== null && isset($cached['suggestedFood']) && empty($cached['isAlreadySet'])) {
            return $cached;
        }
    }
    $dateStr = date('Y年m月d日', strtotime($date));
    $budget = 400;
    $menuStructure = '';
    $specialNote = '';
    if ($dayOfWeek === '月') {
        $menuStructure = 'どんぶり＋味噌汁';
        $specialNote = "\n\n【重要】月曜日は必ずどんぶり（丼物）を提案してください。どんぶりとは、キムチ丼、牛丼、親子丼、天丼、カツ丼、中華丼、五目丼など、ご飯の上に具材を乗せた丼物のことです。";
    } else {
        $menuStructure = 'ごはん＋味噌汁＋主菜＋副菜';
        $specialNote = "\n\n【重要】火・水・木・金曜日は主菜だけではなく副菜も必ず考えて提案してください。主菜（肉・魚などメインのおかず）と副菜（野菜料理・煮物・サラダなど）の両方を具体的に決め、作り方・アドバイスに主菜と副菜の両方の内容を記載すること。";
    }
    $monthContext = '';
    if (!empty($monthMenus)) {
        $lines = [];
        foreach ($monthMenus as $m) {
            $d = $m['date'];
            $lines[] = date('j日', strtotime($d)) . ' ' . $m['food'];
        }
        $monthContext = "\n\n【その月（" . date('Y年n月', strtotime($date)) . "）の、この日より前に設定されている定食】\n" . implode("\n", $lines);
        $monthContext .= "\n\n上記の献立の流れ（重複しない・バリエーション・栄養バランス）を考慮して、この日にふさわしい定食を1つだけ提案してください。";
    } else {
        $monthContext = "\n\nこの月ではまだ定食が設定されていないか、この日が月初です。生徒に人気で栄養バランスの良い定食を提案してください。";
    }
    $attendanceInfo = '';
    if (!empty($attendanceData)) {
        $avgAttendance = array_sum($attendanceData) / count($attendanceData);
        $attendanceInfo = "\n\n過去の来客数データから、この日は約" . round($avgAttendance) . "人の来客が予測されます。";
    }
    $excludeNote = "\n\n【提案禁止】カレー定食・ラーメン定食・うどん定食は提案しないでください。ラーメン・カレー・うどんは既にメニューに単品としてあるため、定食として重複して提案しないこと。";
    $excludePrevious = '';
    if (!empty($excludeFoods) && is_array($excludeFoods)) {
        $lines = [];
        foreach (array_unique(array_map('trim', $excludeFoods)) as $ex) {
            if ($ex !== '') {
                $lines[] = '- ' . $ex;
            }
        }
        if (!empty($lines)) {
            $excludePrevious = "\n\n【今回の別案の条件】以下の定食は、すでにこの日の候補として検討済みなので今回は提案しないでください。献立の流れと重複しない、別の定食を1つだけ提案してください。\n" . implode("\n", $lines);
        }
    }
    $varietyHint = getMenuVarietyHintForDate($date, $dayOfWeek, $excludeFoods);
    $systemPrompt = "あなたは学校食堂のメニュー提案と調理の専門家です。予算とメニュー構成に基づき、献立の流れを考慮して1品だけ定食メニューを提案し、その作り方も詳しく説明してください。"
        . "火・水・木・金曜日は主菜だけでなく副菜も必ず考え、主菜と副菜の両方を具体的に提案すること。カレー定食・ラーメン定食・うどん定食は提案禁止。"
        . "条件を満たす範囲で、日付が変わるたびに同じ定食名に固まらないよう、メニューに多様性を持たせること。";
    $userPrompt = "日付：{$dateStr}（{$dayOfWeek}曜日）\n\n以下の条件で、この日の定食メニューを1つだけ提案し、作り方・アドバイスを記載してください。\n\n";
    $userPrompt .= "- 一人当たり予算：{$budget}円程度\n";
    $userPrompt .= "- メニュー構成：{$menuStructure}\n";
    $userPrompt .= "- 栄養バランスを考慮\n";
    $userPrompt .= $excludeNote;
    $userPrompt .= $monthContext;
    $userPrompt .= $attendanceInfo;
    $userPrompt .= $specialNote;
    $userPrompt .= $excludePrevious;
    $userPrompt .= $varietyHint;
    $formatNote = ($dayOfWeek !== '月') ? "\n\n※ 火～金曜は主菜と副菜の両方の内容（品名・作り方）を回答に含めてください。主菜だけでなく副菜も具体的に提案すること。" : '';
    $userPrompt .= "\n\n【回答形式】\n\n【おすすめ定食】\n[メニュー名（1品だけ、例：とんかつ定食、から揚げ定食）]\n\n【作り方】\n1. [手順1]\n2. [手順2]\n...\n\n【ポイント・アドバイス】\n- [調理のコツなど]\n\n予算：約[金額]円"
        . $formatNote
        . "\n\n※ 最初の見出し「【おすすめ定食】」の次の行に、提案するメニュー名だけを1行で書いてください（例：とんかつ定食）。そのメニュー名を後で定食設定に登録するため、具体的な名前を記載してください。";
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ];
    $menuTemp = isset($config['openai']['menu_suggestion_temperature']) ? (float) $config['openai']['menu_suggestion_temperature'] : 0.92;
    if ($menuTemp < 0.5) {
        $menuTemp = 0.5;
    }
    if ($menuTemp > 1.2) {
        $menuTemp = 1.2;
    }
    $timeout = $config['timeout'] ?? 120;
    $connectTimeout = $config['connect_timeout'] ?? 15;
    $response = callMenuAdviceAI($messages, $config, $timeout, $connectTimeout, $menuTemp);
    if ($response === false) {
        return ['error' => 'AI APIの呼び出しに失敗しました（OpenAI・Gemini ともに応答がありませんでした）'];
    }
    $suggestedFood = '';
    if (preg_match('/【おすすめ定食】\s*\n\s*([^\n【]+)/u', $response, $m)) {
        $suggestedFood = trim(preg_replace('/定食$/', '', $m[1]));
        if (mb_strlen($suggestedFood) > 0 && mb_strpos($m[1], '定食') !== false) {
            $suggestedFood = trim($m[1]);
        }
    }
    if ($suggestedFood === '') {
        if (preg_match('/^([^\n【]+)/u', $response, $m2)) {
            $suggestedFood = trim($m2[1]);
        }
        if ($suggestedFood === '') {
            $suggestedFood = '日替わり定食';
        }
    }
    $result = [
        'advice' => $response,
        'date' => $date,
        'suggestedFood' => $suggestedFood,
        'isAlreadySet' => false,
        'dayOfWeek' => $dayOfWeek
    ];
    saveCachedMenuAdvice($date, $result);
    return $result;
}

// 定食提案を生成（従来：今日のみ・新規提案。互換用）
function generateMenuAdvice($dayOfWeek, $attendanceData = [], $date = null) {
    $config = getAIConfig();
    
    if (!menuAdviceAIConfigured($config)) {
        return ['error' => 'AI APIが設定されていません（OpenAI または Gemini を有効にしてください）'];
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
        $specialNote = "\n\n【重要】火・水・木・金曜日は主菜だけではなく副菜も必ず考えて提案してください。主菜（メインのおかず）と副菜（野菜料理・煮物など）の両方を具体的に決め、作り方に主菜と副菜の両方の内容を記載すること。";
    }
    
    // 来客数予測の情報
    $attendanceInfo = '';
    if (!empty($attendanceData)) {
        $avgAttendance = array_sum($attendanceData) / count($attendanceData);
        $attendanceInfo = "\n\n過去の来客数データから、今日は約" . round($avgAttendance) . "人の来客が予測されます。";
    }
    
    $systemPrompt = "あなたは学校食堂のメニュー提案と調理の専門家です。火・水・木・金曜日は主菜だけでなく副菜も必ず考え、主菜と副菜の両方を具体的に提案してください。予算とメニュー構成に基づき、各メニューの作り方もわかりやすく説明してください。";
    
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
        $userPrompt .= "\n\n以下の形式で回答してください。主菜と副菜の両方を必ず具体的に提案すること。\n\n【今日のおすすめ定食】\n\n主菜：[メニュー名]\n- [簡単な説明]\n\n【作り方】\n1. [手順1]\n2. [手順2]\n3. [手順3]\n...\n\n副菜：[メニュー名]\n- [簡単な説明]\n\n【作り方】\n1. [手順1]\n2. [手順2]\n...\n\n味噌汁：[具材]\n- [簡単な説明]\n\n【作り方】\n1. [手順1]\n2. [手順2]\n...\n\n予算：約[金額]円\n\n※ 主菜だけでなく副菜も具体的に考え、作り方は学校食堂で実際に作れるように説明してください。";
    }
    
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ];
    
    $timeout = $config['timeout'] ?? 120;
    $connectTimeout = $config['connect_timeout'] ?? 15;
    $response = callMenuAdviceAI($messages, $config, $timeout, $connectTimeout, null);
    
    if ($response === false) {
        return ['error' => 'AI APIの呼び出しに失敗しました（OpenAI・Gemini ともに応答がありませんでした）'];
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
// $targetDate: Y-m-d（null または不正なら当日）
function predictAttendance($attendanceData = [], $attendanceDataWithDays = [], $targetDate = null) {
    $config = getAIConfig();

    $hasGemini = ($config['gemini']['enabled'] ?? false) && !empty($config['gemini']['api_key']);
    $hasOpenAI = ($config['openai']['enabled'] ?? false) && !empty($config['openai']['api_key']);
    if (!$hasGemini && !$hasOpenAI) {
        return ['error' => 'Gemini/OpenAI APIが設定されていません'];
    }
    
    if (empty($attendanceData)) {
        return ['prediction' => 'データ不足のため予測できません', 'confidence' => 'low'];
    }
    
    if ($targetDate === null || !is_string($targetDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        $targetDate = date('Y-m-d');
    }
    $predictTs = strtotime($targetDate);
    if ($predictTs === false) {
        $targetDate = date('Y-m-d');
        $predictTs = strtotime($targetDate);
    }
    $targetDayOfWeek = getDayOfWeekForDate($targetDate);
    $dateLabel = date('Y年n月j日', $predictTs);

    // 祝日・行事お知らせ情報（予測対象日）
    $holidays = readHolidays();
    $events = readEvents();
    $todayHoliday = null;
    foreach ($holidays as $h) {
        if (($h['date'] ?? '') === $targetDate) {
            $todayHoliday = $h;
            break;
        }
    }
    $todayEvents = [];
    foreach ($events as $e) {
        if (($e['date'] ?? '') === $targetDate) {
            $todayEvents[] = trim((string)($e['description'] ?? ''));
        }
    }
    
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
    
    $systemPrompt = "あなたは学校食堂の来客数予測の専門家です。過去データを多角的に分析し、指定された予測対象日の来客数を予測してください。"
        . "曜日傾向・最近の傾向・統計に加えて、祝日/休業日と校内の行事・お知らせの影響を必ず考慮してください。";
    
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
        
        // 予測対象曜日の統計を強調
        if (isset($dayOfWeekStats[$targetDayOfWeek])) {
            $todayStat = $dayOfWeekStats[$targetDayOfWeek];
            $userPrompt .= "\n【予測対象日（{$targetDayOfWeek}曜日）の過去データ】\n";
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
    $userPrompt .= "6. 祝日・休業日の影響\n";
    $userPrompt .= "7. 行事・お知らせ（学校イベント等）の影響\n\n";
    $userPrompt .= "予測対象日は {$dateLabel}（{$targetDayOfWeek}曜日）です。\n\n";
    if ($todayHoliday) {
        $reason = (string)($todayHoliday['reason'] ?? '休業日');
        $userPrompt .= "【予測対象日の祝日/休業情報】\n- {$targetDate} は休業扱い（理由: {$reason}）\n";
        $userPrompt .= "- 休業日であれば来客数は通常より大幅減（必要に応じて0〜ごく少数）を優先して見積もってください。\n\n";
    } else {
        $userPrompt .= "【予測対象日の祝日/休業情報】\n- {$targetDate} は通常営業日（休業登録なし）\n\n";
    }
    if (!empty($todayEvents)) {
        $userPrompt .= "【予測対象日の行事・お知らせ】\n";
        foreach ($todayEvents as $ev) {
            if ($ev !== '') {
                $userPrompt .= "- {$ev}\n";
            }
        }
        $userPrompt .= "行事内容に応じて来客増減を見積もってください。\n\n";
    } else {
        $userPrompt .= "【予測対象日の行事・お知らせ】\n- 特記事項なし\n\n";
    }
    $userPrompt .= "これらのデータを基に、多角的な分析を行い、{$dateLabel}の来客数を予測してください。\n\n";
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

    $aiCallResult = callAIForAttendancePrediction($messages, $config);
    $aiResponses = $aiCallResult['responses'] ?? [];
    $apiStatuses = $aiCallResult['statuses'] ?? [];

    if (empty($aiResponses)) {
        // AI APIが失敗した場合、曜日別統計があればそれを使用
        if (!empty($dayOfWeekStats) && isset($dayOfWeekStats[$targetDayOfWeek])) {
            $prediction = $dayOfWeekStats[$targetDayOfWeek]['avg'];
            return [
                'prediction' => $prediction,
                'confidence' => 'medium',
                'method' => 'day_of_week_statistical',
                'details' => "{$targetDayOfWeek}曜日の過去平均値（{$prediction}人）を基に予測しました。"
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

    $geminiResponse = $aiResponses['gemini'] ?? null;
    $openaiResponse = $aiResponses['openai'] ?? null;
    $geminiNumber = extractAttendancePredictionNumber($geminiResponse);
    $openaiNumber = extractAttendancePredictionNumber($openaiResponse);

    // 両APIが成功した場合は統合
    if ($geminiResponse !== null && $openaiResponse !== null) {
        $mergedPredictionNumber = null;
        if ($geminiNumber !== null && $openaiNumber !== null) {
            $mergedPredictionNumber = round(($geminiNumber + $openaiNumber) / 2);
        } elseif ($geminiNumber !== null) {
            $mergedPredictionNumber = $geminiNumber;
        } elseif ($openaiNumber !== null) {
            $mergedPredictionNumber = $openaiNumber;
        }

        $mergedText = "【統合来客数予測】\n";
        if ($mergedPredictionNumber !== null) {
            $mergedText .= "予測来客数：約{$mergedPredictionNumber}人（Gemini/OpenAI統合）\n\n";
        } else {
            $mergedText .= "予測来客数：両APIの文章分析結果を統合（数値抽出不可）\n\n";
        }
        $mergedText .= "【Geminiの分析】\n" . $geminiResponse . "\n\n";
        $mergedText .= "【OpenAIの分析】\n" . $openaiResponse;

        return [
            'prediction' => $mergedText,
            'confidence' => 'high',
            'method' => 'ai_dual_ensemble',
            'usedApi' => 'gemini+openai',
            'usedApis' => ['gemini', 'openai'],
            'apiStatuses' => $apiStatuses,
            'predictionNumber' => $mergedPredictionNumber,
            'individualPredictions' => [
                'gemini' => $geminiNumber,
                'openai' => $openaiNumber
            ]
        ];
    }

    // 片方のみ成功した場合は、その結果を返す
    if ($geminiResponse !== null) {
        return [
            'prediction' => $geminiResponse,
            'confidence' => 'high',
            'method' => 'ai_advanced',
            'usedApi' => 'gemini',
            'usedApis' => ['gemini'],
            'apiStatuses' => $apiStatuses,
            'predictionNumber' => $geminiNumber
        ];
    }

    return [
        'prediction' => $openaiResponse,
        'confidence' => 'high',
        'method' => 'ai_advanced',
        'usedApi' => 'openai',
        'usedApis' => ['openai'],
        'apiStatuses' => $apiStatuses,
        'predictionNumber' => $openaiNumber
    ];
}

// 来客数予測の数値を取得（簡易版：統計的な予測）
// $targetDate: Y-m-d（null または不正なら当日）
function getAttendancePredictionNumber($attendanceData = [], $attendanceDataWithDays = [], $targetDate = null) {
    if (empty($attendanceData)) {
        return null;
    }
    
    if ($targetDate === null || !is_string($targetDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        $targetDate = date('Y-m-d');
    }
    if (strtotime($targetDate) === false) {
        $targetDate = date('Y-m-d');
    }
    $targetDayOfWeek = getDayOfWeekForDate($targetDate);
    
    // 曜日別統計があればそれを使用
    if (!empty($attendanceDataWithDays)) {
        $dayOfWeekStats = calculateDayOfWeekStats($attendanceDataWithDays);
        if (isset($dayOfWeekStats[$targetDayOfWeek])) {
            return $dayOfWeekStats[$targetDayOfWeek]['avg'];
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
    
    if ($attendanceNumber >= 55) {
        return ['level' => 'very_crowded', 'text' => '非常に混雑しそう', 'color' => '#dc3545'];
    } else if ($attendanceNumber >= 40) {
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
        // 定食アドバイス（日付指定可能。指定がなければ今日）
        $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
        $holidayEntry = getHolidayEntryForDate($date);
        if ($holidayEntry !== null) {
            $reason = trim((string)($holidayEntry['reason'] ?? ''));
            if ($reason === '') {
                $reason = '休業日';
            }
            $ts = strtotime($date);
            $dateLabel = $ts !== false ? date('Y年n月j日', $ts) : $date;
            $message = $dateLabel . 'は休業日（' . $reason . '）のため、この日の定食アドバイスはご利用いただけません。';
            echo json_encode([
                'error' => $message,
                'message' => $message,
                'holiday' => true,
                'reason' => $reason,
                'date' => $date,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $dayOfWeek = getDayOfWeekForDate($date);
            $attendanceData = readAttendanceData();
            $dailyMenus = readDailyMenu();
            $existingFood = getExistingFoodForDate($dailyMenus, $date);
            $monthMenus = getMonthlyMenusBeforeDate($dailyMenus, $date);

            if ($existingFood !== null && $existingFood !== '') {
                // その日は既に定食が設定済み → そのメニューの作り方・アドバイスを生成
                $result = generateAdviceForDish($date, $existingFood, $dayOfWeek);
            } else {
                // 未設定 → その月の献立の流れを考慮して提案し、作り方・アドバイスを生成
                // alternate=1 で別案（キャッシュ無視）。exclude に JSON 配列で除外メニュー名を渡す
                $forceAlternate = isset($_GET['alternate']) && ($_GET['alternate'] === '1' || $_GET['alternate'] === 'true');
                $excludeFoods = [];
                if (!empty($_GET['exclude']) && is_string($_GET['exclude'])) {
                    $decoded = json_decode($_GET['exclude'], true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $x) {
                            if (is_string($x) && trim($x) !== '') {
                                $excludeFoods[] = trim($x);
                            }
                        }
                    }
                }
                $result = generateMenuSuggestionWithAdvice($date, $dayOfWeek, $monthMenus, $attendanceData, $forceAlternate, $excludeFoods);
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        }
    } elseif ($action === 'attendance') {
        // 来客数予測（date で Y-m-d 指定可。未指定は当日）
        $predictionDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
        $attendanceData = readAttendanceData();
        $attendanceDataWithDays = readAttendanceDataWithDays();
        
        $result = predictAttendance($attendanceData, $attendanceDataWithDays, $predictionDate);
        if (is_array($result)) {
            $result['date'] = $predictionDate;
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'prediction-number') {
        // 来客数予測の数値のみを返す（簡易版・date で対象日指定可）
        $predictionDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
        $attendanceData = readAttendanceData();
        $attendanceDataWithDays = readAttendanceDataWithDays();
        
        $predictionNumber = getAttendancePredictionNumber($attendanceData, $attendanceDataWithDays, $predictionDate);
        $congestion = getCongestionLevel($predictionNumber);
        
        echo json_encode([
            'prediction' => $predictionNumber,
            'congestion' => $congestion,
            'date' => $predictionDate
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
