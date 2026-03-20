<?php
// フォーマット（JSON/CSV）に応じて Content-Type を切り替えるため、
// ここでは JSON ヘッダを先に固定しない
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

// 月間レポートを生成
function generateMonthlyReport($year, $month) {
    $dataDir = __DIR__ . '/../data';
    // 月間レポートは sales-data.json を参照
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
        'totalPeople' => 0, // 受け取り（received=true）の人数
        'menuSales' => [],
        'dailySales' => [],
        'timeSlotSales' => [],
        'topMenu' => [],
        'averageDailyPeople' => 0,
        'busiestDay' => null,
        // 'busiestTimeSlot' はユーザー要望により表示から削除
    ];
    
    // 日別データを初期化
    $dailyData = [];

    // admin.html の「受け取り（received）」を来客数として集計するための判定
    $isReceived = function ($reservation) {
        if (!is_array($reservation)) return false;
        $received = $reservation['received'] ?? null;
        return $received === true || $received === 'true' || $received === 1 || $received === '1';
    };
    
    // 売上データを集計
    foreach ($monthSalesData as $date => $dayData) {
        $reservations = intval($dayData['reservations'] ?? 0);

        // reservationList がある場合は received=true の合計を来客数にする
        $hasReservationList = isset($dayData['reservationList']) && is_array($dayData['reservationList']);
        if ($hasReservationList) {
            $people = 0;
            $menuSales = [];
            foreach ($dayData['reservationList'] as $reservation) {
                if (!$isReceived($reservation)) continue;

                $p = intval($reservation['people'] ?? 1);
                $people += $p;

                $food = $reservation['food'] ?? '';
                if ($food) {
                    if (!isset($menuSales[$food])) $menuSales[$food] = 0;
                    $menuSales[$food] += $p;
                }
            }
        } else {
            // 古いデータ等で reservationList が無い場合のフォールバック
            $people = intval($dayData['people'] ?? 0);
            $menuSales = $dayData['menuSales'] ?? [];
        }
        
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
    
    // 最も忙しかった日を特定（来客数=受け取り人数が最多の日）
    $maxPeople = 0;
    foreach ($report['dailySales'] as $daily) {
        if ($daily['people'] > $maxPeople) {
            $maxPeople = $daily['people'];
            $report['busiestDay'] = $daily['date'];
        }
    }
    
    return $report;
}

// Excel形式のCSVを生成
function generateExcelReport($report) {
    // CSVセルの安全なエスケープ（Excelで列が崩れる/「#」になる原因の対策）
    $csvCell = function ($value) {
        $s = (string)($value ?? '');
        // 改行・ダブルクォート・カンマがある場合は引用符で囲む
        $needsQuote = preg_match('/[",\r\n]/u', $s) === 1;
        if (!$needsQuote) return $s;
        $escaped = str_replace('"', '""', $s);
        return '"' . $escaped . '"';
    };

    // 日付表記を「YYYY-MM-DD」→「M/D」に変換（例: 2026-03-04 => 3/4）
    $formatMonthDay = function ($dateStr) {
        $s = (string)($dateStr ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) === 1) {
            $mNum = intval($m[2]);
            $dNum = intval($m[3]);
            return $mNum . '/' . $dNum;
        }
        return $s;
    };

    // Excelのロケール差対策：最初に区切り文字を明示
    // （Shift_JISで返すのでBOMは付けない）
    $csv = "sep=,\n\n";
    // 年はファイル名等で混乱しやすいので月だけにする
    $csv .= "月間レポート - " . $report['month'] . "月\n\n";
    $csv .= "基本統計\n";
    $csv .= $csvCell('総営業日数') . ',' . $csvCell($report['totalDays']) . "\n";
    $csv .= $csvCell('総予約数') . ',' . $csvCell($report['totalReservations']) . "\n";
    $csv .= $csvCell('総来客数') . ',' . $csvCell($report['totalPeople']) . "\n";
    $csv .= $csvCell('1日平均来客数') . ',' . $csvCell($report['averageDailyPeople']) . "\n";
    $csv .= $csvCell('最も忙しかった日') . ',' . $csvCell($report['busiestDay']) . "\n";
    $csv .= "\n";

    $csv .= "メニュー別売上\n";
    $csv .= $csvCell('メニュー名') . ',' . $csvCell('売上数') . "\n";
    foreach ($report['menuSales'] as $menu => $quantity) {
        $csv .= $csvCell($menu) . ',' . $csvCell($quantity) . "\n";
    }

    $csv .= "\n時間帯別売上\n";
    $csv .= $csvCell('時間帯') . ',' . $csvCell('来客数') . "\n";
    foreach ($report['timeSlotSales'] as $time => $quantity) {
        $csv .= $csvCell($time) . ',' . $csvCell($quantity) . "\n";
    }

    // Excelでグラフ作りやすいように、まずは日付・予約数・来客数だけの表を出す
    $csv .= "\n日別（予約数 / 来客数）\n";
    $csv .= $csvCell('日付') . ',' . $csvCell('予約数') . ',' . $csvCell('来客数') . "\n";
    foreach ($report['dailySales'] as $daily) {
        $csv .= $csvCell($formatMonthDay($daily['date'])) . ',' .
            $csvCell($daily['reservations']) . ',' .
            $csvCell($daily['people']) . "\n";
    }

    // メニュー別売上の詳細（要約文字列）
    $csv .= "\n日別売上（メニュー別売上 要約）\n";
    $csv .= $csvCell('日付') . ',' . $csvCell('予約数') . ',' . $csvCell('来客数') . ',' . $csvCell('メニュー別売上') . "\n";
    foreach ($report['dailySales'] as $daily) {
        $menuSales = $daily['menuSales'] ?? [];
        $menuSalesText = '';
        if (!empty($menuSales)) {
            $menuItems = [];
            foreach ($menuSales as $menu => $quantity) {
                $menuItems[] = $menu . ':' . $quantity;
            }
            $menuSalesText = implode(' / ', $menuItems);
        } else {
            $menuSalesText = 'データなし';
        }

        $csv .= $csvCell($formatMonthDay($daily['date'])) . ',' .
            $csvCell($daily['reservations']) . ',' .
            $csvCell($daily['people']) . ',' .
            $csvCell($menuSalesText) . "\n";
    }

    // Excel互換のために改行をCRLF
    $csv = str_replace("\n", "\r\n", $csv);

    // ExcelがUTF-8として解釈できず文字化けするケースを避けるため Shift_JIS に変換
    // （mbstring拡張が前提。なければ元UTF-8のまま返す）
    if (function_exists('mb_convert_encoding')) {
        $csv = mb_convert_encoding($csv, 'SJIS-win', 'UTF-8');
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
        header('Content-Type: text/csv; charset=Shift_JIS');
        header('Content-Disposition: attachment; filename="monthly_report_' . $year . '_' . sprintf('%02d', $month) . '.csv"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache, max-age=0');
        echo generateExcelReport($report);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($report, JSON_UNESCAPED_UNICODE);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
}
?>
