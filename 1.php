<?php
/**
 * index.php
 *
 * תיאור: סקריפט זה מושך קובץ JSON ממערכת ימות המשיח ומגדיר משתמשים באופן אוטומטי.
 * כל ההגדרות הרגישות (טוקן, מסלולים, קבצים) נטענות מקובץ `.env` בלבד.
 *
 * צעדים עיקריים:
 * 1. טען תלות Dotenv וקרוא משתני סביבה.
 * 2. קבע אזור זמן, קובץ לוג.
 * 3. פונקציה logLine - רישום לוג להדפסה ולקובץ.
 * 4. פונקציה safeGet - בקשת HTTP בטוחה עם טיפול בשגיאות.
 * 5. פונקציה deleteFile - מחיקת הקובץ המקורי משרת YM בסיום.
 * 6. פונקציה setupNewUser - מבצעת 5 פעולות עבור כל משתמש חדש:
 *    א) הגדרת ivr2 ראשי
 *    ב) הגדרת ivr2:1 ל־routing_1800
 *    ג) העלאת קובץ TTS
 *    ד) העברת הקובץ ל‑WAV
 *    ה) העלאת WhiteList.ini עם מספר טלפון
 * 7. בלוק ראשי - שליפת נתונים, לולאה על רשומות, קריאה ל־setupNewUser, ולבסוף קריאה ל־deleteFile.
 *
 * כדי להפעיל:
 * - ודא שיש לך `.env` עם המשתנים:
 *   YM_API_BASE, YM_TOKEN, YM_FILE_PATH, YM_ROUTING_NUMBER, YM_ROUTING_1800, TIMEZONE
 * - התקן באמצעות `composer require vlucas/phpdotenv`
 * - הרץ: `php index.php`
 */

require __DIR__ . '/vendor/autoload.php';

// טען משתני סביבה
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// קבע אזור זמן ודף לוג מתוך ENV
date_default_timezone_set(getenv('TIMEZONE') ?: 'Asia/Jerusalem');
$logFile = __DIR__ . '/log.txt';

/**
 * רושם שורה ללוג
 * @param string $line - הטקסט לרישום
 */
function logLine(string $line): void {
    global $logFile;
    $time = date('Y-m-d H:i:s');
    $fullLine = "[$time] $line\n";
    echo $fullLine;                          // הדפס למסך
    file_put_contents($logFile, $fullLine, FILE_APPEND);
}

/**
 * שולף דף מה־URL ומטפל בשגיאות
 */
function safeGet(string $url): string {
    $response = @file_get_contents($url);
    if ($response === false) {
        throw new Exception("שגיאה ב-fetch של URL: $url");
    }
    return $response;
}

/**
 * מוחק קובץ YM שלם
 */
function deleteFile(string $token): void {
    // הרכב כתובת למחיקת הקובץ
    $deleteUrl = sprintf(
        "%s/api/FileAction?token=%s&what=%s&action=delete",
        getenv('YM_API_BASE'),
        urlencode($token),
        urlencode(getenv('YM_FILE_PATH'))
    );

    $resp = @file_get_contents($deleteUrl);
    $json = json_decode($resp, true);
    if (is_array($json) && strtoupper($json['responseStatus'] ?? '') === 'OK') {
        logLine("🗑️ מחיקת הקובץ הצליחה");
    } else {
        logLine("⚠️ מחיקת הקובץ נכשלה או לא ברורה: $resp");
    }
}

/**
 * מגדיר משתמש חדש ב־YM עם חמשת השלבים הנדרשים
 */
