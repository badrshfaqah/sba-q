<?php
/**
 * بث الملفات الصوتية للحلقات بعد التحقق من الصلاحية
 * (مجلد uploads محمي بالكامل — لا يُقرأ إلا من هنا)
 */
require __DIR__ . '/core/bootstrap.php';
require_login();

$episodeId = (int)($_GET['episode'] ?? 0);
$user = current_user();

$st = db()->prepare('SELECT audio_path FROM ' . tbl('episodes') . ' WHERE id = ?');
$st->execute([$episodeId]);
$path = (string)$st->fetchColumn();

if ($path === '') {
    http_response_code(404);
    exit('غير موجود');
}

/* من يملك حق الاستماع: من يرى كل البيانات، أو من عُيّن مقيّماً لهذه الحلقة */
$allowed = can('data.viewall') || can('episodes.manage');
if (!$allowed) {
    $st = db()->prepare('SELECT COUNT(*) FROM ' . tbl('episode_evaluators') . '
        WHERE episode_id = ? AND user_id = ?');
    $st->execute([$episodeId, (int)$user['id']]);
    $allowed = (int)$st->fetchColumn() > 0;
}
if (!$allowed) {
    http_response_code(403);
    exit('غير مصرح');
}

/* منع أي خروج عن مجلد الرفع */
$full = realpath(SBA_ROOT . '/' . $path);
$base = realpath(SBA_ROOT . '/uploads');
if ($full === false || $base === false || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
    http_response_code(404);
    exit('غير موجود');
}

$types = [
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
    'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'webm' => 'audio/webm',
];
$ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mime = $types[$ext] ?? 'application/octet-stream';
$size = filesize($full);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="episode-' . $episodeId . '.' . $ext . '"');
header('Cache-Control: private, max-age=600');
header('Accept-Ranges: bytes');

/* دعم الطلب الجزئي (ضروري للتنقل داخل المقطع على الجوال) */
$start = 0;
$end   = $size - 1;
if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end   = min((int)$m[2], $size - 1);
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . ($end - $start + 1));

$fh = fopen($full, 'rb');
fseek($fh, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fh)) {
    $chunk = fread($fh, (int)min(8192, $remaining));
    if ($chunk === false) break;
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($fh);
