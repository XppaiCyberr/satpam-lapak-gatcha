<?php
$chatId = '-1001197136417';
$dbPath = __DIR__.'/runtime/lapak-member-limits.sqlite';
$fallbackTopics = [
    '3282669' => 'Lapak Digital',
    '4226256' => 'Lapak Fisik',
];

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function loadCredentials($path)
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];
    $values = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line == '' || substr($line, 0, 1) == '#') {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $config[trim($key)] = trim($value);
        } else {
            $values[] = $line;
        }
    }

    if (isset($config['token'])) {
        return $config;
    }

    return ['token' => isset($values[0]) ? $values[0] : ''];
}

function jsonResponse($data)
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function telegramRequest($token, $method, $data)
{
    if ($token == '') {
        return ['ok' => false, 'description' => 'Bot token is not configured.'];
    }
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = json_encode($value);
        }
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.telegram.org/bot'.$token.'/'.$method,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'description' => $error];
    }
    curl_close($ch);
    $decoded = json_decode($result, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'Invalid Telegram response.'];
}

function ensureMonitorSchema($db)
{
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
    $db->exec('CREATE TABLE IF NOT EXISTS chat_topics (
        chat_id TEXT NOT NULL,
        thread_id TEXT NOT NULL,
        name TEXT NOT NULL DEFAULT "",
        updated_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (chat_id, thread_id)
    )');
}

function loadTopics($db, $fallbackTopics)
{
    $topics = $fallbackTopics;
    $stmt = $db->query('SELECT thread_id, MAX(name) AS name FROM chat_topics GROUP BY thread_id ORDER BY name ASC, thread_id ASC');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['thread_id'] != '') {
            $topics[$row['thread_id']] = $row['name'] != '' ? $row['name'] : 'Topic #'.$row['thread_id'];
        }
    }
    $stmt = $db->query('SELECT DISTINCT thread_id FROM chats WHERE thread_id != "" ORDER BY thread_id ASC');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($topics[$row['thread_id']])) {
            $topics[$row['thread_id']] = 'Topic #'.$row['thread_id'];
        }
    }
    return $topics;
}

