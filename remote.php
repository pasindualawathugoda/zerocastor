<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$user = getLoggedInUser($pdo);

// Fetch settings for the Web Logo
$settings  = getSetting($pdo);
$siteTitle = $settings['site_name'] ?? 'Zerocastor TV';
$siteLogo  = !empty($settings['header_logo']) ? $settings['header_logo']
           : (!empty($settings['site_logo'])  ? $settings['site_logo']
           : 'https://zerocastor.com/assets/newlogo.png');

// Handle TV Deletion API via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_tv') {
    $code = trim($_POST['tv_code'] ?? '');
    if ($user && $code) {
        $stmt = $pdo->prepare("DELETE FROM tv_devices WHERE user_id = ? AND device_code = ?");
        $stmt->execute([(int)$user['id'], $code]);
        echo json_encode(['status' => 'ok']);
        exit;
    }
    echo json_encode(['status' => 'error']);
    exit;
}

// Fetch user's registered TVs
$userTvs = [];
if ($user) {
    $stmt = $pdo->prepare("SELECT * FROM tv_devices WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([(int)$user['id']]);
    $userTvs = $stmt->fetchAll();
}

// Fetch Categories and Channels
$catsStmt = $pdo->query("
    SELECT *
    FROM categories
    ORDER BY CASE WHEN LOWER(name) = 'local' THEN 0 ELSE 1 END, sort_order ASC, name ASC
");
$cats = $catsStmt->fetchAll();

$channelsByCat = [];
foreach ($cats as $cat) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM channels
        WHERE category_id = ? AND status = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([(int)$cat['id']]);
    $rows = $stmt->fetchAll();
    if ($rows) {
        $channelsByCat[(int)$cat['id']] = $rows;
    }
}

function js(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= e($siteTitle) ?> Remote</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* ═══════════════════ iOS REMOTE TOKENS ═══════════════════ */
:root {
    --bg: #000000;
    --bg-secondary: #1c1c1e;
    --bg-tertiary: #2c2c2e;
    --accent: #0a84ff;
    --text: #ffffff;
    --muted: #8e8e93;
    --separator: rgba(84, 84, 88, 0.65);
    --red: #ff453a;
}

* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

html, body {
    margin: 0; padding: 0;
    background-color: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", sans-serif;
    user-select: none; /* Prevent text selection */
    touch-action: manipulation; /* Completely disables double-tap to zoom */
    overscroll-behavior-y: none;
    padding-bottom: env(safe-area-inset-bottom);
    padding-top: env(safe-area-inset-top);
}

.container { max-width: 500px; margin: 0 auto; height: 100vh; display: flex; flex-direction: column; position: relative; overflow: hidden; }

/* ═══════════════════ LOGIN VIEW (FIXED) ═══════════════════ */
#loginView {
    position: fixed; inset: 0; z-index: 2000;
    background-color: var(--bg);
    display: <?= $user ? 'none' : 'flex' ?>;
    flex-direction: column; justify-content: center; align-items: center;
    padding: 30px; text-align: center; overflow-y: auto;
}
.login-logo { width: 140px; height: 140px; object-fit: contain; margin-bottom: 20px; filter: drop-shadow(0 4px 15px rgba(255,255,255,0.1)); }
.login-title { font-size: 32px; font-weight: 700; margin: 0 0 8px; letter-spacing: 0.5px; }
.login-sub { font-size: 16px; color: var(--muted); margin-bottom: 40px; }

.input-group { width: 100%; max-width: 400px; margin-bottom: 16px; position: relative; }
.input-group i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 20px; }
.ios-input {
    width: 100%; height: 60px; border-radius: 14px; border: none; outline: none;
    background: var(--bg-secondary); color: #fff; font-size: 17px; font-family: inherit;
    padding: 0 16px 0 50px; transition: background 0.2s;
}
.ios-input:focus { background: var(--bg-tertiary); }

.ios-btn-primary {
    width: 100%; max-width: 400px; height: 60px; border-radius: 14px; border: none; outline: none;
    background: var(--accent); color: #fff; font-size: 17px; font-weight: 600;
    margin-top: 15px; cursor: pointer; transition: transform 0.15s cubic-bezier(0.2, 0.8, 0.2, 1), opacity 0.15s;
}
.ios-btn-primary:active { transform: scale(0.92); opacity: 0.8; }
.auth-msg { margin-top: 20px; font-size: 15px; min-height: 20px; }
.auth-msg.err { color: var(--red); }

