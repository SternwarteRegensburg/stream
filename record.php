<?php
/*
 * Start/stop the DeckLink HLS stream and its recording.
 *
 * Install:
 *   cp record.php /var/www/record.php
 *   cp decklink-record.service /etc/systemd/system/
 *   cp decklink-record.sudoers /etc/sudoers.d/decklink-record  (root:root, mode 0440)
 *   systemctl daemon-reload
 *
 * The sudoers file name must not contain a dot — sudo silently skips
 * such files in /etc/sudoers.d.
 *
 * Protect this file with Apache authentication — anyone who can open it
 * can start and stop the stream and recordings.
 */

const UNIT_REC = 'decklink-record.service';
const UNIT_HLS = 'decklink-hls.service';
const REC_DIR  = '/var/www/recordings';
const REC_URL  = '/recordings';

/* Only these unit/action pairs are ever passed to systemctl. */
const ACTIONS = [
    'rec-start' => [UNIT_REC, 'start', 'Recording started.'],
    'rec-stop'  => [UNIT_REC, 'stop',  'Recording stopped.'],
    'hls-start' => [UNIT_HLS, 'start', 'Stream started.'],
    'hls-stop'  => [UNIT_HLS, 'stop',  'Stream stopped.'],
];

function unit_is_active(string $unit): bool
{
    exec('systemctl is-active --quiet ' . escapeshellarg($unit), $out, $code);
    return $code === 0;
}

/** Wall-clock time the unit became active, or null if it is not running. */
function unit_started_at(string $unit): ?int
{
    exec('systemctl show -p ActiveEnterTimestamp --value ' . escapeshellarg($unit), $out);
    $ts = trim(implode('', $out));
    if ($ts === '') {
        return null;
    }
    $time = strtotime($ts);
    return $time === false ? null : $time;
}

function control(string $unit, string $action, string $okMsg): array
{
    $cmd = 'sudo -n /usr/bin/systemctl ' . $action . ' ' . escapeshellarg($unit) . ' 2>&1';
    exec($cmd, $out, $code);
    if ($code === 0) {
        return [$okMsg, 'ok'];
    }
    return ['systemctl ' . $action . ' ' . $unit . ' failed: ' . trim(implode(' ', $out)), 'error'];
}

/* --- handle the form (POST/redirect/GET so reloads do not re-submit) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['action'] ?? '';
    if (!isset(ACTIONS[$key])) {
        [$msg, $cls] = ['Unknown action.', 'error'];
    } else {
        [$unit, $action, $okMsg] = ACTIONS[$key];
        $active = unit_is_active($unit);
        if (($action === 'start') === $active) {
            [$msg, $cls] = ['Nothing to do — state already as requested.', ''];
        } else {
            [$msg, $cls] = control($unit, $action, $okMsg);
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')
        . '?msg=' . urlencode($msg) . '&cls=' . urlencode($cls));
    exit;
}

$message    = $_GET['msg'] ?? '';
$messageCls = in_array($_GET['cls'] ?? '', ['ok', 'error'], true) ? $_GET['cls'] : '';
$recording  = unit_is_active(UNIT_REC);
$streaming  = unit_is_active(UNIT_HLS);
$recStarted = $recording ? unit_started_at(UNIT_REC) : null;
$hlsStarted = $streaming ? unit_started_at(UNIT_HLS) : null;
$selfUrl    = strtok($_SERVER['REQUEST_URI'], '?');

/* newest recordings first */
$files = [];
foreach (glob(REC_DIR . '/*.mkv') ?: [] as $path) {
    $files[] = ['name' => basename($path), 'size' => filesize($path), 'time' => filemtime($path)];
}
usort($files, fn($a, $b) => $b['time'] <=> $a['time']);

function human_size(int $bytes): string
{
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $i = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
    $i = min($i, count($units) - 1);
    return round($bytes / (1024 ** $i), $i ? 1 : 0) . ' ' . $units[$i];
}

