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
$salesFile = $dataDir . '/sales.json';

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

// 月間レポートを生成
function generateMonthlyReport($year, $month) {
    $dataDir = __DIR__ . '/../data';
    $salesDataFile = $dataDir . '/sales-data.json';
    $holidaysFile = $dataDir . '/holidays.json';
    
    // 売上データを読み込み（集計済みデータ）
    $salesData = readJsonSafe($salesDataFile);
    
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
        
        // 土日を休業日に追加（既に登録されていない場合のみ）
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
    
    // 指定された月の売上データを抽出
    $monthSalesData = [];
    foreach ($salesData as $date => $dayData) {
        $dateYear = intval(substr($date, 0, 4));
        $dateMonth = intval(substr($date, 5, 2));
        
        if ($dateYear === $year && $dateMonth === $month) {
            $monthSalesData[$date] = $dayData;
        }
    }
    
    // レポートデータを初期化
    $report = [
        'year' => $year,
        'month' => $month,
        'totalDays' => $totalDays,
        'totalReservations' => 0, // 予約した人数の合計
        'totalPeople' => 0, // 認証済み（verified=true）の人数
        'menuSales' => [],
        'dailySales' => [],
        'timeSlotSales' => [],
        'topMenu' => [],
        'averageDailyPeople' => 0,
        'busiestDay' => null,
        'busiestTimeSlot' => null
    ];
    
    // 日別データを初期化
    $dailyData = [];
    
    // 売上データを集計
    foreach ($monthSalesData as $date => $dayData) {
        $reservations = intval($dayData['reservations'] ?? 0);
        $people = intval($dayData['people'] ?? 0);
        $menuSales = $dayData['menuSales'] ?? [];
        
        // 日別データを設定
        $dailyData[$date] = [
            'date' => $date,
            'reservations' => $reservations,
            'people' => $people,
            'menuSales' => $menuSales
        ];
        
        // 合計を加算
        $report['totalReservations'] += $reservations;
        $report['totalPeople'] += $people;
        
        // メニュー別売上を集計
        foreach ($menuSales as $menu => $quantity) {
            if (!isset($report['menuSales'][$menu])) {
                $report['menuSales'][$menu] = 0;
            }
            $report['menuSales'][$menu] += $quantity;
        }
    }
    
    // 日別データを配列に変換（日付順にソート）
    ksort($dailyData);
    $report['dailySales'] = array_values($dailyData);
    
    // 平均値を計算
    if ($report['totalDays'] > 0) {
        $report['averageDailyPeople'] = round($report['totalPeople'] / $report['totalDays'], 1);
    }
    
    // トップメニューを計算
    arsort($report['menuSales']);
    $report['topMenu'] = array_slice($report['menuSales'], 0, 5, true);
    
    // 最も忙しかった日を特定（認証済み人数が最多の日）
    $maxPeople = 0;
    foreach ($report['dailySales'] as $daily) {
        if ($daily['people'] > $maxPeople) {
            $maxPeople = $daily['people'];
            $report['busiestDay'] = $daily['date'];
        }
    }
    
    // 最も忙しかった時間帯を特定
    $maxTimeSlot = 0;
    foreach ($report['timeSlotSales'] as $time => $quantity) {
        if ($quantity > $maxTimeSlot) {
            $maxTimeSlot = $quantity;
            $report['busiestTimeSlot'] = $time;
        }
    }
    
    return $report;
}

// Excel形式のCSVを生成
function generateExcelReport($report) {
    $csv = "月間レポート - {$report['year']}年{$report['month']}月\n\n";
    $csv .= "基本統計\n";
    $csv .= "総営業日数,{$report['totalDays']}\n";
    $csv .= "総予約数,{$report['totalReservations']}\n";
    $csv .= "総来客数,{$report['totalPeople']}\n";
    $csv .= "1日平均来客数,{$report['averageDailyPeople']}\n";
    $csv .= "最も忙しかった日,{$report['busiestDay']}\n";
    $csv .= "最も忙しかった時間帯,{$report['busiestTimeSlot']}\n\n";
    
    $csv .= "メニュー別売上\n";
    $csv .= "メニュー名,売上数\n";
    foreach ($report['menuSales'] as $menu => $quantity) {
        $csv .= "{$menu},{$quantity}\n";
    }
    
    $csv .= "\n時間帯別売上\n";
    $csv .= "時間帯,来客数\n";
    foreach ($report['timeSlotSales'] as $time => $quantity) {
        $csv .= "{$time},{$quantity}\n";
    }
    
    $csv .= "\n日別売上\n";
    $csv .= "日付,予約数,来客数,メニュー別売上\n";
    foreach ($report['dailySales'] as $daily) {
        $menuSales = $daily['menuSales'] ?? [];
        $menuSalesText = '';
        if (!empty($menuSales)) {
            $menuItems = [];
            foreach ($menuSales as $menu => $quantity) {
                $menuItems[] = "{$menu}:{$quantity}";
            }
            $menuSalesText = implode(' / ', $menuItems);
        } else {
            $menuSalesText = 'データなし';
        }
        $csv .= "{$daily['date']},{$daily['reservations']},{$daily['people']},\"{$menuSalesText}\"\n";
    }
    
    return $csv;
}

// API処理
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $format = $_GET['format'] ?? 'json';
    
    $report = generateMonthlyReport($year, $month);
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="monthly_report_' . $year . '_' . sprintf('%02d', $month) . '.csv"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache, max-age=0');
        echo generateExcelReport($report);
    } else {
        echo json_encode($report, JSON_UNESCAPED_UNICODE);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
}
?>