/* ═══════════════════ REMOTE VIEW ═══════════════════ */
#remoteView {
    display: <?= $user ? 'flex' : 'none' ?>;
    flex-direction: column; height: 100%;
}

/* Header TV Selector */
.remote-header {
    display: flex; justify-content: center; align-items: center;
    padding: 15px 20px; border-bottom: 0.5px solid var(--separator);
    background: rgba(0,0,0,0.85); backdrop-filter: blur(20px);
    position: sticky; top: 0; z-index: 100;
}
.tv-selector-btn {
    display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.08); border: none;
    color: var(--text); font-size: 15px; font-weight: 600; cursor: pointer;
    padding: 8px 16px; border-radius: 20px; transition: transform 0.15s cubic-bezier(0.2, 0.8, 0.2, 1), background 0.15s;
}
.tv-selector-btn:active { transform: scale(0.92); background: rgba(255,255,255,0.15); }
.tv-selector-btn i { font-size: 12px; color: var(--muted); transition: transform 0.3s; }
.tv-selector-btn.open i { transform: rotate(180deg); }

/* Remote Scroll Body */
.remote-body { flex: 1; overflow-y: auto; padding: 20px; }
.remote-body::-webkit-scrollbar { display: none; }

/* Top Actions */
.action-row { display: flex; justify-content: space-between; margin-bottom: 30px; gap: 15px; }
.action-btn {
    flex: 1; height: 65px; border-radius: 16px; background: var(--bg-secondary); border: none;
    color: var(--text); font-size: 24px; display: flex; flex-direction: column; align-items: center; justify-content: center;
    transition: transform 0.15s cubic-bezier(0.2, 0.8, 0.2, 1), background 0.15s; cursor: pointer;
}
.action-btn span { font-size: 11px; font-weight: 500; margin-top: 4px; color: var(--muted); transition: color 0.15s; }
.action-btn:active { background: var(--bg-tertiary); transform: scale(0.88); }
.action-btn.power { color: var(--red); background: rgba(255, 69, 58, 0.15); }
.action-btn.power:active { background: rgba(255, 69, 58, 0.3); }

