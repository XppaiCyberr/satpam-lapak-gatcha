<?php
$memberCount = 23324;
$chatId = '-1001197136417';
$topics = [
    '3282669' => 'Lapak Digital',
    '4226256' => 'Lapak Fisik',
];
$dbPath = __DIR__.'/runtime/lapak-member-limits.sqlite';
$today = date('Y-m-d');

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatNumber($value)
{
    return number_format((int) $value);
}

function emptyTopicStats($topics)
{
    $stats = [];
    foreach ($topics as $threadId => $name) {
        $stats[$threadId] = [
            'name' => $name,
            'today_warned' => 0,
            'today_violations' => 0,
            'total_warned' => 0,
            'total_violations' => 0,
            'leaderboard' => [],
        ];
    }

    return $stats;
}

function defaultMessageStats()
{
    return [
        'today' => 0,
        'total' => 0,
        'active_hours_today' => 0,
        'avg_per_active_hour_today' => 0,
        'avg_per_day' => 0,
        'peak_hour_today' => 0,
        'stored_days' => 0,
    ];
}

function ensureDashboardSchema($db)
{
    $db->exec('CREATE TABLE IF NOT EXISTS message_thread_limits (
        day TEXT NOT NULL,
        chat_id TEXT NOT NULL,
        thread_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        name TEXT NOT NULL DEFAULT "",
        count INTEGER NOT NULL DEFAULT 0,
        warned INTEGER NOT NULL DEFAULT 0,
        violation_count INTEGER NOT NULL DEFAULT 0,
        last_warning INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (day, chat_id, thread_id, user_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS group_message_stats (
        day TEXT NOT NULL,
        hour TEXT NOT NULL,
        chat_id TEXT NOT NULL,
        count INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (day, hour, chat_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS chats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id TEXT NOT NULL,
        chat_id TEXT NOT NULL,
        thread_id TEXT NOT NULL DEFAULT "",
        user_id TEXT NOT NULL,
        name TEXT NOT NULL DEFAULT "",
        username TEXT NOT NULL DEFAULT "",
        text TEXT NOT NULL DEFAULT "",
        media_type TEXT NOT NULL DEFAULT "",
        file_id TEXT NOT NULL DEFAULT "",
        date INTEGER NOT NULL
    )');
    $columns = [];
    $result = $db->query('PRAGMA table_info(chats)');
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $columns[$row['name']] = true;
    }
    if (!isset($columns['media_type'])) {
        $db->exec('ALTER TABLE chats ADD COLUMN media_type TEXT NOT NULL DEFAULT ""');
    }
    if (!isset($columns['file_id'])) {
        $db->exec('ALTER TABLE chats ADD COLUMN file_id TEXT NOT NULL DEFAULT ""');
    }
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_chats_msg_chat ON chats (chat_id, message_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_chats_date ON chats (date)');
}

function loadStats($dbPath, $chatId, $topics, $today)
{
    $stats = emptyTopicStats($topics);
    $messageStats = defaultMessageStats();
    $meta = [
        'available' => false,
        'error' => '',
        'last_seen' => '',
        'days' => 0,
    ];

    if (!is_file($dbPath)) {
        $meta['error'] = 'SQLite database has not been created yet.';
        return [$stats, $messageStats, $meta];
    }

    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers())) {
        $meta['error'] = 'PDO SQLite is not available on this PHP runtime.';
        return [$stats, $messageStats, $meta];
    }

    try {
        $db = new PDO('sqlite:'.$dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ensureDashboardSchema($db);
        $meta['available'] = true;

        $last = $db->query('SELECT MAX(day) FROM message_thread_limits')->fetchColumn();
        $days = $db->query('SELECT COUNT(DISTINCT day) FROM message_thread_limits')->fetchColumn();
        $meta['last_seen'] = $last ? $last : '';
        $meta['days'] = (int) $days;

        foreach ($topics as $threadId => $name) {
            $stmt = $db->prepare('SELECT COUNT(DISTINCT user_id) FROM message_thread_limits WHERE day = ? AND chat_id = ? AND thread_id = ? AND warned = 1');
            $stmt->execute([$today, $chatId, (string) $threadId]);
            $stats[$threadId]['today_warned'] = (int) $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COALESCE(SUM(violation_count), 0) FROM message_thread_limits WHERE day = ? AND chat_id = ? AND thread_id = ?');
            $stmt->execute([$today, $chatId, (string) $threadId]);
            $stats[$threadId]['today_violations'] = (int) $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(DISTINCT user_id) FROM message_thread_limits WHERE chat_id = ? AND thread_id = ? AND warned = 1');
            $stmt->execute([$chatId, (string) $threadId]);
            $stats[$threadId]['total_warned'] = (int) $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COALESCE(SUM(violation_count), 0) FROM message_thread_limits WHERE chat_id = ? AND thread_id = ?');
            $stmt->execute([$chatId, (string) $threadId]);
            $stats[$threadId]['total_violations'] = (int) $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT user_id, MAX(name) AS name, SUM(violation_count) AS count FROM message_thread_limits WHERE day = ? AND chat_id = ? AND thread_id = ? AND violation_count > 0 GROUP BY user_id ORDER BY count DESC, name ASC LIMIT 5');
            $stmt->execute([$today, $chatId, (string) $threadId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stats[$threadId]['leaderboard'][] = [
                    'name' => $row['name'] != '' ? $row['name'] : 'User '.$row['user_id'],
                    'count' => (int) $row['count'],
                ];
            }
        }

        $stmt = $db->prepare('SELECT COALESCE(SUM(count), 0) FROM group_message_stats WHERE day = ? AND chat_id = ?');
        $stmt->execute([$today, $chatId]);
        $messageStats['today'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COALESCE(SUM(count), 0) FROM group_message_stats WHERE chat_id = ?');
        $stmt->execute([$chatId]);
        $messageStats['total'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM group_message_stats WHERE day = ? AND chat_id = ?');
        $stmt->execute([$today, $chatId]);
        $messageStats['active_hours_today'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COALESCE(MAX(count), 0) FROM group_message_stats WHERE day = ? AND chat_id = ?');
        $stmt->execute([$today, $chatId]);
        $messageStats['peak_hour_today'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(DISTINCT day) FROM group_message_stats WHERE chat_id = ?');
        $stmt->execute([$chatId]);
        $messageStats['stored_days'] = (int) $stmt->fetchColumn();

        $messageStats['avg_per_active_hour_today'] = $messageStats['active_hours_today'] > 0
            ? $messageStats['today'] / $messageStats['active_hours_today']
            : 0;
        $messageStats['avg_per_day'] = $messageStats['stored_days'] > 0
            ? $messageStats['total'] / $messageStats['stored_days']
            : 0;
    } catch (Exception $e) {
        $meta['available'] = false;
        $meta['error'] = $e->getMessage();
    }

    return [$stats, $messageStats, $meta];
}


list($stats, $messageStats, $meta) = loadStats($dbPath, $chatId, $topics, $today);
$todayWarned = 0;
$todayViolations = 0;
$totalWarned = 0;
$totalViolations = 0;
foreach ($stats as $topicStats) {
    $todayWarned += $topicStats['today_warned'];
    $todayViolations += $topicStats['today_violations'];
    $totalWarned += $topicStats['total_warned'];
    $totalViolations += $topicStats['total_violations'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Satpam Lapak Gatcha</title>
  <style>
    :root {
      --ink: #17201c;
      --muted: #5e6a62;
      --line: #d8ded8;
      --panel: #ffffff;
      --soft: #f5f7f3;
      --green: #1f8a5b;
      --teal: #0b7894;
      --amber: #c17708;
      --red: #b94242;
      --black: #101413;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      color: var(--ink);
      background: #eef2ee;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      letter-spacing: 0;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .shell {
      min-height: 100vh;
      display: grid;
      grid-template-columns: 280px minmax(0, 1fr);
    }

    .sidebar {
      background: var(--black);
      color: #f6fbf7;
      padding: 28px 22px;
      position: sticky;
      top: 0;
      height: 100vh;
      display: flex;
      flex-direction: column;
      gap: 28px;
    }

    .brand {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .mark {
      width: 44px;
      height: 44px;
      border-radius: 8px;
      background: #e8f6ee;
      color: var(--green);
      display: grid;
      place-items: center;
      font-weight: 900;
      font-size: 20px;
      overflow: hidden;
    }

    .mark img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .brand h1 {
      margin: 0;
      font-size: 17px;
      line-height: 1.15;
    }

    .brand p {
      margin: 3px 0 0;
      color: #aeb9b0;
      font-size: 13px;
    }

    .nav {
      display: grid;
      gap: 8px;
    }

    .nav a {
      color: #dfe8e1;
      border: 1px solid rgba(255, 255, 255, .12);
      border-radius: 8px;
      padding: 10px 12px;
      font-size: 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .nav a:hover {
      background: rgba(255, 255, 255, .08);
    }

    .sideStat {
      margin-top: auto;
      border-top: 1px solid rgba(255, 255, 255, .14);
      padding-top: 20px;
      display: grid;
      gap: 14px;
      color: #d2ddd4;
      font-size: 13px;
    }

    .sideStat strong {
      display: block;
      color: #fff;
      font-size: 24px;
      line-height: 1;
      margin-bottom: 4px;
    }

    main {
      padding: 28px;
      display: grid;
      gap: 22px;
    }

    .hero {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 8px;
      overflow: hidden;
      display: grid;
      grid-template-columns: minmax(0, 1.1fr) minmax(320px, .9fr);
      min-height: 390px;
    }

    .heroCopy {
      padding: 38px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 22px;
    }

    .eyebrow {
      color: var(--green);
      font-weight: 800;
      font-size: 13px;
      text-transform: uppercase;
    }

    .hero h2 {
      margin: 0;
      font-size: clamp(34px, 5vw, 64px);
      line-height: .96;
      max-width: 760px;
    }

    .hero p {
      margin: 0;
      color: var(--muted);
      font-size: 17px;
      line-height: 1.65;
      max-width: 710px;
    }

    .actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .button {
      min-height: 42px;
      border-radius: 8px;
      padding: 10px 14px;
      font-weight: 750;
      border: 1px solid var(--line);
      background: #fff;
      color: var(--ink);
      display: inline-flex;
      align-items: center;
      gap: 9px;
    }

    .button.primary {
      background: var(--green);
      border-color: var(--green);
      color: #fff;
    }

    .visual {
      background: #f8faf7;
      border-left: 1px solid var(--line);
      padding: 28px;
      display: grid;
      align-content: center;
      gap: 14px;
    }

    .phone {
      max-width: 390px;
      width: 100%;
      margin: 0 auto;
      border: 10px solid #1b221f;
      border-radius: 28px;
      background: #e9f1ec;
      box-shadow: 0 18px 44px rgba(20, 30, 24, .18);
      overflow: hidden;
    }

    .phoneTop {
      height: 48px;
      background: #1b221f;
      color: #ecf5ef;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 16px;
      font-size: 13px;
      font-weight: 700;
    }

    .chat {
      padding: 18px;
      display: grid;
      gap: 12px;
      min-height: 360px;
    }

    .bubble {
      background: #fff;
      border-radius: 8px;
      padding: 12px;
      border: 1px solid #dbe4dd;
      font-size: 13px;
      line-height: 1.45;
      box-shadow: 0 6px 16px rgba(40, 60, 48, .08);
    }

    .bubble.user {
      justify-self: end;
      background: #dff2e7;
      max-width: 78%;
    }

    .bubble.warn {
      border-left: 4px solid var(--red);
    }

    .inlineKeys {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 6px;
    }

    .inlineKeys span {
      border: 1px solid #b8d1c3;
      background: #edf7f1;
      border-radius: 6px;
      min-height: 34px;
      display: grid;
      place-items: center;
      font-size: 12px;
      font-weight: 800;
      color: var(--green);
    }

    .grid {
      display: grid;
      gap: 16px;
    }

    .stats {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .card {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 20px;
    }

    .metric {
      display: grid;
      gap: 8px;
      min-height: 138px;
    }

    .metric span {
      color: var(--muted);
      font-size: 13px;
      font-weight: 700;
    }

    .metric strong {
      font-size: 34px;
      line-height: 1;
    }

    .metric p {
      margin: 0;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
    }

    .section {
      display: grid;
      gap: 14px;
    }

    .sectionHeader {
      display: flex;
      justify-content: space-between;
      align-items: end;
      gap: 20px;
    }

    .sectionHeader h3 {
      margin: 0;
      font-size: 25px;
    }

    .sectionHeader p {
      margin: 0;
      color: var(--muted);
      max-width: 720px;
      line-height: 1.55;
    }

    .twoCol {
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .topic {
      display: grid;
      gap: 14px;
    }

    .topicTitle {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .topicTitle h4 {
      margin: 0;
      font-size: 20px;
    }

    .pill {
      border-radius: 999px;
      padding: 5px 9px;
      font-size: 12px;
      font-weight: 800;
      background: #edf6f2;
      color: var(--green);
      border: 1px solid #cbe2d6;
      white-space: nowrap;
    }

    .topic ul {
      margin: 0;
      padding: 0;
      display: grid;
      gap: 9px;
      list-style: none;
    }

    .topic li {
      display: flex;
      gap: 10px;
      color: var(--muted);
      line-height: 1.5;
    }

    .dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--green);
      flex: 0 0 8px;
      margin-top: 8px;
    }

    .flow {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .step {
      min-height: 168px;
      display: grid;
      align-content: start;
      gap: 10px;
      border-top: 4px solid var(--teal);
    }

    .step:nth-child(2) {
      border-top-color: var(--amber);
    }

    .step:nth-child(3) {
      border-top-color: var(--red);
    }

    .step:nth-child(4) {
      border-top-color: var(--green);
    }

    .step b {
      font-size: 18px;
    }

    .step p {
      margin: 0;
      color: var(--muted);
      line-height: 1.5;
      font-size: 14px;
    }

    .table {
      width: 100%;
      border-collapse: collapse;
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 8px;
      overflow: hidden;
    }

    .table th,
    .table td {
      text-align: left;
      border-bottom: 1px solid var(--line);
      padding: 14px 16px;
      font-size: 14px;
    }

    .table th {
      color: var(--muted);
      background: #f8faf7;
      font-size: 12px;
      text-transform: uppercase;
    }

    .table tr:last-child td {
      border-bottom: 0;
    }

    .leaderboard {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .miniList {
      margin: 0;
      padding: 0;
      list-style: none;
      display: grid;
      gap: 10px;
    }

    .miniList li {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      border-bottom: 1px solid var(--line);
      padding-bottom: 10px;
      color: var(--muted);
      font-size: 14px;
    }

    .miniList li:last-child {
      border-bottom: 0;
      padding-bottom: 0;
    }

    .miniList b {
      color: var(--ink);
    }

    .notice {
      background: #fff9ed;
      border: 1px solid #ead3a6;
      color: #755110;
      border-radius: 8px;
      padding: 14px 16px;
      line-height: 1.45;
      font-size: 14px;
    }

    .footer {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
      padding: 8px 0 20px;
    }


    @media (max-width: 1060px) {
      .shell {
        grid-template-columns: 1fr;
      }

      .sidebar {
        position: static;
        height: auto;
      }

      .hero {
        grid-template-columns: 1fr;
      }

      .visual {
        border-left: 0;
        border-top: 1px solid var(--line);
      }

      .stats,
      .flow {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 720px) {
      main {
        padding: 16px;
      }

      .heroCopy,
      .visual {
        padding: 22px;
      }

      .stats,
      .twoCol,
      .flow,
      .leaderboard {
        grid-template-columns: 1fr;
      }

      .sectionHeader {
        display: grid;
      }

      .table {
        display: block;
        overflow-x: auto;
      }
    }
  </style>
</head>
<body>
  <div class="shell">
    <aside class="sidebar">
      <div class="brand">
        <div class="mark"><img src="logo.jpg" alt="Gatcha logo"></div>
        <div>
          <h1>Satpam Lapak Gatcha</h1>
          <p>Telegram moderation bot</p>
        </div>
      </div>

      <nav class="nav" aria-label="Page sections">
        <a href="#overview">Overview <span>01</span></a>
        <a href="#rules">Rules <span>02</span></a>
        <a href="#stats">Stats <span>03</span></a>
        <a href="#commands">Commands <span>04</span></a>
        <a href="monitor.php">Monitor <span>05</span></a>
      </nav>

      <div class="sideStat">
        <div>
          <strong><?= h(formatNumber($memberCount)) ?></strong>
          total Telegram members
        </div>
        <div>
          <strong><?= h(count($topics)) ?></strong>
          monitored marketplace topics
        </div>
      </div>
    </aside>

    <main>
      <section class="hero" id="overview">
        <div class="heroCopy">
          <div class="eyebrow">www.Gatcha.org · @trick_ngirit</div>
          <h2>Moderation dashboard for the Gatcha Telegram group.</h2>
          <p>
            Satpam Lapak Gatcha keeps marketplace topics readable by limiting each user to two posts per day
            in monitored Lapak topics, deleting excess messages, mentioning violators, and retaining moderation
            stats in SQLite.
          </p>
          <div class="actions">
            <a class="button primary" href="https://t.me/trick_ngirit">Open Telegram Group</a>
            <a class="button" href="https://www.Gatcha.org">Visit Gatcha.org</a>
          </div>
        </div>

        <div class="visual" aria-label="Telegram bot moderation preview">
          <div class="phone">
            <div class="phoneTop">
              <span>Gatcha Moderation</span>
              <span>online</span>
            </div>
            <div class="chat">
              <div class="bubble user">Lapak Digital post #3 from the same user today</div>
              <div class="bubble warn">
                <b>Satpam Lapak Gatcha</b><br>
                @member Limit Lapak Member: setiap user maksimal 2 pesan per hari.
              </div>
              <div class="bubble">
                <b>Tangkapan Satpam hari ini</b><br>
                <?php foreach ($topics as $threadId => $name): ?>
                  <?= h($name) ?>: <?= h($stats[$threadId]['today_warned']) ?> user kena warning<br>
                <?php endforeach; ?>
              </div>
              <div class="inlineKeys">
                <span>Ringkasan</span>
                <span>Leaderboard</span>
                <span>Total</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="grid stats" id="stats">
        <div class="card metric">
          <span>Total Members</span>
          <strong><?= h(formatNumber($memberCount)) ?></strong>
          <p>Current Gatcha Telegram community size provided for this dashboard.</p>
        </div>
        <div class="card metric">
          <span>Messages Today</span>
          <strong><?= h(formatNumber($messageStats['today'])) ?></strong>
          <p>Messages received by the bot today in the Gatcha group.</p>
        </div>
        <div class="card metric">
          <span>Avg / Active Hour</span>
          <strong><?= h(number_format($messageStats['avg_per_active_hour_today'], 1)) ?></strong>
          <p>Average messages per hour with activity today.</p>
        </div>
        <div class="card metric">
          <span>Avg / Stored Day</span>
          <strong><?= h(number_format($messageStats['avg_per_day'], 1)) ?></strong>
          <p>Average messages per retained day in SQLite.</p>
        </div>
      </section>

      <section class="grid stats">
        <div class="card metric">
          <span>Warned Today</span>
          <strong><?= h(formatNumber($todayWarned)) ?></strong>
          <p>Unique users who triggered warnings today across monitored topics.</p>
        </div>
        <div class="card metric">
          <span>Violations Today</span>
          <strong><?= h(formatNumber($todayViolations)) ?></strong>
          <p>Excess messages deleted and counted today.</p>
        </div>
        <div class="card metric">
          <span>Peak Hour Today</span>
          <strong><?= h(formatNumber($messageStats['peak_hour_today'])) ?></strong>
          <p>Highest message count in a recorded hour today.</p>
        </div>
        <div class="card metric">
          <span>Total Messages</span>
          <strong><?= h(formatNumber($messageStats['total'])) ?></strong>
          <p>All messages retained in the SQLite message counter.</p>
        </div>
      </section>

      <?php if (!$meta['available']): ?>
        <div class="notice">SQLite stats are not available yet: <?= h($meta['error']) ?></div>
      <?php endif; ?>

      <section class="section" id="rules">
        <div class="sectionHeader">
          <h3>Monitored Topics</h3>
          <p>The bot only enforces rules inside the configured Lapak Member topics in the Gatcha Telegram group. Messages outside these topics are not counted by this rule.</p>
        </div>

        <div class="grid twoCol">
          <?php foreach ($topics as $threadId => $name): ?>
          <div class="card topic">
            <div class="topicTitle">
              <h4><?= h($name) ?></h4>
              <span class="pill">topic <?= h($threadId) ?></span>
            </div>
            <ul>
              <li><span class="dot"></span><span>Daily user post limit: 2 messages.</span></li>
              <li><span class="dot"></span><span><?= h(formatNumber($stats[$threadId]['today_warned'])) ?> users warned today.</span></li>
              <li><span class="dot"></span><span><?= h(formatNumber($stats[$threadId]['total_violations'])) ?> retained total violations.</span></li>
              <li><span class="dot"></span><span>Violators are mentioned in a warning reply.</span></li>
            </ul>
          </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="section">
        <div class="sectionHeader">
          <h3>Moderation Flow</h3>
          <p>Satpam Lapak Gatcha runs as a Telegram bot and reacts to incoming messages in the monitored topic IDs.</p>
        </div>

        <div class="grid flow">
          <div class="card step">
            <b>1. Watch topics</b>
            <p>Only messages from the configured Gatcha chat and topic IDs are evaluated.</p>
          </div>
          <div class="card step">
            <b>2. Count daily posts</b>
            <p>Each user has an independent daily counter per monitored topic.</p>
          </div>
          <div class="card step">
            <b>3. Enforce limit</b>
            <p>Messages beyond the second post are deleted and recorded as violations.</p>
          </div>
          <div class="card step">
            <b>4. Report stats</b>
            <p>/satpam opens summary, leaderboard, and total stats through inline buttons.</p>
          </div>
        </div>
      </section>

      <section class="section">
        <div class="sectionHeader">
          <h3>Live Leaderboard Today</h3>
          <p>Read directly from <code>runtime/lapak-member-limits.sqlite</code>. Counts show excess messages after the 2-post daily limit.</p>
        </div>

        <div class="grid leaderboard">
          <?php foreach ($topics as $threadId => $name): ?>
            <div class="card topic">
              <div class="topicTitle">
                <h4><?= h($name) ?></h4>
                <span class="pill"><?= h(formatNumber($stats[$threadId]['today_violations'])) ?> today</span>
              </div>
              <?php if (empty($stats[$threadId]['leaderboard'])): ?>
                <p class="footer">Belum ada pelanggar hari ini.</p>
              <?php else: ?>
                <ol class="miniList">
                  <?php foreach ($stats[$threadId]['leaderboard'] as $row): ?>
                    <li><b><?= h($row['name']) ?></b><span><?= h(formatNumber($row['count'])) ?> pelanggaran</span></li>
                  <?php endforeach; ?>
                </ol>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="section" id="commands">
        <div class="sectionHeader">
          <h3>Bot Commands And Stats</h3>
          <p>The bot currently exposes one command. Its inline keyboard switches between today-only moderation data and retained totals from SQLite. Message-volume averages reflect messages the bot receives from the group.</p>
        </div>

        <table class="table">
          <thead>
            <tr>
              <th>Command or View</th>
              <th>What It Shows</th>
              <th>Scope</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>/satpam</td>
              <td>Tangkapan Satpam hari ini: unique warned users per topic</td>
              <td>Today only</td>
            </tr>
            <tr>
              <td>Leaderboard</td>
              <td>Users who violated the rule and how many excess messages they sent</td>
              <td>Today only</td>
            </tr>
            <tr>
              <td>Total</td>
              <td><?= h(formatNumber($totalWarned)) ?> retained warned users, <?= h(formatNumber($totalViolations)) ?> retained violations</td>
              <td><?= h($meta['days']) ?> stored day<?= $meta['days'] == 1 ? '' : 's' ?></td>
            </tr>
            <tr>
              <td>Ringkasan</td>
              <td>Returns the report message to the /satpam summary</td>
              <td>Today only</td>
            </tr>
            <tr>
              <td>Message rate</td>
              <td><?= h(formatNumber($messageStats['today'])) ?> today, <?= h(number_format($messageStats['avg_per_active_hour_today'], 1)) ?> per active hour, <?= h(number_format($messageStats['avg_per_day'], 1)) ?> per stored day</td>
              <td><?= h($messageStats['stored_days']) ?> stored day<?= $messageStats['stored_days'] == 1 ? '' : 's' ?></td>
            </tr>
          </tbody>
        </table>
      </section>


      <p class="footer">
        Runtime statistics are read from <code>runtime/lapak-member-limits.sqlite</code>.
        <?php if ($meta['last_seen'] != ''): ?> Last stored activity date: <?= h($meta['last_seen']) ?>.<?php endif; ?>
      </p>
    </main>
  </div>
</body>
</html>