function human_duration(int $seconds): string
{
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if ($recording || $streaming || $message !== ''): ?>
    <!-- keep duration/file size current, and drop the message from the URL -->
    <meta http-equiv="refresh" content="15;url=<?= htmlspecialchars($selfUrl) ?>">
  <?php endif; ?>
  <title>Recording Control</title>
  <style>
    body {
      margin: 0;
      background: #1a1a1a;
      color: #eee;
      font-family: system-ui, sans-serif;
      display: flex;
      flex-direction: column;
      align-items: center;
      min-height: 100vh;
    }
    h1 {
      font-weight: 400;
      margin: 20px 0;
    }
    main {
      width: 100%;
      max-width: 640px;
      padding: 0 16px 40px;
      box-sizing: border-box;
    }
    .panel {
      background: #222;
      border: 1px solid #333;
      padding: 20px;
      margin-bottom: 24px;
    }
    .panel h2 {
      font-weight: 400;
      font-size: 1rem;
      color: #aaa;
      margin: 0 0 12px;
    }
    .state {
      font-size: 1.1rem;
      margin: 0 0 16px;
    }
    p.hint {
      color: #888;
      font-size: 0.85rem;
      margin: 10px 0 0;
    }
    .dot {
      display: inline-block;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      margin-right: 8px;
      vertical-align: middle;
    }
    .dot.on  { background: #ff6b6b; }
    .dot.off { background: #666; }
    button {
      font: inherit;
      font-size: 1rem;
      padding: 10px 24px;
      border: 0;
      color: #fff;
      background: #444;
      cursor: pointer;
    }
    button.start { background: #2f7d3f; }
    button.stop  { background: #a33; }
    button:hover { filter: brightness(1.15); }
    #status {
      margin: 0 0 16px;
      font-size: 0.9rem;
      color: #aaa;
    }
    .error { color: #ff6b6b; }
    .ok { color: #51cf66; }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.9rem;
    }
    th, td {
      text-align: left;
      padding: 6px 8px;
      border-bottom: 1px solid #333;
    }
    th { color: #aaa; font-weight: 400; }
    td.num { text-align: right; white-space: nowrap; }
    a { color: #74c0fc; }
    p.empty { color: #888; margin: 0; }
    nav { margin-bottom: 24px; }
  </style>
</head>
<body>
  <h1>Übertragung &amp; Aufzeichnung</h1>

  <main>
    <nav><a href="/">&larr; Zum Livestream</a></nav>

    <?php if ($message !== ''): ?>
      <div id="status" class="<?= htmlspecialchars($messageCls) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="panel">
      <h2>Livestream</h2>
      <p class="state">
        <span class="dot <?= $streaming ? 'on' : 'off' ?>"></span>
        <?php if ($streaming): ?>
          Streaming<?php if ($hlsStarted !== null): ?>
            since <?= htmlspecialchars(date('H:i:s', $hlsStarted)) ?>
            (<?= human_duration(max(0, time() - $hlsStarted)) ?>)
          <?php endif; ?>
        <?php else: ?>
          Stopped
        <?php endif; ?>
      </p>

      <form method="post">
        <?php if ($streaming): ?>
          <button class="stop" name="action" value="hls-stop" type="submit">Stop stream</button>
          <?php if ($recording): ?>
            <p class="hint">Stopping the stream also ends the running recording.</p>
          <?php endif; ?>
        <?php else: ?>
          <button class="start" name="action" value="hls-start" type="submit">Start stream</button>
        <?php endif; ?>
      </form>
    </div>

    <div class="panel">
      <h2>Aufzeichnung</h2>
      <p class="state">
        <span class="dot <?= $recording ? 'on' : 'off' ?>"></span>
        <?php if ($recording): ?>
          Recording<?php if ($recStarted !== null): ?>
            since <?= htmlspecialchars(date('H:i:s', $recStarted)) ?>
            (<?= human_duration(max(0, time() - $recStarted)) ?>)
          <?php endif; ?>
        <?php else: ?>
          Not recording
        <?php endif; ?>
      </p>

      <form method="post">
        <?php if ($recording): ?>
          <button class="stop" name="action" value="rec-stop" type="submit">Stop recording</button>
        <?php else: ?>
          <button class="start" name="action" value="rec-start" type="submit">Start recording</button>
          <?php if (!$streaming): ?>
            <p class="hint">Starting a recording also starts the stream.</p>
          <?php endif; ?>
        <?php endif; ?>
      </form>
    </div>

    <div class="panel">
      <h2>Recordings</h2>
      <?php if (!$files): ?>
        <p class="empty">No recordings yet.</p>
      <?php else: ?>
        <table>
          <tr><th>File</th><th class="num">Size</th><th class="num">Modified</th></tr>
          <?php foreach ($files as $f): ?>
            <tr>
              <td><a href="<?= REC_URL . '/' . htmlspecialchars(rawurlencode($f['name'])) ?>"><?= htmlspecialchars($f['name']) ?></a></td>
              <td class="num"><?= human_size($f['size']) ?></td>
              <td class="num"><?= htmlspecialchars(date('Y-m-d H:i', $f['time'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