function setupNewUser(string $user, string $pass, string $phone): void {
    logLine("📦 התחלת הגדרות עבור $user (ט: $phone)");

    // שלב 1: הגדרת ivr2 ראשי
    $url1 = sprintf(
        "%s/api/UpdateExtension?token=%s&path=ivr2:&type=routing_yemot&routing_yemot_number=%s&white_list_error_goto=/1&white_list=yes",
        getenv('YM_API_BASE'),
        urlencode("{$user}:{$pass}"),
        urlencode(getenv('YM_ROUTING_NUMBER'))
    );
    $resp1 = safeGet($url1);
    $json1 = json_decode($resp1, true);
    if (!is_array($json1) || strtoupper($json1['responseStatus'] ?? '') !== 'OK') {
        throw new Exception("❌ UpdateExtension ivr2 נכשל: $resp1");
    }
    logLine("✅ ivr2 ראשי הוגדר בהצלחה");

    // שלב 2: הגדרת ivr2:1 ל‑routing_1800
    $url2 = sprintf(
        "%s/api/UpdateExtension?token=%s&path=ivr2:1&type=routing_1800&routing_1800=%s",
        getenv('YM_API_BASE'),
        urlencode("{$user}:{$pass}"),
        urlencode(getenv('YM_ROUTING_1800'))
    );
    $resp2 = safeGet($url2);
    $json2 = json_decode($resp2, true);
    if (!is_array($json2) || strtoupper($json2['responseStatus'] ?? '') !== 'OK') {
        throw new Exception("❌ UpdateExtension ivr2:1 נכשל: $resp2");
    }
    logLine("✅ ivr2:1 routing_1800 הוגדר בהצלחה");

    // שלבים 3-5: העלאה והעברה של קבצי TTS ו‑Whitelist
    $actions = [
        ['action'=>'upload','what'=>'ivr2:/M1102.tts','contents'=>' '],
        ['action'=>'move','what'=>'ivr2:/M1102.tts','target'=>'ivr2:/M1102.wav'],
        ['action'=>'upload','what'=>'ivr2:WhiteList.ini','contents'=>$phone],
    ];

    foreach ($actions as $step) {
        if ($step['action'] === 'upload') {
            $apiUrl = sprintf(
                "%s/api/UploadTextFile?token=%s&what=%s&contents=%s",
                getenv('YM_API_BASE'),
                urlencode("{$user}:{$pass}"),
                urlencode($step['what']),
                urlencode($step['contents'])
            );
        } else {
            $apiUrl = sprintf(
                "%s/api/FileAction?token=%s&what=%s&action=%s&target=%s",
                getenv('YM_API_BASE'),
                urlencode("{$user}:{$pass}"),
                urlencode($step['what']),
                $step['action'],
                urlencode($step['target'])
            );
        }

        $resp = safeGet($apiUrl);
        $json = json_decode($resp, true);
        if (!is_array($json) || strtoupper($json['responseStatus'] ?? '') !== 'OK') {
            throw new Exception("❌ {$step['action']} נכשל: $resp");
        }
        logLine("✅ שלב {$step['action']} עבור {$step['what']} הסתיים בהצלחה");
    }

    logLine("🎉 סיום הגדרות עבור $user\n");
}

// נקודת כניסה ראשית
logLine("🚀 התחלת שליפת נתונים מ‑YM");

$token = getenv('YM_TOKEN');
if (!$token) {
    logLine("❌ YM_TOKEN חסר. וודא שהגדרת ב־.env או ב־GitHub Secrets");
    exit(1);
}

try {
    // שלב שליפת JSON וקידוד
    $renderUrl = sprintf(
        "%s/api/RenderYMGRFile?token=%s&wath=%s&convertType=json&notLoadLang=0",
        getenv('YM_API_BASE'),
        urlencode($token),
        urlencode(getenv('YM_FILE_PATH'))
    );
    $response = safeGet($renderUrl);
    $json = json_decode($response, true);

    if (!is_array($json) || !isset($json['data'])) {
        throw new Exception("JSON לא תקין או חסר 'data'.");
    }

    $data = $json['data'];
    if (empty($data)) {
        logLine("ℹ️ לא נמצאו רשומות");
    } else {
        foreach ($data as $i => $entry) {
            $user  = $entry['P050'] ?? null;
            $pass  = $entry['P051'] ?? null;
            $phone = $entry['P052'] ?? null;

            if (!$user || !$pass || !$phone) {
                throw new Exception("❌ רשומה $i לא מלאה");
            }
            setupNewUser($user, $pass, $phone);
        }
    }
} catch (Exception $e) {
    logLine($e->getMessage());
} finally {
    deleteFile($token);
}