/* iOS Trackpad / D-PAD */
.dpad-wrapper {
    width: 280px; height: 280px; margin: 0 auto 35px; position: relative;
    background: var(--bg-secondary); border-radius: 50%;
    box-shadow: inset 0 2px 10px rgba(255,255,255,0.05), 0 10px 30px rgba(0,0,0,0.5);
}
.dpad-btn {
    position: absolute; border: none; background: transparent; color: var(--text); font-size: 32px;
    display: flex; align-items: center; justify-content: center; cursor: pointer; 
    transition: transform 0.15s cubic-bezier(0.2, 0.8, 0.2, 1), opacity 0.1s;
}
.dpad-btn:active { transform: scale(0.8); opacity: 0.5; }
.dpad-up { top: 0; left: 25%; width: 50%; height: 25%; }
.dpad-down { bottom: 0; left: 25%; width: 50%; height: 25%; }
.dpad-left { left: 0; top: 25%; width: 25%; height: 50%; }
.dpad-right { right: 0; top: 25%; width: 25%; height: 50%; }
.dpad-ok {
    top: 25%; left: 25%; width: 50%; height: 50%; border-radius: 50%;
    background: var(--bg-tertiary); font-size: 20px; font-weight: 700;
    transition: transform 0.15s cubic-bezier(0.2, 0.8, 0.2, 1), background 0.15s;
}
.dpad-ok:active { background: #48484a; transform: scale(0.85); }

/* Modern Vertical Rockers */
.rocker-container { display: flex; justify-content: center; gap: 40px; margin-bottom: 40px; }
.modern-rocker {
    width: 70px; height: 170px; background: var(--bg-secondary);
    border-radius: 35px; display: flex; flex-direction: column;
    box-shadow: inset 0 2px 10px rgba(255,255,255,0.05), 0 8px 25px rgba(0,0,0,0.4);
    position: relative; overflow: hidden;
}
.modern-rocker-btn {
    flex: 1; border: none; background: transparent; color: var(--text);
    font-size: 28px; display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: background 0.1s;
}
.modern-rocker-icon { pointer-events: none; transition: transform 0.15s cubic-bezier(0.2, 0.8, 0.2, 1); }
.modern-rocker-btn:active { background: rgba(255,255,255,0.08); }
.modern-rocker-btn:active .modern-rocker-icon { transform: scale(0.7); }

.rocker-label {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    font-size: 11px; font-weight: 700; color: var(--muted); letter-spacing: 1px;
    pointer-events: none; background: var(--bg-secondary); padding: 4px; border-radius: 10px;
}

/* ═══════════════════ SEARCH & GUIDE ═══════════════════ */
.guide-title { font-size: 22px; font-weight: 700; margin-bottom: 15px; }

.search-box {
    display: flex; align-items: center; background: var(--bg-secondary);
    border-radius: 12px; padding: 0 12px; height: 44px; margin-bottom: 20px;
}
.search-box i { color: var(--muted); font-size: 18px; margin-right: 8px; }
.search-box input {
    flex: 1; background: transparent; border: none; outline: none;
    color: var(--text); font-size: 17px; font-family: inherit;
}
.search-box input::placeholder { color: var(--muted); }

.cat-group { margin-bottom: 20px; }
.cat-title { font-size: 13px; font-weight: 600; color: var(--muted); text-transform: uppercase; margin-bottom: 10px; margin-left: 5px; }
.ch-list { display: flex; flex-direction: column; gap: 8px; }
.ch-item {
    display: flex; align-items: center; gap: 15px; padding: 12px 16px; border-radius: 16px;
    background: var(--bg-secondary); cursor: pointer; 
    transition: transform 0.15s cubic-bezier(0.2, 0.8, 0.2, 1), background 0.15s;
}
.ch-item:active { transform: scale(0.94); background: var(--bg-tertiary); }
.ch-item img { width: 44px; height: 44px; border-radius: 10px; background: #000; object-fit: contain; }
.ch-info { flex: 1; }
.ch-name { font-size: 17px; font-weight: 600; margin-bottom: 2px; }
.ch-num { font-size: 13px; color: var(--muted); }

/* ═══════════════════ iOS BOTTOM SHEET (TV SELECTOR) ═══════════════════ */
.sheet-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000;
    opacity: 0; pointer-events: none; transition: opacity 0.3s;
}
.sheet-overlay.show { opacity: 1; pointer-events: auto; }

.bottom-sheet {
    position: fixed; bottom: 0; left: 0; right: 0; max-width: 500px; margin: 0 auto;
    background: #1c1c1e; border-radius: 28px 28px 0 0; padding: 15px 20px 40px;
    transform: translateY(100%); transition: transform 0.35s cubic-bezier(0.32, 0.72, 0, 1);
    z-index: 1001; box-shadow: 0 -10px 40px rgba(0,0,0,0.5);
}
.bottom-sheet.show { transform: translateY(0); }

.sheet-handle { width: 40px; height: 5px; background: #48484a; border-radius: 5px; margin: 0 auto 20px; }
.sheet-title { font-size: 20px; font-weight: 700; text-align: center; margin-bottom: 20px; }

.tv-list { display: flex; flex-direction: column; gap: 10px; max-height: 40vh; overflow-y: auto; }
.tv-list::-webkit-scrollbar { display: none; }

.tv-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px; background: var(--bg-tertiary); border-radius: 16px;
    transition: transform 0.15s cubic-bezier(0.2, 0.8, 0.2, 1);
}
.tv-item-info-wrap { flex: 1; display: flex; align-items: center; gap: 12px; cursor: pointer; }
.tv-item:active { transform: scale(0.95); }

.tv-item-icon { font-size: 24px; color: var(--muted); }
.tv-item.active .tv-item-icon { color: var(--accent); }
.tv-item-name { font-size: 17px; font-weight: 500; }
.tv-item-code { font-size: 13px; color: var(--muted); margin-top: 2px; }
.tv-check { color: var(--accent); font-size: 20px; display: none; margin-right: 10px; }
.tv-item.active .tv-check { display: block; }

.delete-tv-btn {
    background: rgba(255, 69, 58, 0.15); border: none; width: 44px; height: 44px;
    border-radius: 12px; color: var(--red); font-size: 18px;
    display: flex; align-items: center; justify-content: center; cursor: pointer;
    transition: background 0.2s, transform 0.15s cubic-bezier(0.2, 0.8, 0.2, 1);
}
.delete-tv-btn:active { background: rgba(255, 69, 58, 0.3); transform: scale(0.85); }

.btn-logout {
    width: 100%; height: 56px; border-radius: 14px; border: none; background: rgba(255, 69, 58, 0.1);
    color: var(--red); font-size: 17px; font-weight: 600; margin-top: 20px; cursor: pointer;
    transition: background 0.15s, transform 0.15s cubic-bezier(0.2, 0.8, 0.2, 1);
}
.btn-logout:active { background: rgba(255, 69, 58, 0.2); transform: scale(0.95); }
</style>
</head>
<body ontouchstart="">

<div class="container">

    <div id="loginView">
        <img src="<?= e($siteLogo) ?>" alt="TV Logo" class="login-logo">
        <h2 class="login-title"><?= e($siteTitle) ?></h2>
        <p class="login-sub">Sign in to automatically sync with your TVs.</p>

        <div class="input-group">
            <i class="bi bi-telephone-fill"></i>
            <input type="text" id="lM" class="ios-input" placeholder="Mobile Number" inputmode="numeric">
        </div>
        <div class="input-group">
            <i class="bi bi-lock-fill"></i>
            <input type="password" id="lP" class="ios-input" placeholder="Password">
        </div>
        
        <button class="ios-btn-primary" onclick="doLogin()">Sign In</button>
        <div class="auth-msg" id="lMsg"></div>
    </div>

    <?php if ($user): ?>
    <div id="remoteView">
        
        <div class="remote-header">
            <button class="tv-selector-btn" id="tvSelectorBtn" onclick="toggleTvSheet()">
                <i class="bi bi-display"></i>
                <span id="activeTvName">Connecting...</span>
                <i class="bi bi-chevron-down" id="tvChevron"></i>
            </button>
        </div>

        <div class="remote-body">
            
            <div class="action-row">
                <button class="action-btn" onclick="toggleLocalFullscreen(); triggerCmd('fullscreen');">
                    <i class="bi bi-arrows-fullscreen"></i>
                    <span>Expand</span>
                </button>
                <button class="action-btn power" onclick="triggerCmd('channel', '2')">
                    <i class="bi bi-house-door-fill"></i>
                    <span>Home</span>
                </button>
                <button class="action-btn" onclick="triggerCmd('mute')">
                    <i class="bi bi-volume-mute-fill"></i>
                    <span>Mute</span>
                </button>
            </div>

            <div class="dpad-wrapper">
                <button class="dpad-btn dpad-up" onclick="triggerCmd('volume_up')"><i class="bi bi-chevron-up"></i></button>
                <button class="dpad-btn dpad-down" onclick="triggerCmd('volume_down')"><i class="bi bi-chevron-down"></i></button>
                <button class="dpad-btn dpad-left" onclick="triggerCmd('prev')"><i class="bi bi-chevron-left"></i></button>
                <button class="dpad-btn dpad-right" onclick="triggerCmd('next')"><i class="bi bi-chevron-right"></i></button>
                <button class="dpad-btn dpad-ok" onclick="triggerCmd('channel', '2')">OK</button>
            </div>

            <div class="rocker-container">
                <div class="modern-rocker">
                    <button class="modern-rocker-btn" onclick="triggerCmd('volume_up')">
                        <i class="bi bi-plus-lg modern-rocker-icon"></i>
                    </button>
                    <div class="rocker-label">VOL</div>
                    <button class="modern-rocker-btn" onclick="triggerCmd('volume_down')">
                        <i class="bi bi-dash-lg modern-rocker-icon"></i>
                    </button>
                </div>
                <div class="modern-rocker">
                    <button class="modern-rocker-btn" onclick="triggerCmd('next')">
                        <i class="bi bi-chevron-up modern-rocker-icon"></i>
                    </button>
                    <div class="rocker-label">CH</div>
                    <button class="modern-rocker-btn" onclick="triggerCmd('prev')">
                        <i class="bi bi-chevron-down modern-rocker-icon"></i>
                    </button>
                </div>
            </div>

            <div class="guide-title">TV Guide</div>
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="chSearch" placeholder="Search Channels..." onkeyup="filterChannels()">
            </div>

            <div id="guideContainer">
                <?php foreach ($cats as $cat): ?>
                    <?php $catId = (int)$cat['id']; if (empty($channelsByCat[$catId])) continue; ?>
                    <div class="cat-group">
                        <div class="cat-title"><?= e($cat['name']) ?></div>
                        <div class="ch-list">
                            <?php foreach ($channelsByCat[$catId] as $ch): ?>
                                <div class="ch-item" onclick="triggerCmd('channel','<?= (int)$ch['id'] ?>')">
                                    <img src="<?= e((string)$ch['channel_image']) ?>" alt="" loading="lazy">
                                    <div class="ch-info">
                                        <div class="ch-name"><?= e($ch['channel_name']) ?></div>
                                        <div class="ch-num">CH <?= (int)$ch['id'] ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div style="height: 40px;"></div> </div>
    </div>
    
    <div class="sheet-overlay" id="sheetOverlay" onclick="toggleTvSheet()"></div>
    <div class="bottom-sheet" id="tvSheet">
        <div class="sheet-handle"></div>
        <div class="sheet-title">My TVs</div>
        
        <div class="tv-list" id="tvListContainer">
            <?php if(empty($userTvs)): ?>
                <div style="text-align:center; color:var(--muted); padding:20px; font-size: 15px;">
                    No TVs linked to this account. Open the TV app and login first.
                </div>
            <?php else: ?>
                <?php foreach($userTvs as $idx => $tv): ?>
                    <div class="tv-item" id="tv-row-<?= e($tv['device_code']) ?>" data-code="<?= e($tv['device_code']) ?>">
                        
                        <div class="tv-item-info-wrap" onclick="selectTv('<?= e($tv['device_code']) ?>')">
                            <i class="bi bi-display tv-item-icon"></i>
                            <div>
                                <div class="tv-item-name">TV Unit <?= $idx + 1 ?></div>
                                <div class="tv-item-code">Code: <?= e($tv['device_code']) ?></div>
                            </div>
                        </div>

                        <i class="bi bi-check-circle-fill tv-check"></i>
                        
                        <button class="delete-tv-btn" onclick="deleteTv('<?= e($tv['device_code']) ?>')" title="Remove TV">
                            <i class="bi bi-trash-fill"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <button class="btn-logout" onclick="doLogout()">Sign Out</button>
    </div>
    <?php endif; ?>

</div>

<script>
/* ══════════════════════════════════
   HAPTIC FEEDBACK (iOS Feel)
══════════════════════════════════ */
function haptic() {
    if (navigator.vibrate) {
        navigator.vibrate(30); // Quick sharp tap
    }
}

/* ══════════════════════════════════
   LOCAL FULLSCREEN TOGGLE
══════════════════════════════════ */
function toggleLocalFullscreen() {
    haptic();
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(err => {
            console.log("Error attempting to enable full-screen mode:", err.message);
        });
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    }
}