if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action == 'media') {
        $credentials = loadCredentials(__DIR__.'/x.c');
        $fileId = isset($_GET['file_id']) ? trim($_GET['file_id']) : '';
        $file = telegramRequest(isset($credentials['token']) ? $credentials['token'] : '', 'getFile', ['file_id' => $fileId]);
        if (!isset($file['ok']) || !$file['ok'] || !isset($file['result']['file_path'])) {
            http_response_code(404);
            exit;
        }
        $url = 'https://api.telegram.org/file/bot'.$credentials['token'].'/'.$file['result']['file_path'];
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_SSL_VERIFYPEER => false]);
        $content = curl_exec($ch);
        curl_close($ch);
        if ($content === false) {
            http_response_code(404);
            exit;
        }
        $extension = strtolower(pathinfo($file['result']['file_path'], PATHINFO_EXTENSION));
        $types = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
        header('Cache-Control: public, max-age=86400');
        header('Content-Type: '.(isset($types[$extension]) ? $types[$extension] : 'application/octet-stream'));
        echo $content;
        exit;
    }

    if ($action == 'chats') {
        if (!is_file($dbPath)) {
            jsonResponse(['ok' => true, 'chats' => [], 'topics' => $fallbackTopics]);
        }
        if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers())) {
            jsonResponse(['ok' => false, 'error' => 'PDO SQLite is not available.']);
        }
        $db = new PDO('sqlite:'.$dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ensureMonitorSchema($db);
        $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
        $stmt = $db->prepare('SELECT id, message_id, chat_id, thread_id, user_id, name, username, text, media_type, file_id, date FROM chats WHERE id > ? ORDER BY id ASC LIMIT 150');
        $stmt->execute([$sinceId]);
        jsonResponse(['ok' => true, 'chats' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'topics' => loadTopics($db, $fallbackTopics)]);
    }

    if ($action == 'reply') {
        $text = isset($_POST['text']) ? trim($_POST['text']) : '';
        $replyTo = isset($_POST['message_id']) ? trim($_POST['message_id']) : '';
        $replyChatId = isset($_POST['chat_id']) ? trim($_POST['chat_id']) : $chatId;
        $threadId = isset($_POST['thread_id']) ? trim($_POST['thread_id']) : '';
        if ($text == '' || $replyTo == '') {
            jsonResponse(['ok' => false, 'error' => 'Message text and target message are required.']);
        }
        $credentials = loadCredentials(__DIR__.'/x.c');
        $payload = ['chat_id' => $replyChatId, 'text' => $text, 'reply_parameters' => ['message_id' => (int) $replyTo], 'allow_sending_without_reply' => true];
        if ($threadId != '') {
            $payload['message_thread_id'] = (int) $threadId;
        }
        $response = telegramRequest(isset($credentials['token']) ? $credentials['token'] : '', 'sendMessage', $payload);
        if (isset($response['ok']) && $response['ok']) {
            if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers()) && is_file($dbPath) && isset($response['result']['message_id'])) {
                $db = new PDO('sqlite:'.$dbPath);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                ensureMonitorSchema($db);
                $botName = isset($credentials['username']) && $credentials['username'] != '' ? '@'.ltrim($credentials['username'], '@') : 'Satpam Bot';
                $date = isset($response['result']['date']) ? (int) $response['result']['date'] : time();
                $stmt = $db->prepare('INSERT OR IGNORE INTO chats (message_id, chat_id, thread_id, user_id, name, username, text, media_type, file_id, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([(string) $response['result']['message_id'], $replyChatId, $threadId, 'bot', $botName, ltrim(isset($credentials['username']) ? $credentials['username'] : '', '@'), $text, '', '', $date]);
            }
            jsonResponse(['ok' => true]);
        }
        jsonResponse(['ok' => false, 'error' => isset($response['description']) ? $response['description'] : 'Telegram request failed.']);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Realtime Monitor</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @keyframes floatIn { from { opacity:0; transform:translateY(10px) scale(.98); } to { opacity:1; transform:translateY(0) scale(1); } }
    @keyframes pulseDot { 0%,100% { transform:scale(1); opacity:1; } 50% { transform:scale(1.35); opacity:.55; } }
    .animate-message { animation:floatIn .22s ease-out both; }
    .animate-live { animation:pulseDot 1.4s ease-in-out infinite; }
    .feed-scroll { scrollbar-width:thin; scrollbar-color:#cbd5c8 transparent; }
  </style>
</head>
<body class="h-screen overflow-hidden bg-neutral-950 text-neutral-100 antialiased">
  <div class="grid h-screen grid-rows-[64px_1fr]">
    <header class="flex items-center justify-between border-b border-white/10 bg-neutral-950/80 px-4 backdrop-blur-xl sm:px-6">
      <div>
        <h1 class="text-base font-semibold tracking-tight sm:text-lg">Realtime Monitor</h1>
        <p class="text-xs text-neutral-400">All Telegram topics · live reply panel</p>
      </div>
      <div class="flex items-center gap-3 rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1.5 text-xs text-emerald-200">
        <span class="h-2 w-2 rounded-full bg-emerald-400 animate-live"></span>
        <span id="status">live</span>
      </div>
    </header>

    <main class="grid min-h-0 grid-cols-1 lg:grid-cols-[280px_1fr]">
      <aside class="min-h-0 overflow-y-auto border-b border-white/10 bg-neutral-900/70 p-3 lg:border-b-0 lg:border-r">
        <div class="mb-3 flex items-center justify-between px-2 text-xs uppercase tracking-[.2em] text-neutral-500">
          <span>Topics</span><span id="topicCount">0</span>
        </div>
        <div id="filters" class="flex gap-2 overflow-x-auto pb-1 lg:grid lg:overflow-visible"></div>
      </aside>

      <section class="relative min-h-0 bg-[radial-gradient(circle_at_top_right,rgba(16,185,129,.12),transparent_36%),#0a0a0a]">
        <div id="feed" class="feed-scroll h-full overflow-y-auto p-3 sm:p-5 lg:p-6">
          <div class="grid h-full place-items-center text-sm text-neutral-500">Waiting for messages...</div>
        </div>
      </section>
    </main>
  </div>

  <script>
    var topics = <?= json_encode($fallbackTopics) ?>;
    var lastId = 0;
    var activeTopic = '';
    var messages = [];
    var topicsKey = JSON.stringify(topics);
    var feed = document.getElementById('feed');
    var filters = document.getElementById('filters');
    var statusEl = document.getElementById('status');
    var topicCount = document.getElementById('topicCount');

    function esc(v) { return String(v || '').replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
    function topicLabel(id) { return id ? (topics[id] || ('Topic #' + id)) : 'General'; }
    function visible(m) { return activeTopic === '' || (activeTopic === '__general' && !m.thread_id) || m.thread_id === activeTopic; }
    function countTopic(id) { return messages.filter(function(m) { return id === '' || (id === '__general' && !m.thread_id) || m.thread_id === id; }).length; }

    function renderFilters() {
      var items = [{id:'', name:'All'}, {id:'__general', name:'General'}];
      Object.keys(topics).sort(function(a,b) { return topics[a].localeCompare(topics[b]); }).forEach(function(id) { items.push({id:id, name:topics[id]}); });
      topicCount.textContent = Object.keys(topics).length;
      filters.innerHTML = items.map(function(item) {
        var active = item.id === activeTopic;
        return '<button data-topic="' + esc(item.id) + '" class="group flex min-w-fit items-center justify-between gap-3 rounded-2xl border px-3 py-2 text-left text-sm transition duration-200 hover:-translate-y-0.5 ' + (active ? 'border-emerald-400/40 bg-emerald-400/15 text-emerald-100 shadow-lg shadow-emerald-950/30' : 'border-white/10 bg-white/[.03] text-neutral-300 hover:border-white/20 hover:bg-white/[.06]') + '"><span class="truncate">' + esc(item.name) + '</span><span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-neutral-400">' + countTopic(item.id) + '</span></button>';
      }).join('');
    }

    function renderFeed() {
      var list = messages.filter(visible).slice(-250);
      if (!list.length) {
        feed.innerHTML = '<div class="grid h-full place-items-center text-sm text-neutral-500">No messages in this view.</div>';
        return;
      }
      feed.innerHTML = '<div class="mx-auto grid w-full max-w-4xl gap-3">' + list.map(function(m) {
        var media = m.file_id && (m.media_type === 'sticker' || m.media_type === 'photo') ? '<img class="max-h-56 max-w-56 rounded-2xl bg-neutral-900 object-contain ring-1 ring-white/10" loading="lazy" src="?action=media&file_id=' + encodeURIComponent(m.file_id) + '" alt="' + esc(m.media_type) + '">' : '';
        var bot = m.user_id === 'bot';
        var cardClass = bot ? 'border-emerald-400/25 bg-emerald-950/35' : 'border-white/10 bg-neutral-900/80';
        var nameClass = bot ? 'text-emerald-200' : 'text-neutral-100';
        return '<article class="animate-message rounded-3xl border ' + cardClass + ' p-4 shadow-2xl shadow-black/20 backdrop-blur" data-chat="' + esc(m.chat_id) + '" data-thread="' + esc(m.thread_id) + '" data-message="' + esc(m.message_id) + '"><div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-neutral-500"><span><b class="font-semibold ' + nameClass + '">' + esc(m.name || ('User ' + m.user_id)) + '</b><span class="mx-2 text-neutral-700">/</span><span class="text-emerald-300">' + esc(topicLabel(m.thread_id)) + '</span></span><time>' + new Date(m.date * 1000).toLocaleTimeString() + '</time></div>' + media + '<div class="whitespace-pre-wrap break-words text-sm leading-6 text-neutral-200">' + esc(m.text || '[message]') + '</div><form class="mt-3 flex gap-2"><input class="min-w-0 flex-1 rounded-full border border-white/10 bg-black/30 px-4 py-2 text-sm outline-none transition focus:border-emerald-400/60" autocomplete="off" placeholder="Reply..."><button class="rounded-full bg-emerald-500 px-4 py-2 text-sm font-semibold text-neutral-950 transition hover:-translate-y-0.5 hover:bg-emerald-400">Send</button></form></article>';
      }).join('') + '</div>';
      feed.scrollTop = feed.scrollHeight;
    }

    function render() { renderFilters(); renderFeed(); }

    feed.addEventListener('submit', function(e) {
      e.preventDefault();
      var card = e.target.closest('article');
      var input = e.target.querySelector('input');
      var body = new URLSearchParams();
      body.append('chat_id', card.dataset.chat);
      body.append('thread_id', card.dataset.thread || '');
      body.append('message_id', card.dataset.message);
      body.append('text', input.value);
      fetch('?action=reply', {method:'POST', body:body}).then(function(r) { return r.json(); }).then(function(data) {
        if (data.ok) { input.value = ''; input.placeholder = 'Sent'; return; }
        alert(data.error || 'Failed');
      });
    });

    filters.addEventListener('click', function(e) {
      var button = e.target.closest('button[data-topic]');
      if (!button) return;
      activeTopic = button.dataset.topic;
      render();
    });

    function poll() {
      fetch('?action=chats&since_id=' + lastId).then(function(r) { return r.json(); }).then(function(data) {
        if (!data.ok) { statusEl.textContent = data.error || 'offline'; return; }
        var shouldRender = false;
        if (data.topics) {
          var nextTopicsKey = JSON.stringify(data.topics);
          if (nextTopicsKey !== topicsKey) {
            topics = data.topics;
            topicsKey = nextTopicsKey;
            shouldRender = true;
          }
        }
        if (data.chats.length) {
          data.chats.forEach(function(m) { lastId = Math.max(lastId, parseInt(m.id, 10)); messages.push(m); });
          messages = messages.slice(-600);
          shouldRender = true;
        }
        if (shouldRender) {
          render();
        }
        statusEl.textContent = 'live';
      }).catch(function() { statusEl.textContent = 'offline'; });
    }

    render();
    poll();
    setInterval(poll, 1500);
  </script>
</body>
</html>
