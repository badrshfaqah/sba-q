<?php
/**
 * قارئ ملفات Excel (xlsx) و CSV بدون مكتبات خارجية
 * يعتمد على ZipArchive + SimpleXML المدمجة في PHP
 */
if (!defined('SBA')) exit;

/**
 * قراءة أول ورقة من ملف xlsx أو ملف csv
 * ترجع مصفوفة صفوف، كل صف مصفوفة قيم نصية
 */
function spreadsheet_read(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return csv_read_rows($path);
    }
    if ($ext === 'xlsx') {
        return xlsx_read_rows($path);
    }
    throw new RuntimeException('صيغة الملف غير مدعومة. المسموح: xlsx أو csv');
}

function csv_read_rows(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException('تعذر فتح الملف');
    // إزالة BOM إن وجد
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $rows[] = array_map(fn($c) => trim((string)$c), $row);
    }
    fclose($fh);
    return $rows;
}

function xlsx_read_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('امتداد Zip غير مفعل على السيرفر');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('تعذر فتح ملف Excel — تأكد أنه بصيغة xlsx صحيحة');
    }

    // النصوص المشتركة
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    // نص منسق مقسم إلى أجزاء
                    $txt = '';
                    foreach ($si->r as $r) $txt .= (string)$r->t;
                    $shared[] = $txt;
                }
            }
        }
    }

    // تحديد ملف الورقة الأولى
    $sheetFile = 'xl/worksheets/sheet1.xml';
    $wbXml   = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml !== false && $relsXml !== false) {
        $wb   = simplexml_load_string($wbXml);
        $rels = simplexml_load_string($relsXml);
        if ($wb !== false && $rels !== false && isset($wb->sheets->sheet[0])) {
            $first = $wb->sheets->sheet[0];
            $rid   = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = (string)$rel['Target'];
                    $sheetFile = 'xl/' . ltrim(str_replace('../', '', $target), '/');
                    break;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetFile);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('تعذر قراءة ورقة البيانات من الملف');
    }

    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('محتوى الملف غير صالح');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];                      // مثل B3
            $col = xlsx_col_index(preg_replace('/\d+/', '', $ref));
            $type = (string)$c['t'];
            $val  = '';
            if ($type === 's') {
                $idx = (int)$c->v;
                $val = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)$c->is->t;
            } elseif (isset($c->v)) {
                $val = (string)$c->v;
            }
            $cells[$col] = trim($val);
        }
        if (!$cells) continue;
        // ملء الفراغات بين الأعمدة
        $max = max(array_keys($cells));
        $line = [];
        for ($i = 0; $i <= $max; $i++) $line[$i] = $cells[$i] ?? '';
        $rows[] = $line;
    }
    return $rows;
}

/** تحويل حرف العمود (A,B,..,AA) إلى رقم يبدأ من صفر */
function xlsx_col_index(string $letters): int
{
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/**
 * تحويل قيمة خلية الوقت إلى صيغة H:i
 * تدعم: "14:30" أو "2:30 PM" أو رقم إكسل التسلسلي (كسر من اليوم)
 */
function cell_to_time(string $val): ?string
{
    $val = trim($val);
    if ($val === '') return null;

    // رقم إكسل التسلسلي
    if (is_numeric($val)) {
        $f = (float)$val;
        $frac = $f - floor($f);            // جزء الوقت من اليوم
        $secs = (int)round($frac * 86400);
        return sprintf('%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60));
    }

    // نص وقت
    $ts = strtotime($val);
    if ($ts !== false) {
        return date('H:i', $ts);
    }
    // صيغة "14.30"
    if (preg_match('/^(\d{1,2})[.:](\d{2})$/', $val, $m)) {
        $h = (int)$m[1]; $i = (int)$m[2];
        if ($h < 24 && $i < 60) return sprintf('%02d:%02d', $h, $i);
    }
    return null;
}

/**
 * تحويل قيمة خلية الالتزام إلى 1 / 0 / null (فارغ = تجاهل)
 */
function cell_to_compliance(string $val): ?int
{
    $val = trim(mb_strtolower($val));
    if ($val === '') return null;
    $yes = ['ملتزم', 'نعم', '1', 'yes', 'y', 'true', '✓', 'ok', 'ملتزمة'];
    $no  = ['غير ملتزم', 'لا', '0', 'no', 'n', 'false', '✗', 'x', 'غير ملتزمة'];
    if (in_array($val, $yes, true)) return 1;
    if (in_array($val, $no, true))  return 0;
    return null;
}