/* ══════════════════════════════════
   AUTH LOGIC
══════════════════════════════════ */
async function doLogin() {
    haptic();
    const m = document.getElementById('lMsg'); 
    m.className = 'auth-msg'; 
    m.textContent = 'Authenticating...';
    
    const mob = document.getElementById('lM').value.trim();
    const pass = document.getElementById('lP').value.trim();

    try {
        const r = await fetch('api/auth.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=login&mobile=' + encodeURIComponent(mob) + '&password=' + encodeURIComponent(pass)
        });
        const j = await r.json();
        if (j.status === 'ok') {
            m.textContent = 'Success! Syncing TVs...';
            setTimeout(() => location.reload(), 500);
        } else {
            m.className = 'auth-msg err';
            m.textContent = j.message || 'Login failed';
        }
    } catch(e) {
        m.className = 'auth-msg err';
        m.textContent = 'Network error';
    }
}

async function doLogout() {
    haptic();
    try {
        await fetch('api/auth.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=logout'
        });
    } catch(e) {}
    localStorage.removeItem('zerocast_tv_code');
    location.reload();
}

/* ══════════════════════════════════
   TV SELECTION & MANAGEMENT
══════════════════════════════════ */
let activeTvCode = '';

function toggleTvSheet() {
    haptic();
    const sheet = document.getElementById('tvSheet');
    const overlay = document.getElementById('sheetOverlay');
    const btn = document.getElementById('tvSelectorBtn');
    
    if (sheet.classList.contains('show')) {
        sheet.classList.remove('show');
        overlay.classList.remove('show');
        btn.classList.remove('open');
    } else {
        sheet.classList.add('show');
        overlay.classList.add('show');
        btn.classList.add('open');
    }
}

function selectTv(code) {
    haptic();
    activeTvCode = code;
    localStorage.setItem('zerocast_tv_code', code);
    
    document.getElementById('activeTvName').textContent = "TV (" + code + ")";
    
    document.querySelectorAll('.tv-item').forEach(el => {
        if (el.dataset.code === code) {
            el.classList.add('active');
        } else {
            el.classList.remove('active');
        }
    });

    const sheet = document.getElementById('tvSheet');
    if(sheet.classList.contains('show')) {
        toggleTvSheet();
    }
}

async function deleteTv(code) {
    haptic();
    if(!confirm("Remove this TV from your account?")) return;

    try {
        const r = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=delete_tv&tv_code=' + encodeURIComponent(code)
        });
        const j = await r.json();
        
        if (j.status === 'ok') {
            const row = document.getElementById('tv-row-' + code);
            if (row) row.remove();

            if (activeTvCode === code) {
                localStorage.removeItem('zerocast_tv_code');
                activeTvCode = '';
                initTvConnection();
            }
        } else {
            alert("Failed to remove TV.");
        }
    } catch(e) {
        alert("Network error.");
    }
}

function initTvConnection() {
    const tvItems = document.querySelectorAll('.tv-item');
    if (tvItems.length === 0) {
        document.getElementById('activeTvName').textContent = "No TV Found";
        return;
    }

    const savedCode = localStorage.getItem('zerocast_tv_code');
    let found = false;
    
    if (savedCode) {
        tvItems.forEach(el => {
            if (el.dataset.code === savedCode) found = true;
        });
    }

    if (found) {
        selectTv(savedCode);
    } else {
        selectTv(tvItems[0].dataset.code);
    }
}

/* ══════════════════════════════════
   SEND COMMANDS
══════════════════════════════════ */
async function triggerCmd(command, payload = '') {
    haptic(); 
    
    if (!activeTvCode) {
        alert("Please connect to a TV first.");
        toggleTvSheet();
        return;
    }

    const form = new URLSearchParams();
    form.append('tv_code', activeTvCode);
    form.append('command', command);
    if (payload !== '') form.append('payload', payload);

    try {
        fetch('api/remote-command.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: form.toString()
        });
    } catch(e) {
        console.error("Command failed", e);
    }
}

/* ══════════════════════════════════
   CHANNEL SEARCH
══════════════════════════════════ */
function filterChannels() {
    const query = document.getElementById('chSearch').value.toLowerCase();
    const categories = document.querySelectorAll('.cat-group');

    categories.forEach(cat => {
        const channels = cat.querySelectorAll('.ch-item');
        let hasVisible = false;

        channels.forEach(ch => {
            const name = ch.querySelector('.ch-name').textContent.toLowerCase();
            const num = ch.querySelector('.ch-num').textContent.toLowerCase();
            
            if (name.includes(query) || num.includes(query)) {
                ch.style.display = 'flex';
                hasVisible = true;
            } else {
                ch.style.display = 'none';
            }
        });

        cat.style.display = hasVisible ? 'block' : 'none';
    });
}

// Init
document.addEventListener('DOMContentLoaded', () => {
    <?php if($user): ?>
    initTvConnection();
    <?php endif; ?>
});
</script>
</body>
</html>