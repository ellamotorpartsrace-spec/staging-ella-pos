<?php
$page_title = 'Inventory Backup & Recovery';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireLogin();
if (!in_array($_SESSION['role'], ['admin', 'super_admin'])) { denyAccess('Admin access required.'); }
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<style>
/* ── DESIGN TOKENS ─────────────────────────────────────────────────────── */
:root{
  --brs-indigo:#6366f1; --brs-emerald:#10b981; --brs-red:#ef4444;
  --brs-amber:#f59e0b;  --brs-sky:#0ea5e9;     --brs-rose:#dc2626;
  --brs-radius:14px;    --brs-speed:.2s ease;
}

/* ── BANNER ──────────────────────────────────────────────────────────────── */
.brs-banner{
  background:linear-gradient(130deg,#1e1b4b 0%,#3730a3 55%,#4f46e5 100%);
  border-radius:var(--brs-radius); padding:28px 30px; margin-bottom:24px;
  position:relative; overflow:hidden; color:#fff;
}
.brs-banner::before,.brs-banner::after{
  content:''; position:absolute; border-radius:50%;
  background:rgba(255,255,255,.05); pointer-events:none;
}
.brs-banner::before{width:260px;height:260px;top:-80px;right:-60px;}
.brs-banner::after {width:180px;height:180px;bottom:-60px;left:25%;}
.brs-banner-title{font-size:1.6rem;font-weight:800;margin:0;letter-spacing:-.01em;position:relative;color:#ffffff;}
.brs-banner-desc {color:rgba(255,255,255,.9);font-size:.9rem;margin-top:8px;position:relative}

/* ── STAT CARDS ──────────────────────────────────────────────────────────── */
.brs-card{
  background:var(--card-bg,#fff); border-radius:var(--brs-radius);
  border:1px solid var(--border-color,#e5e7eb);
  box-shadow:0 1px 4px rgba(0,0,0,.06); transition:var(--brs-speed);
  position:relative; overflow:hidden;
}
.brs-card:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(0,0,0,.1);}
.brs-stat-bar{position:absolute;top:0;left:0;width:100%;height:4px;border-radius:14px 14px 0 0;}
.brs-stat-icon{position:absolute;bottom:12px;right:14px;font-size:2.6rem;opacity:.25;}
.brs-stat-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;
  color:var(--text-secondary,#6b7280);margin-bottom:6px;}
.brs-stat-val{font-size:1.75rem;font-weight:800;line-height:1;color:var(--text-primary,#111);}
.brs-stat-sub{font-size:.72rem;color:var(--text-secondary,#6b7280);margin-top:5px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

/* ── TABS ────────────────────────────────────────────────────────────────── */
.brs-tabs{
  display:flex; background:var(--card-bg,#fff);
  border:1px solid var(--border-color,#e5e7eb);
  border-radius:var(--brs-radius) var(--brs-radius) 0 0;
  border-bottom:none; overflow-x:auto; flex-wrap:nowrap;
}
.brs-tabs .nav-link{
  color:var(--text-secondary,#6b7280); font-weight:600; font-size:.82rem;
  padding:14px 18px; border:none; border-bottom:3px solid transparent;
  border-radius:0; white-space:nowrap; transition:var(--brs-speed);
  display:flex; align-items:center; gap:7px; flex-shrink:0;
}
.brs-tabs .nav-link:hover{color:var(--brs-indigo);background:rgba(99,102,241,.04);}
.brs-tabs .nav-link.active{color:var(--brs-indigo);border-bottom-color:var(--brs-indigo);background:rgba(99,102,241,.05);}
.brs-tabs .nav-link i{font-size:.88rem;}

/* ── TAB CONTENT WRAPPER ────────────────────────────────────────────────── */
.brs-pane{
  background:var(--card-bg,#fff);
  border:1px solid var(--border-color,#e5e7eb);
  border-top:none; border-radius:0 0 var(--brs-radius) var(--brs-radius);
}

/* ── TOOLBAR ─────────────────────────────────────────────────────────────── */
.brs-toolbar{
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  padding:14px 20px; background:var(--bg-surface,#f8f9fb);
  border-bottom:1px solid var(--border-color,#e5e7eb);
}
.brs-toolbar-title{
  font-size:.72rem; font-weight:800; text-transform:uppercase;
  letter-spacing:.07em; color:var(--text-secondary,#6b7280); margin:0;
}

/* ── SNAPSHOT ROW ───────────────────────────────────────────────────────── */
.snap-row{
  display:flex; align-items:center; gap:14px;
  padding:15px 20px; border-bottom:1px solid var(--border-color,#e5e7eb);
  transition:background var(--brs-speed);
}
.snap-row:last-child{border-bottom:none;}
.snap-row:hover{background:var(--bg-surface,#f8f9fb);}

.snap-avatar{
  width:44px; height:44px; border-radius:11px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:1.1rem;
}
.snap-avatar.manual    {background:rgba(99,102,241,.12);color:var(--brs-indigo);}
.snap-avatar.auto      {background:rgba(16,185,129,.12);color:var(--brs-emerald);}
.snap-avatar.pre_restore{background:rgba(245,158,11,.12);color:var(--brs-amber);}

.snap-name{font-weight:700;font-size:.875rem;color:var(--text-primary,#111);
  max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.snap-meta{font-size:.73rem;color:var(--text-secondary,#6b7280);margin-top:3px;display:flex;gap:10px;flex-wrap:wrap;}
.snap-meta-item{display:flex;align-items:center;gap:4px;}

/* TYPE BADGE */
.snap-type{
  display:inline-flex; align-items:center; gap:5px;
  padding:4px 11px; border-radius:20px; font-size:.69rem; font-weight:700; flex-shrink:0;
}
.snap-type.manual    {background:rgba(99,102,241,.1); color:var(--brs-indigo);}
.snap-type.auto      {background:rgba(16,185,129,.1); color:var(--brs-emerald);}
.snap-type.pre_restore{background:rgba(245,158,11,.1);color:var(--brs-amber);}

/* ── EMPTY STATE ─────────────────────────────────────────────────────────── */
.brs-empty{text-align:center;padding:56px 24px;color:var(--text-secondary,#6b7280);}
.brs-empty-icon{font-size:3.2rem;opacity:.15;margin-bottom:14px;}
.brs-empty h6{font-weight:800;font-size:1rem;color:var(--text-primary,#111);margin-bottom:6px;}
.brs-empty p{font-size:.85rem;max-width:340px;margin:0 auto;}

/* ── TABLES ──────────────────────────────────────────────────────────────── */
.brs-table thead th{
  font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
  padding:16px 18px; background:var(--bg-surface,#f8f9fb);
  border-bottom:2px solid var(--border-color,#e5e7eb);
  color:var(--text-secondary,#4b5563); white-space:nowrap;
}
.brs-table tbody td{
  font-size:.88rem; padding:16px 18px; vertical-align:middle;
  border-bottom:1px solid var(--border-color,#e5e7eb);
  color:var(--text-primary,#111);
}
.brs-table tbody tr:last-child td{border-bottom:none;}
.brs-table tbody tr:hover td{background:var(--bg-surface,#f8f9fb);}

/* Diff colours */
.diff-changed td{background:rgba(245,158,11,.05)!important;}
.diff-added   td{background:rgba(16,185,129,.05)!important;}
.diff-removed td{background:rgba(239,68,68,.05)!important;}

/* ── CHIPS ───────────────────────────────────────────────────────────────── */
.chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;
  border-radius:20px;font-size:.69rem;font-weight:700;white-space:nowrap;}
.chip-changed{background:rgba(245,158,11,.15);color:#b45309;}
.chip-added  {background:rgba(16,185,129,.15); color:#047857;}
.chip-removed{background:rgba(239,68,68,.15);  color:#b91c1c;}
.chip-create {background:rgba(99,102,241,.1);  color:var(--brs-indigo);}
.chip-restore{background:rgba(245,158,11,.12); color:#b45309;}
.chip-emergency{background:rgba(239,68,68,.12);color:var(--brs-rose);}
.chip-delete {background:rgba(239,68,68,.12);  color:var(--brs-rose);}
.chip-auto   {background:rgba(16,185,129,.12); color:#047857;}
.chip-resync {background:rgba(14,165,233,.12); color:#0369a1;}

/* delta text */
.d-pos{color:#059669;font-weight:700;}
.d-neg{color:#dc2626;font-weight:700;}
.d-zero{color:var(--text-secondary,#6b7280);}

/* ── COMPARISON HEADER CARDS ─────────────────────────────────────────────── */
.cmp-snap-card{
  border-radius:10px; padding:14px 16px;
  border:1px solid var(--border-color,#e5e7eb);
  background:var(--bg-surface,#f8f9fb);
}
.cmp-snap-card.a-card{border-left:4px solid #94a3b8;}
.cmp-snap-card.b-card{border-left:4px solid var(--brs-indigo);}
.cmp-snap-label{font-size:.68rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.07em;color:var(--text-secondary);margin-bottom:4px;}
.cmp-snap-name{font-weight:700;font-size:.875rem;color:var(--text-primary);}
.cmp-snap-date{font-size:.73rem;color:var(--text-secondary);margin-top:2px;}

/* Summary pills row */
.cmp-summary{
  display:flex; flex-wrap:wrap; gap:10px; align-items:center;
  padding:14px 20px; background:var(--bg-surface,#f8f9fb);
  border-top:1px solid var(--border-color); border-bottom:1px solid var(--border-color);
}
.cmp-pill{display:inline-flex;align-items:center;gap:6px;
  padding:6px 14px;border-radius:20px;font-size:.78rem;font-weight:700;}
.cmp-pill.changed{background:rgba(245,158,11,.12);color:#b45309;}
.cmp-pill.added  {background:rgba(16,185,129,.12); color:#047857;}
.cmp-pill.removed{background:rgba(239,68,68,.12);  color:#b91c1c;}
.cmp-pill.same   {background:rgba(107,114,128,.1); color:#4b5563;}

/* ── EMERGENCY BANNER ────────────────────────────────────────────────────── */
.em-banner{
  background:linear-gradient(135deg,#7f1d1d,#991b1b);
  border-radius:12px; padding:24px; color:#fff; text-align:center;
}
.em-banner i.em-icon{font-size:2.4rem;display:block;margin-bottom:10px;}

/* ── LOG TERMINAL ────────────────────────────────────────────────────────── */
.log-term{
  background:#0f172a; color:#cbd5e1; font-family:'SF Mono','Fira Code',monospace;
  font-size:.79rem; border-radius:10px; padding:16px;
  max-height:230px; overflow-y:auto; border:1px solid rgba(255,255,255,.07);
}
.log-term .ll{margin-bottom:4px;line-height:1.5;}
.log-term .ok {color:#4ade80;} .log-term .err{color:#f87171;}
.log-term .inf{color:#93c5fd;} .log-term .wrn{color:#fbbf24;}

/* ── MODALS ──────────────────────────────────────────────────────────────── */
.modal-content{border:none;border-radius:16px;overflow:hidden;
  box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-header{border-bottom:1px solid var(--border-color,#e5e7eb);padding:20px 24px;}
.modal-body  {padding:24px;}
.modal-footer{border-top:1px solid var(--border-color,#e5e7eb);padding:16px 24px;}

/* Step wizard dots */
.steps{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:24px;}
.step-dot{
  width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:.72rem;font-weight:800;border:2px solid var(--border-color,#e5e7eb);
  color:var(--text-secondary);background:var(--card-bg,#fff);
  transition:var(--brs-speed);flex-shrink:0;z-index:1;
}
.step-line{flex:1;height:2px;background:var(--border-color,#e5e7eb);max-width:60px;transition:background .3s;}
.step-dot.s-on {border-color:var(--brs-indigo);background:var(--brs-indigo);color:#fff;}
.step-dot.s-done{border-color:var(--brs-emerald);background:var(--brs-emerald);color:#fff;}
.step-dot.e-on {border-color:var(--brs-red);background:var(--brs-red);color:#fff;}
.step-line.s-done{background:var(--brs-emerald);}
.step-line.e-done{background:var(--brs-red);}

/* Stage panels */
.m-stage{display:none;animation:stageIn .22s ease;}
.m-stage.on{display:block;}
@keyframes stageIn{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}

/* Confirm box */
.c-box{
  border:2px solid var(--border-color,#e5e7eb);border-radius:10px;
  padding:11px 15px;font-family:'SF Mono','Fira Code',monospace;
  font-size:.95rem;letter-spacing:.06em;width:100%;outline:none;
  background:var(--bg-surface,#f8f9fb);color:var(--text-primary,#111);
  transition:border-color .2s;
}
.c-box.ok {border-color:var(--brs-emerald)!important;background:rgba(16,185,129,.04);}
.c-box.bad{border-color:var(--brs-red)!important;}
.c-hint{font-size:.76rem;color:var(--text-secondary);margin-top:7px;}
.c-hint code{
  background:var(--bg-surface);border:1px solid var(--border-color);
  padding:1px 6px;border-radius:5px;font-weight:800;letter-spacing:.07em;
}

/* Info boxes */
.ib{border-radius:10px;padding:14px 16px;border:1px solid var(--border-color);background:var(--bg-surface);}
.ib-success{background:rgba(16,185,129,.05);border-color:rgba(16,185,129,.25);}
.ib-primary{background:rgba(99,102,241,.05);border-color:rgba(99,102,241,.25);}
.ib-warning{background:rgba(245,158,11,.05);border-color:rgba(245,158,11,.25);}
.ib-danger {background:rgba(239,68,68,.05); border-color:rgba(239,68,68,.25);}

/* Mini spinner */
.spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.35);
  border-top-color:#fff;border-radius:50%;animation:rtn .65s linear infinite;}
@keyframes rtn{to{transform:rotate(360deg)}}

/* Settings toggle buttons */
.tgl-btn{cursor:pointer;border-radius:8px!important;transition:var(--brs-speed)!important;
  font-weight:600!important;}
.tgl-btn.active{
  background:var(--brs-indigo)!important;border-color:var(--brs-indigo)!important;
  color:#fff!important;
}

/* Section description boxes */
.section-desc{
  padding:20px 24px; background:var(--bg-surface,#f8f9fb);
  border-bottom:1px solid var(--border-color,#e5e7eb);
  font-size:.9rem; color:var(--text-primary,#111);
  display:flex; align-items:flex-start; gap:12px;
}
.section-desc i{font-size:1.15rem;flex-shrink:0;margin-top:3px;}

/* Recovery step instruction */
.how-step{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;}
.how-num{width:26px;height:26px;border-radius:50%;background:var(--brs-indigo);color:#fff;
  font-size:.72rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.how-text{font-size:.8rem;color:var(--text-secondary);padding-top:4px;}
.how-text strong{color:var(--text-primary);}

/* Audit action colour map */
.audit-row-RESTORE       td:first-child{border-left:4px solid var(--brs-amber);}
.audit-row-EMERGENCY_RESTORE td:first-child{border-left:4px solid var(--brs-rose);}
.audit-row-DELETE_SNAPSHOT td:first-child{border-left:4px solid var(--brs-red);}
.audit-row-CREATE_SNAPSHOT td:first-child{border-left:4px solid var(--brs-indigo);}
.audit-row-AUTO_SNAPSHOT  td:first-child{border-left:4px solid var(--brs-emerald);}
.audit-row-SHOPEE_RESYNC  td:first-child{border-left:4px solid var(--brs-sky);}

@media(max-width:640px){
  .brs-banner{padding:18px;} .brs-banner-title{font-size:1.1rem;}
  .snap-name{max-width:160px;} .brs-tabs .nav-link span.tab-t{display:none;}
}
</style>

<div class="container-fluid p-4">

<!-- ════ BANNER ══════════════════════════════════════════════════════════ -->
<div class="brs-banner mb-4">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
      <h1 class="brs-banner-title">
        <i class="fa-solid fa-shield-halved me-2" style="opacity:.8"></i>Inventory Backup &amp; Recovery
      </h1>
      <p class="brs-banner-desc mb-0">
        Captures real stock quantities (POS &amp; Shopee allocations) for every active product &nbsp;·&nbsp;
        <strong style="color:rgba(255,255,255,.9)">This is a STOCK backup — not just products</strong>
      </p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-shrink-0">
      <span class="badge px-3 py-2" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);color:#fff;font-size:.75rem">
        <i class="fa-solid fa-lock me-1"></i>Admin Only
      </span>
      <button class="btn btn-light fw-bold px-4" id="btnOpenCreate" style="border-radius:10px">
        <i class="fa-solid fa-camera me-1"></i>Create Snapshot
      </button>
    </div>
  </div>
</div>

<!-- ════ STAT CARDS ══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
  <?php
  $stats = [
    ['id'=>'statTotal',    'label'=>'Total Snapshots',    'color'=>'var(--brs-indigo)',  'icon'=>'fa-layer-group'],
    ['id'=>'statLastBkp',  'label'=>'Last Backup',        'color'=>'var(--brs-emerald)', 'icon'=>'fa-calendar-check'],
    ['id'=>'statProducts', 'label'=>'Products Protected', 'color'=>'var(--brs-sky)',     'icon'=>'fa-boxes-stacked'],
    ['id'=>'statLastRec',  'label'=>'Last Recovery',      'color'=>'var(--brs-amber)',   'icon'=>'fa-rotate-left'],
  ];
  foreach($stats as $s): ?>
  <div class="col-6 col-xl-3">
    <div class="brs-card p-4 h-100">
      <div class="brs-stat-bar" style="background:<?=$s['color']?>"></div>
      <i class="fa-solid <?=$s['icon']?> brs-stat-icon" style="color:<?=$s['color']?>"></i>
      <div class="brs-stat-label"><?=$s['label']?></div>
      <div class="brs-stat-val" id="<?=$s['id']?>" style="color:<?=$s['color']?>">—</div>
      <div class="brs-stat-sub" id="<?=$s['id']?>Sub">loading…</div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ════ TABS ════════════════════════════════════════════════════════════ -->
<ul class="nav brs-tabs" id="mainTabs" role="tablist">
  <?php $tabs=[
    ['href'=>'#t1','icon'=>'fa-layer-group',   'label'=>'Snapshot Manager'],
    ['href'=>'#t2','icon'=>'fa-code-compare',  'label'=>'Comparison'],
    ['href'=>'#t3','icon'=>'fa-rotate-left',   'label'=>'Recovery Center'],
    ['href'=>'#t4','icon'=>'fa-list-check',    'label'=>'Audit Logs'],
    ['href'=>'#t5','icon'=>'fa-gear',          'label'=>'Settings'],
  ];
  foreach($tabs as $i=>$t): ?>
  <li class="nav-item">
    <a class="nav-link <?=$i===0?'active':''?>" data-bs-toggle="tab" href="<?=$t['href']?>" role="tab">
      <i class="fa-solid <?=$t['icon']?>"></i>
      <span class="tab-t"><?=$t['label']?></span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="tab-content">

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 1 — SNAPSHOT MANAGER
══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade show active brs-pane" id="t1">
  <div class="section-desc">
    <i class="fa-solid fa-circle-info text-primary"></i>
    <span>
      A <strong>snapshot</strong> is a frozen copy of every product's <strong>Total Stock</strong>,
      <strong>Shopee Allocated Stock</strong>, and <strong>POS Stock</strong> numbers at a specific moment.
      You can restore these exact numbers later if something goes wrong.
    </span>
  </div>
  <div class="brs-toolbar">
    <div class="input-group" style="max-width:280px">
      <span class="input-group-text" style="background:var(--card-bg);border-color:var(--border-color)">
        <i class="fa-solid fa-magnifying-glass" style="font-size:.78rem;color:var(--text-secondary)"></i>
      </span>
      <input type="text" class="form-control form-control-sm" id="snapSearch" placeholder="Search snapshots…"
             style="background:var(--card-bg);color:var(--text-primary);border-color:var(--border-color)">
    </div>
    <select class="form-select form-select-sm" id="snapTypeFilter" style="width:160px;background:var(--card-bg);color:var(--text-primary);border-color:var(--border-color)">
      <option value="">All Types</option>
      <option value="manual">Manual</option>
      <option value="auto">Auto</option>
      <option value="pre_restore">Pre-Restore</option>
    </select>
    <span class="ms-auto small text-muted" id="snapCount"></span>
    <button class="btn btn-sm btn-outline-secondary" id="btnRefreshSnaps" title="Refresh">
      <i class="fa-solid fa-sync"></i>
    </button>
  </div>
  <div id="snapWrap">
    <!-- skeleton rows -->
    <div style="padding:15px 20px;border-bottom:1px solid var(--border-color)">
      <div style="display:flex;gap:14px;align-items:center">
        <div style="width:44px;height:44px;border-radius:11px;background:var(--border-color);opacity:.4;flex-shrink:0"></div>
        <div style="flex:1">
          <div style="height:13px;background:var(--border-color);border-radius:5px;width:55%;margin-bottom:8px;opacity:.4"></div>
          <div style="height:11px;background:var(--border-color);border-radius:5px;width:35%;opacity:.3"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 2 — COMPARISON
══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade brs-pane" id="t2">
  <div class="section-desc">
    <i class="fa-solid fa-circle-info text-primary"></i>
    <span>Compare two snapshots side-by-side to see exactly which products changed stock between two points in time.
      Color coding: <span class="chip chip-changed ms-1">Changed</span>
      <span class="chip chip-added ms-1">Added</span>
      <span class="chip chip-removed ms-1">Removed</span>
    </span>
  </div>
  <div class="p-4 border-bottom">
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="fw-semibold small mb-1 d-block">
          <span class="badge me-1" style="background:#94a3b8;border-radius:4px">A</span> Baseline Snapshot
        </label>
        <select class="form-select" id="cmpA"><option value="">— Select snapshot A —</option></select>
      </div>
      <div class="col-md-4">
        <label class="fw-semibold small mb-1 d-block">
          <span class="badge me-1" style="background:var(--brs-indigo);border-radius:4px">B</span> Compare-To Snapshot
        </label>
        <select class="form-select" id="cmpB"><option value="">— Select snapshot B —</option></select>
      </div>
      <div class="col-md-4 d-flex gap-2 align-items-end">
        <button class="btn btn-primary fw-semibold flex-grow-1" id="btnCompare" style="border-radius:8px">
          <i class="fa-solid fa-code-compare me-1"></i>Compare
        </button>
        <a class="btn btn-outline-success fw-semibold d-none" id="btnExportCsv" style="border-radius:8px" title="Export as CSV">
          <i class="fa-solid fa-file-csv"></i>
        </a>
      </div>
    </div>
  </div>
  <!-- Snapshot name cards (shown after compare) -->
  <div id="cmpSnapCards" class="p-4 pb-0 d-none">
    <div class="row g-3 mb-0">
      <div class="col-md-6">
        <div class="cmp-snap-card a-card">
          <div class="cmp-snap-label"><span class="badge" style="background:#94a3b8;border-radius:4px">A</span> Baseline</div>
          <div class="cmp-snap-name" id="cmpAName">—</div>
          <div class="cmp-snap-date" id="cmpADate">—</div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="cmp-snap-card b-card">
          <div class="cmp-snap-label"><span class="badge" style="background:var(--brs-indigo);border-radius:4px">B</span> Compare To</div>
          <div class="cmp-snap-name" id="cmpBName">—</div>
          <div class="cmp-snap-date" id="cmpBDate">—</div>
        </div>
      </div>
    </div>
  </div>
  <!-- Summary pills -->
  <div id="cmpSummary" class="cmp-summary d-none">
    <span class="small fw-bold text-muted me-1">Differences:</span>
    <span class="cmp-pill changed d-none" id="cmpPillC"><i class="fa-solid fa-arrows-left-right"></i> <span id="cmpNumC">0</span> Changed</span>
    <span class="cmp-pill added   d-none" id="cmpPillA"><i class="fa-solid fa-plus"></i>             <span id="cmpNumA">0</span> Added</span>
    <span class="cmp-pill removed d-none" id="cmpPillR"><i class="fa-solid fa-minus"></i>            <span id="cmpNumR">0</span> Removed</span>
    <span class="cmp-pill same    d-none" id="cmpPillS"><i class="fa-solid fa-equals"></i>           <span id="cmpNumS">0</span> Unchanged</span>
  </div>
  <!-- Diff table -->
  <div id="cmpResult">
    <div class="brs-empty" id="cmpEmpty">
      <i class="fa-solid fa-code-compare brs-empty-icon d-block mx-auto"></i>
      <h6>No Comparison Yet</h6>
      <p>Select two snapshots above and click <strong>Compare</strong> to see stock differences.</p>
    </div>
    <div class="table-responsive d-none" id="cmpTableWrap">
      <table class="table brs-table mb-0" id="cmpTable">
        <thead>
          <tr>
            <th class="ps-4">SKU</th>
            <th>Product Name</th>
            <th class="text-center" style="background:#f8fafc">A&nbsp;POS</th>
            <th class="text-center" style="background:#f8fafc">B&nbsp;POS</th>
            <th class="text-center" style="background:#f8fafc">POS Δ</th>
            <th class="text-center" style="background:#fdf2f8;border-left:1px solid #e2e8f0">A&nbsp;Shopee</th>
            <th class="text-center" style="background:#fdf2f8">B&nbsp;Shopee</th>
            <th class="text-center" style="background:#fdf2f8">Shopee Δ</th>
            <th class="text-center pe-4">Change</th>
          </tr>
        </thead>
        <tbody id="cmpBody"></tbody>
      </table>
      <!-- Pagination -->
      <div class="d-flex justify-content-between align-items-center p-3 border-top bg-white d-none" id="cmpPaginationWrap">
        <div class="small fw-semibold text-muted" id="cmpPageInfo"></div>
        <div class="btn-group">
          <button class="btn btn-sm btn-outline-secondary" id="cmpBtnPrev">Previous</button>
          <button class="btn btn-sm btn-outline-secondary" id="cmpBtnNext">Next</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 3 — RECOVERY CENTER
══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade brs-pane" id="t3">
  <div class="section-desc">
    <i class="fa-solid fa-triangle-exclamation text-warning"></i>
    <span>
      <strong>Restore</strong> will overwrite the current live inventory with the stock numbers from the selected snapshot.
      A <strong>Pre-Restore Backup</strong> is always automatically created first — so you can always undo.
    </span>
  </div>

  <!-- How it works -->
  <div class="p-4 border-bottom bg-white">
    <h6 class="fw-bold mb-3 small text-uppercase" style="letter-spacing:.06em;color:var(--text-secondary)">
      <i class="fa-solid fa-list-ol me-1"></i>How Restore Works
    </h6>
    <div class="d-flex flex-wrap gap-4">
      <div class="how-step mb-0"><div class="how-num">1</div><div class="how-text"><strong>Click Restore</strong> on any snapshot below</div></div>
      <div class="how-step mb-0"><div class="how-num">2</div><div class="how-text"><strong>Review the preview</strong> — see what will change</div></div>
      <div class="how-step mb-0"><div class="how-num">3</div><div class="how-text"><strong>Type RESTORE</strong> to confirm</div></div>
      <div class="how-step mb-0"><div class="how-num">4</div><div class="how-text"><strong>System auto-creates a backup</strong> of current state, then applies restore</div></div>
    </div>
  </div>

  <!-- Snapshot list for restore -->
  <div class="brs-toolbar">
    <span class="brs-toolbar-title"><i class="fa-solid fa-rotate-left me-1 text-warning"></i>Available Snapshots</span>
    <div class="input-group input-group-sm ms-3" style="max-width:240px">
      <span class="input-group-text" style="background:var(--card-bg);border-color:var(--border-color)"><i class="fa-solid fa-search text-muted"></i></span>
      <input type="text" class="form-control" id="recSearch" placeholder="Search snapshots..." style="background:var(--card-bg);color:var(--text-primary);border-color:var(--border-color)">
    </div>
    <span class="ms-auto small text-muted" id="recCount"></span>
  </div>
  <div id="recWrap">
    <div class="brs-empty" id="recEmpty" style="display:none">
      <i class="fa-solid fa-rotate-left brs-empty-icon d-block mx-auto"></i>
      <h6>No Snapshots Available</h6>
      <p>Create a snapshot in the <strong>Snapshot Manager</strong> tab first.</p>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 4 — AUDIT LOGS
══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade brs-pane" id="t4">
  <div class="section-desc">
    <i class="fa-solid fa-circle-info text-primary"></i>
    <span>
      Every critical action — create, restore, delete, emergency restore — is permanently recorded here
      with the <strong>user name</strong>, <strong>IP address</strong>, and <strong>timestamp</strong>.
    </span>
  </div>
  <div class="brs-toolbar">
    <label class="fw-semibold small mb-0 text-muted">Filter by Action:</label>
    <select class="form-select form-select-sm" id="auditFilter" style="width:200px;background:var(--card-bg);color:var(--text-primary);border-color:var(--border-color)">
      <option value="">All Actions</option>
      <option value="CREATE_SNAPSHOT">Create Snapshot</option>
      <option value="RESTORE">Restore</option>
      <option value="EMERGENCY_RESTORE">Emergency Restore</option>
      <option value="AUTO_SNAPSHOT">Auto Snapshot</option>
      <option value="SHOPEE_RESYNC">Shopee Resync</option>
    </select>
    <label class="fw-semibold small mb-0 text-muted ms-2">Date:</label>
    <input type="date" class="form-control form-control-sm" id="auditDateFilter" style="width:140px;background:var(--card-bg);color:var(--text-primary);border-color:var(--border-color)">
    <button class="btn btn-sm btn-outline-secondary" id="btnAuditRefresh" title="Refresh">
      <i class="fa-solid fa-sync"></i>
    </button>
    <span class="ms-auto small text-muted" id="auditMeta"></span>
  </div>

  <div class="table-responsive">
    <table class="table brs-table mb-0" id="auditTable">
      <thead>
        <tr>
          <th class="ps-4" style="width:170px">Date &amp; Time</th>
          <th style="width:170px">Action</th>
          <th>User</th>
          <th>Snapshot Used</th>
          <th class="text-center">Products</th>
          <th class="pe-4">Notes</th>
        </tr>
      </thead>
      <tbody id="auditBody">
        <tr><td colspan="6" class="text-center py-4 text-muted">
          <i class="fa-solid fa-spinner fa-spin me-1"></i>Loading audit log…
        </td></tr>
      </tbody>
    </table>
  </div>
  <div class="brs-toolbar" style="justify-content:space-between">
    <span class="small text-muted" id="auditFooter"></span>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-secondary" id="btnAuditPrev" disabled>
        <i class="fa-solid fa-chevron-left me-1"></i>Prev
      </button>
      <button class="btn btn-sm btn-outline-secondary" id="btnAuditNext" disabled>
        Next<i class="fa-solid fa-chevron-right ms-1"></i>
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 5 — SETTINGS
══════════════════════════════════════════════════════════════════════ -->
<style>
/* Settings Redesign Styles */
.text-indigo { color: var(--brs-indigo) !important; }
.text-amber { color: var(--brs-amber) !important; }
.bg-light-amber { background: rgba(245,158,11,.1); }
.bg-light-shopee { background: rgba(238,77,45,.1); }
.text-slate-300 { color: #cbd5e1 !important; }
.text-slate-400 { color: #94a3b8 !important; }

.btn-setting-pill {
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  font-weight: 600;
  padding: 0.35rem 0.85rem;
  font-size: 0.85rem;
  border-radius: 8px;
  transition: all 0.2s;
}
.btn-setting-pill:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
  color: #334155;
}
.btn-setting-pill.active {
  background: rgba(99,102,241,.1);
  border-color: var(--brs-indigo);
  color: var(--brs-indigo);
  box-shadow: inset 0 0 0 1px var(--brs-indigo);
}

.setting-toggle-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1rem;
  background: #f8fafc;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  transition: all 0.2s;
}
.setting-toggle-row:hover {
  background: #fff;
  box-shadow: 0 4px 12px rgba(0,0,0,.03);
  border-color: #cbd5e1;
}
.setting-toggle-row .icon-wrap {
  width: 32px; height: 32px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem;
}
</style>

<div class="tab-pane fade brs-pane bg-white p-4" id="t5">
  <!-- Header -->
  <div class="mb-4">
    <h5 class="fw-bold text-dark mb-1">System Configurations</h5>
    <p class="text-muted small">Manage automation, restore behaviors, and snapshot retention policies.</p>
  </div>

  <div class="row g-4">
    <!-- Left Column: Settings -->
    <div class="col-lg-7">
      
      <!-- Auto Snapshots Card -->
      <div class="card border-0 mb-4" style="background:#fff;border-radius:12px;box-shadow:0 4px 15px -5px rgba(0,0,0,.05);overflow:hidden;border:1px solid #e2e8f0!important">
        <div class="card-header bg-transparent border-0 pt-3 pb-0 px-3 d-flex align-items-center gap-3">
          <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg, var(--brs-indigo), #818cf8);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 10px rgba(99,102,241,.3)">
            <i class="fa-solid fa-robot fs-6"></i>
          </div>
          <div>
            <h6 class="fw-bold mb-0">Auto-Snapshot Engine</h6>
            <div class="small text-muted" style="font-size:0.75rem">Automated background inventory protection.</div>
          </div>
          <div class="ms-auto form-check form-switch fs-5 mb-0">
             <input class="form-check-input" type="checkbox" id="setEnabled" role="switch" style="cursor:pointer">
          </div>
        </div>
        
        <div class="card-body p-3 pt-2">
          <hr class="mt-2 mb-3" style="border-color:#f1f5f9">
          
          <div class="setting-group mb-3">
            <label class="fw-bold text-uppercase mb-2 d-flex align-items-center gap-2" style="color:#64748b;letter-spacing:.05em;font-size:0.75rem">
              <i class="fa-solid fa-stopwatch text-indigo"></i> Capture Frequency
            </label>
            <div class="d-flex flex-wrap gap-2" id="freqGroup">
              <button class="btn btn-setting-pill tgl-btn" data-v="every_6h">Every 6 Hours</button>
              <button class="btn btn-setting-pill tgl-btn" data-v="every_12h">Every 12 Hours</button>
              <button class="btn btn-setting-pill tgl-btn" data-v="daily">Daily</button>
              <button class="btn btn-setting-pill tgl-btn" data-v="weekly">Weekly</button>
            </div>
            <input type="hidden" id="setFreq" value="daily">
          </div>

          <div class="setting-group mb-3">
            <label class="fw-bold text-uppercase mb-2 d-flex align-items-center gap-2" style="color:#64748b;letter-spacing:.05em;font-size:0.75rem">
              <i class="fa-solid fa-box-archive text-indigo"></i> Retention Policy
            </label>
            <div class="d-flex flex-wrap gap-2" id="retGroup">
              <button class="btn btn-setting-pill tgl-btn" data-v="10">Keep 10</button>
              <button class="btn btn-setting-pill tgl-btn" data-v="30">Keep 30</button>
              <button class="btn btn-setting-pill tgl-btn" data-v="90">Keep 90</button>
            </div>
            <input type="hidden" id="setRet" value="30">
          </div>

          <div class="setting-group">
            <label class="fw-bold text-uppercase mb-2 d-flex align-items-center gap-2" style="color:#64748b;letter-spacing:.05em;font-size:0.75rem">
              <i class="fa-solid fa-scale-balanced text-indigo"></i> Activity Threshold
            </label>
            <div class="d-flex flex-wrap gap-2" id="threshGroup">
              <button class="btn btn-setting-pill tgl-btn" data-v="0">Always Run (0+)</button>
              <button class="btn btn-setting-pill tgl-btn" data-v="1">1+ Changes</button>
              <button class="btn btn-setting-pill tgl-btn" data-v="5">5+ Changes</button>
              <button class="btn btn-setting-pill tgl-btn" data-v="10">10+ Changes</button>
            </div>
            <input type="hidden" id="setThresh" value="0">
            <div class="mt-2 text-muted" style="font-size:0.75rem"><i class="fa-solid fa-circle-info me-1"></i>Skip auto-snapshots if inventory hasn't changed enough.</div>
          </div>
        </div>
      </div>

      <!-- Restore Configuration Card -->
      <div class="card border-0 mb-4" style="background:#fff;border-radius:12px;box-shadow:0 4px 15px -5px rgba(0,0,0,.05);overflow:hidden;border:1px solid #e2e8f0!important">
        <div class="card-header bg-transparent border-0 pt-3 pb-0 px-3 d-flex align-items-center gap-3">
          <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg, var(--brs-amber), #fbbf24);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 10px rgba(245,158,11,.3)">
            <i class="fa-solid fa-rotate-left fs-6"></i>
          </div>
          <div>
            <h6 class="fw-bold mb-0">Restore Rules</h6>
            <div class="small text-muted" style="font-size:0.75rem">Manage how the restoration process behaves.</div>
          </div>
        </div>
        <div class="card-body p-3 pt-2">
          <hr class="mt-2 mb-3" style="border-color:#f1f5f9">
          
          <div class="setting-toggle-row mb-2">
            <div class="d-flex align-items-center">
              <div class="icon-wrap bg-light-amber"><i class="fa-solid fa-layer-group text-amber"></i></div>
              <div class="ms-3">
                <div class="fw-bold text-dark fs-6">Selective Restoring</div>
                <div class="text-muted" style="font-size:0.75rem">Allow choosing POS vs Shopee stock to restore.</div>
              </div>
            </div>
            <div class="form-check form-switch fs-5 mb-0">
              <input class="form-check-input" type="checkbox" id="setPartial" role="switch" style="cursor:pointer">
            </div>
          </div>

          <div class="setting-toggle-row">
            <div class="d-flex align-items-center">
              <div class="icon-wrap bg-light-shopee"><i class="fa-brands fa-shopee text-shopee" style="color:var(--shopee-primary)"></i></div>
              <div class="ms-3">
                <div class="fw-bold text-dark fs-6">Shopee Auto-Sync Integration</div>
                <div class="text-muted" style="font-size:0.75rem">Allow automatic pushing of restored stock to Shopee.</div>
              </div>
            </div>
            <div class="form-check form-switch fs-5 mb-0">
              <input class="form-check-input" type="checkbox" id="setShopeeSync" role="switch" style="cursor:pointer">
            </div>
          </div>
        </div>
      </div>
      
      <!-- Save Button Row -->
      <div class="d-flex align-items-center gap-3 mb-4">
        <button class="btn btn-primary fw-bold px-4 py-2" id="btnSaveSet" style="border-radius:10px;background:var(--brs-indigo);border:none;box-shadow:0 4px 12px rgba(99,102,241,.25)">
          <i class="fa-solid fa-check me-2"></i>Save All Changes
        </button>
        <span class="badge rounded-pill px-3 py-2 d-none set-saved" style="font-size:.85rem;font-weight:600;background:rgba(16,185,129,.1);color:var(--brs-emerald)">
          <i class="fa-solid fa-circle-check me-1"></i>Settings applied successfully!
        </span>
      </div>

    </div>

    <!-- Right Column: Cron Setup -->
    <div class="col-lg-5">
      <div class="card border-0" style="background:linear-gradient(to bottom, #1e293b, #0f172a);border-radius:16px;color:#fff;box-shadow:0 10px 30px -10px rgba(0,0,0,.15)">
        <div class="card-body p-4">
          <div class="d-flex align-items-center gap-2 mb-3">
            <i class="fa-solid fa-terminal fs-4 text-info"></i>
            <h6 class="fw-bold mb-0">Server Configuration</h6>
          </div>
          <p class="text-slate-300 small" style="line-height:1.5">
            To ensure the auto-snapshot engine runs perfectly on schedule, you must add the following background task to your server's Cron Jobs.
          </p>
          
          <div class="mt-3 mb-3">
            <label class="fw-bold text-uppercase text-slate-400 mb-2" style="letter-spacing:.05em;font-size:0.7rem">Hostinger Cron Command</label>
            <div class="position-relative">
              <div class="p-2 pe-4" style="background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-family:monospace;color:#38bdf8;word-break:break-all;line-height:1.4;font-size:0.8rem">
                0 * * * * php /home/<span class="text-warning fw-bold">USER</span>/public_html/ella-pos/api/inventory/auto_snapshot.php
              </div>
            </div>
          </div>
          
          <div class="d-flex gap-2 align-items-start text-slate-400" style="font-size:0.75rem">
            <i class="fa-solid fa-circle-info mt-1"></i>
            <div>
              Replace <strong class="text-white">USER</strong> with your Hostinger FTP account name. The script executes every hour but internally checks your <em>Capture Frequency</em>.
            </div>
          </div>
        </div>
      </div>

      <!-- Offline Backup Card -->
      <div class="card border-0 mt-4" style="background:#fff;border-radius:12px;box-shadow:0 4px 15px -5px rgba(0,0,0,.05);border:1px solid #e2e8f0!important">
        <div class="card-body p-4 text-center">
          <div style="width:50px;height:50px;border-radius:12px;background:rgba(16,185,129,.1);color:var(--brs-emerald);display:flex;align-items:center;justify-content:center;margin:0 auto 15px;">
            <i class="fa-solid fa-cloud-arrow-down fs-4"></i>
          </div>
          <h6 class="fw-bold mb-2">Offline Cold Backup</h6>
          <p class="small text-muted mb-4" style="line-height:1.5">Download a complete, offline JSON copy of your current inventory, snapshot history, and settings for ultimate disaster recovery.</p>
          
          <a href="../../api/inventory/snapshots.php?action=export_cold_backup" target="_blank" class="btn fw-bold w-100 py-2" style="background:var(--brs-emerald);color:#fff;border-radius:8px;box-shadow:0 4px 10px rgba(16,185,129,.25)">
            <i class="fa-solid fa-download me-2"></i>Download Database Dump
          </a>
        </div>
      </div>
    </div>

</div><!-- /.tab-content -->
</div><!-- /.container-fluid -->

<!-- ═══════════════════════ MODAL: Snapshot Details ══════════════════════ -->
<div class="modal fade" id="mDetail" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border:none;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
      <div class="modal-header align-items-center pb-3" style="background:linear-gradient(145deg, #f8f9fb, #ffffff);border-bottom:1px solid var(--border-color,#e5e7eb)">
        <div>
           <h5 class="modal-title fw-bold" style="color:var(--text-primary)"><i class="fa-solid fa-box-open text-primary me-2"></i>Snapshot Contents</h5>
           <div class="small text-muted mt-1 fw-semibold" id="detName">—</div>
        </div>
        <button type="button" class="btn-close mb-auto mt-1" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" style="background:var(--bg-surface,#f8f9fb)">
        <div class="p-4 border-bottom bg-white">
          <div class="d-flex align-items-center justify-content-between mb-2">
             <label class="fw-bold text-muted small text-uppercase" style="letter-spacing:.05em"><i class="fa-solid fa-filter me-1"></i>Quick Find</label>
             <span class="badge px-3 py-2 rounded-pill" style="background:rgba(99,102,241,.1);color:var(--brs-indigo);font-size:.85rem;font-weight:700" id="detCount">0 products</span>
          </div>
          <div class="input-group input-group-lg" style="box-shadow:0 4px 6px -1px rgba(0,0,0,.05), 0 2px 4px -1px rgba(0,0,0,.03);border-radius:12px;overflow:hidden;border:2px solid rgba(99,102,241,.3);transition:border .2s">
            <span class="input-group-text bg-white border-0 px-4"><i class="fa-solid fa-search" style="color:var(--brs-indigo);font-size:1.2rem"></i></span>
            <input type="text" class="form-control border-0 ps-0 fw-bold" id="detSearch" placeholder="Type a Product Name, Variation, Brand, or SKU..." style="box-shadow:none;font-size:1.1rem;color:var(--text-primary)">
          </div>
        </div>
        <div class="table-responsive bg-white" style="min-height:450px">
          <table class="table brs-table mb-0" style="min-width:700px">
            <thead>
              <tr>
                <th class="ps-4">Product &amp; SKU</th>
                <th class="text-center">Total Stock</th>
                <th class="text-center">Shopee Alloc</th>
                <th class="text-center pe-4">POS Stock</th>
              </tr>
            </thead>
            <tbody id="detBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer bg-white justify-content-between py-3">
        <span class="small text-muted fw-semibold" id="detFoot"></span>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary px-3 fw-bold" style="border-radius:8px" id="detPrev">Prev</button>
          <button class="btn btn-sm btn-outline-secondary px-3 fw-bold" style="border-radius:8px" id="detNext">Next</button>
        </div>
      </div>
    </div>
  </div>
</div>


<!-- ═══════════════════════ MODAL: Create Snapshot ══════════════════════ -->
<div class="modal fade" id="mCreate" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-camera text-primary me-2"></i>Create Stock Snapshot</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="ib ib-primary mb-3 small">
          <i class="fa-solid fa-circle-info text-primary me-1"></i>
          This will capture the <strong>exact stock quantities</strong> (Total, POS, Shopee) for all active products right now.
        </div>
        <div class="mb-3">
          <label class="fw-semibold mb-1">Snapshot Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="cName" placeholder="e.g. Pre-Import Backup — June 2026" autocomplete="off">
          <div class="invalid-feedback">Snapshot name is required.</div>
        </div>
        <div>
          <label class="fw-semibold mb-1">Notes <small class="text-muted fw-normal">(optional)</small></label>
          <textarea class="form-control" id="cNotes" rows="2" placeholder="Why are you creating this snapshot?"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary fw-bold" id="btnDoCreate">
          <span class="spin d-none me-1" id="cSpin"></span>
          <i class="fa-solid fa-camera me-1" id="cIcon"></i>Create Snapshot
        </button>
      </div>
    </div>
  </div>
</div>



<!-- ════════════════════ MODAL: Restore — 3 Stages ══════════════════════ -->
<div class="modal fade" id="mRestore" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-rotate-left text-warning me-2"></i>Restore Snapshot</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" id="rsX"></button>
      </div>
      <div class="modal-body">
        <!-- Step dots -->
        <div class="steps" id="rsSteps">
          <div class="step-dot s-on"  id="rsD1">1</div>
          <div class="step-line"      id="rsL1"></div>
          <div class="step-dot"       id="rsD2">2</div>
          <div class="step-line"      id="rsL2"></div>
          <div class="step-dot"       id="rsD3">3</div>
          <div class="step-line"      id="rsL3"></div>
          <div class="step-dot"       id="rsD4">4</div>
          <div class="step-line"      id="rsL4"></div>
          <div class="step-dot"       id="rsD5">5</div>
        </div>
        <!-- Labels under dots -->
        <div class="d-flex justify-content-center gap-0 mb-4" style="margin-top:-16px">
          <div style="width:34px;text-align:center;font-size:.62rem;font-weight:700;color:var(--text-secondary);flex-shrink:0">Preview</div>
          <div style="flex:1;max-width:50px"></div>
          <div style="width:34px;text-align:center;font-size:.62rem;font-weight:700;color:var(--text-secondary);flex-shrink:0">Options</div>
          <div style="flex:1;max-width:50px"></div>
          <div style="width:34px;text-align:center;font-size:.62rem;font-weight:700;color:var(--text-secondary);flex-shrink:0">Verify</div>
          <div style="flex:1;max-width:50px"></div>
          <div style="width:34px;text-align:center;font-size:.62rem;font-weight:700;color:var(--text-secondary);flex-shrink:0">Confirm</div>
          <div style="flex:1;max-width:50px"></div>
          <div style="width:34px;text-align:center;font-size:.62rem;font-weight:700;color:var(--text-secondary);flex-shrink:0">Done</div>
        </div>

        <!-- Stage 1: Preview -->
        <div class="m-stage on" id="rsS1">
          <div class="ib ib-warning mb-3 d-flex gap-2">
            <i class="fa-solid fa-triangle-exclamation text-warning flex-shrink-0 mt-1"></i>
            <div>
              <strong>Stock Overwrite Warning</strong><br>
              <span class="small">This will overwrite the current live stock quantities (POS and Shopee) with the numbers from the selected snapshot.</span>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-sm-6">
              <div class="ib ib-success h-100">
                <div class="small fw-bold mb-2" style="color:var(--brs-emerald)">
                  <i class="fa-solid fa-camera me-1"></i>SNAPSHOT TO RESTORE
                </div>
                <div class="fw-bold fs-6" id="rsName" style="color:var(--text-primary)">—</div>
                <div class="small text-muted mt-1" id="rsDate">—</div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="ib ib-primary h-100">
                <div class="small fw-bold mb-2" style="color:var(--brs-indigo)">
                  <i class="fa-solid fa-boxes-stacked me-1"></i>WHAT WILL BE RESTORED
                </div>
                <div class="fw-bold" style="font-size:1.4rem;color:var(--text-primary)" id="rsCount">—</div>
                <div class="small text-muted">product stock quantities</div>
              </div>
            </div>
          </div>
          <div class="ib mt-3 d-flex gap-2">
            <i class="fa-solid fa-shield-halved text-success flex-shrink-0 mt-1"></i>
            <div class="small">
              <strong>Safety:</strong> Before restoring, the system will automatically create a
              <strong>Pre-Restore Backup</strong> of the current stock — so you can always reverse this restore.
            </div>
          </div>
        </div>

        <!-- Stage 2: Options -->
        <div class="m-stage" id="rsS2">
          <div class="text-center mb-4">
            <i class="fa-solid fa-sliders fa-2x text-primary mb-2 d-block"></i>
            <h6 class="fw-bold mb-1">Restore Options</h6>
            <p class="text-muted small mb-0">Select what you want to restore and sync.</p>
          </div>
          
          <div class="mb-3" id="optPartialDiv">
            <label class="fw-bold small text-uppercase mb-2 d-block" style="color:#64748b;letter-spacing:.05em">Select Stock Types to Restore</label>
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" role="switch" id="optRestorePos" checked>
              <label class="form-check-label fw-semibold" for="optRestorePos">Physical POS Stock</label>
              <div class="small text-muted">Restore stock for walk-in store (store_id = 1).</div>
            </div>
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" role="switch" id="optRestoreShopee" checked>
              <label class="form-check-label fw-semibold" for="optRestoreShopee">Shopee Allocated Stock</label>
              <div class="small text-muted">Restore allocated online stock (store_id = 2).</div>
            </div>
          </div>
          
          <div class="mb-3" id="optShopeeSyncDiv">
            <label class="fw-bold small text-uppercase mb-2 d-block" style="color:#64748b;letter-spacing:.05em">Shopee API Push</label>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="optShopeeSync">
              <label class="form-check-label fw-semibold text-shopee" for="optShopeeSync" style="color:var(--shopee-primary)"><i class="fa-brands fa-shopee me-1"></i>Auto-Sync to Shopee</label>
              <div class="small text-muted">Automatically push the restored allocated stock to Shopee instantly.</div>
            </div>
          </div>
        </div>

        <!-- Stage 3: Admin Password -->
        <div class="m-stage" id="rsS3">
          <div class="text-center mb-4">
            <i class="fa-solid fa-lock fa-2x text-warning mb-2 d-block"></i>
            <h6 class="fw-bold mb-1">Admin Password Required</h6>
            <p class="text-muted small mb-0">For security, verify your identity before proceeding.</p>
          </div>
          <label class="fw-semibold mb-1">Your Admin Password</label>
          <input type="password" class="form-control" id="rsPwd" placeholder="Enter your password" autocomplete="current-password">
          <div class="text-danger small mt-2 d-none" id="rsPwdErr">
            <i class="fa-solid fa-circle-xmark me-1"></i>Incorrect password. Please try again.
          </div>
        </div>

        <!-- Stage 4: Typed confirmation -->
        <div class="m-stage" id="rsS4">
          <div class="text-center mb-4">
            <i class="fa-solid fa-keyboard fa-2x text-danger mb-2 d-block"></i>
            <h6 class="fw-bold mb-1">Confirm the Restore</h6>
            <p class="text-muted small">You must type the exact word below to proceed.</p>
          </div>
          <div class="ib mb-3 small text-center">
            Restoring: <strong id="rsName2">—</strong>
          </div>
          <label class="fw-semibold mb-1">Type <code>RESTORE</code> to confirm:</label>
          <input type="text" class="c-box" id="rsInput" placeholder="RESTORE" autocomplete="off" spellcheck="false">
          <p class="c-hint">The <strong>Confirm Restore</strong> button activates only when you type <code>RESTORE</code> (case-sensitive).</p>
        </div>

        <!-- Stage 5: Result -->
        <div class="m-stage" id="rsS5">
          <div id="rsRunning" class="text-center py-3">
            <div class="spinner-border text-warning mb-3" style="width:3rem;height:3rem" role="status"></div>
            <h6 class="fw-bold mb-1">Restoring Inventory…</h6>
            <p class="small text-muted">Creating pre-restore backup, then applying stock restore. Please wait.</p>
          </div>
          <div id="rsResult" class="d-none">
            <div class="log-term mb-3" id="rsLog"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" id="rsCancelBtn">Cancel</button>
        <button class="btn btn-warning fw-bold"          id="rsBtn1">Proceed to Options →</button>
        <button class="btn btn-secondary fw-bold d-none" id="rsBtn2B">← Back</button>
        <button class="btn btn-warning fw-bold d-none"   id="rsBtn2">Proceed to Verification →</button>
        <button class="btn btn-secondary fw-bold d-none" id="rsBtn3B">← Back</button>
        <button class="btn btn-warning fw-bold d-none"   id="rsBtn3">
          <span class="spin d-none me-1" id="rsPwdSpin"></span>Verify Password →
        </button>
        <button class="btn btn-danger fw-bold d-none"    id="rsBtn4C" disabled>
          <span class="spin d-none me-1" id="rsSpin"></span>
          <i class="fa-solid fa-rotate-left me-1" id="rsIcon"></i>Confirm Restore
        </button>
      </div>
    </div>
  </div>
</div>

<script>
'use strict';
const API    = '<?= BASE_URL ?>api/inventory/snapshots.php';
let snapList = [];
let delId    = null;
let rsId     = null;
let auditPg  = 0;
const PER_PG = 25;

/* ── HELPERS ─────────────────────────────────────────────────────── */
const esc  = s => { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; };
const fmtN = n => n==null ? '—' : Number(n).toLocaleString('en-PH');
const fmtD = s => {
    if(!s) return '—';
    return new Date(s).toLocaleString('en-PH',{year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit',hour12:true});
};
const timeAgo = s => {
    if(!s) return '';
    const sec=Math.floor((Date.now()-new Date(s))/1000);
    if(sec<60)  return 'just now';
    if(sec<3600)return Math.floor(sec/60)+'m ago';
    if(sec<86400)return Math.floor(sec/3600)+'h ago';
    return Math.floor(sec/86400)+'d ago';
};

function logLine(el,msg,cls=''){
    const r=document.createElement('div'); r.className='ll '+cls;
    r.textContent=`[${new Date().toLocaleTimeString()}] ${msg}`;
    el.appendChild(r); el.scrollTop=el.scrollHeight;
}

async function apiFetch(method,action,body=null){
    try{
        const opts={method,headers:{'Content-Type':'application/json'},cache:'no-store'};
        let finalUrl = `${API}?action=${action}`;
        if(method==='GET'){
            finalUrl += `&_t=${Date.now()}`;
            if(body) finalUrl += `&${new URLSearchParams(body).toString()}`;
        } else if(body) {
            opts.body = JSON.stringify(body);
        }
        const r=await fetch(finalUrl, opts);
        if(!r.ok) throw new Error(`HTTP ${r.status}`);
        return await r.json();
    }catch(e){ return {success:false,error:e.message}; }
}
const GET  = (action,params={}) => apiFetch('GET',action,Object.keys(params).length?params:null);
const POST = (action,body)       => apiFetch('POST',action,body);

function showToast(msg,type='success'){
    if(window.EllaToast) EllaToast.show(msg,type);
    else{ const t=type==='success'?'✓':'✗'; alert(`${t} ${msg}`); }
}

/* ── TYPE HELPERS ─────────────────────────────────────────────────── */
const typeIcon  = t=>({manual:'fa-camera',auto:'fa-robot',pre_restore:'fa-shield-halved'}[t]||'fa-camera');
const typeLabel = t=>({manual:'Manual',auto:'Auto',pre_restore:'Pre-Restore'}[t]||t);

/* ════════════════════════════════════════════════════════════════════
   STATS
════════════════════════════════════════════════════════════════════ */
async function loadStats(){
    const d=await GET('stats');
    if(!d.success){ console.warn('Stats error:',d.error); return; }
    document.getElementById('statTotal').textContent    = fmtN(d.total_snapshots);
    document.getElementById('statTotalSub').textContent = d.total_snapshots===1?'1 snapshot saved':`${d.total_snapshots} snapshots saved`;
    document.getElementById('statLastBkp').textContent  = d.last_backup ? timeAgo(d.last_backup) : 'None yet';
    document.getElementById('statLastBkpSub').textContent = d.last_backup ? fmtD(d.last_backup) : 'No snapshots yet';
    document.getElementById('statProducts').innerHTML = `${fmtN(d.products_protected)} <span style="font-size:1.1rem;color:var(--text-secondary);font-weight:600">/ ${fmtN(d.stock_protected)} <small>qty</small></span>`;
    document.getElementById('statProductsSub').textContent = 'active SKUs / total physical items';
    document.getElementById('statLastRec').textContent   = d.last_recovery ? timeAgo(d.last_recovery) : 'Never';
    document.getElementById('statLastRecSub').textContent= d.last_recovery ? fmtD(d.last_recovery) : 'No restores performed';
}

/* ════════════════════════════════════════════════════════════════════
   SNAPSHOT LIST (Tab 1 + Tab 3)
════════════════════════════════════════════════════════════════════ */
function renderSnaps(list){
    const q     = document.getElementById('snapSearch').value.trim().toLowerCase();
    const qWords = q ? q.split(/\s+/) : [];
    const type  = document.getElementById('snapTypeFilter').value;
    const filtered = list.filter(s=>{
        const matchQ = qWords.length === 0 || qWords.every(w => 
            s.snapshot_name.toLowerCase().includes(w) || (s.notes||'').toLowerCase().includes(w)
        );
        const matchType = !type || s.trigger_type===type;
        return matchQ&&matchType;
    });
    document.getElementById('snapCount').textContent =
        filtered.length===list.length ? `${list.length} snapshot${list.length!==1?'s':''}` : `${filtered.length} of ${list.length} shown`;

    const wrap=document.getElementById('snapWrap');
    if(!filtered.length){
        wrap.innerHTML=`<div class="brs-empty">
          <i class="fa-solid fa-layer-group brs-empty-icon d-block mx-auto"></i>
          <h6>${q||type?'No Matching Snapshots':'No Snapshots Yet'}</h6>
          <p>${q||type?'Try changing your search or filter.':'Click <strong>Create Snapshot</strong> above to get started.'}</p>
        </div>`; return;
    }
    wrap.innerHTML=filtered.map(s=>`
    <div class="snap-row">
      <div class="snap-avatar ${s.trigger_type}"><i class="fa-solid ${typeIcon(s.trigger_type)}"></i></div>
      <div class="flex-grow-1 min-w-0">
        <div class="snap-name" title="${esc(s.snapshot_name)}">${esc(s.snapshot_name)}</div>
        <div class="snap-meta">
          <span class="snap-meta-item"><i class="fa-solid fa-calendar-alt fa-xs"></i>${fmtD(s.created_at)}</span>
          <span class="snap-meta-item"><i class="fa-solid fa-user fa-xs"></i>${esc(s.created_by_name||'System')}</span>
          ${s.notes?`<span class="snap-meta-item" style="color:var(--text-secondary);background:rgba(0,0,0,.03);padding:2px 8px;border-radius:4px" title="${esc(s.notes)}"><i class="fa-solid fa-note-sticky fa-xs"></i>${esc(s.notes)}</span>`:''}
        </div>
      </div>
      <div class="d-flex align-items-center gap-3 flex-shrink-0">
        <span class="small fw-bold text-muted" title="Total Products / Total Items"><i class="fa-solid fa-boxes-stacked fa-xs me-1"></i>${fmtN(s.total_products)} <span class="fw-normal mx-1 text-light-muted">|</span> ${fmtN(s.total_stock_qty)}</span>
        <span class="snap-type ${s.trigger_type}"><i class="fa-solid ${typeIcon(s.trigger_type)} fa-xs"></i>${typeLabel(s.trigger_type)}</span>
        <div class="d-flex gap-2">
          <button class="btn btn-sm fw-bold px-3" onclick="openDetail(${s.id})" title="View contents" style="background:rgba(99,102,241,.1);color:var(--brs-indigo);border-radius:20px;transition:all .2s">
            <i class="fa-solid fa-box-open me-1"></i>Details
          </button>
        </div>
      </div>
    </div>`).join('');

    // Recovery tab
    const rq = (document.getElementById('recSearch')?.value || '').trim().toLowerCase();
    const rqWords = rq ? rq.split(/\s+/) : [];
    const eligible = list.filter(s => rqWords.length === 0 || rqWords.every(w => 
        s.snapshot_name.toLowerCase().includes(w) || (s.notes||'').toLowerCase().includes(w)
    ));
    const recWrap=document.getElementById('recWrap');
    document.getElementById('recCount').textContent=`${eligible.length} snapshot${eligible.length!==1?'s':''}`;
    if(!eligible.length){ document.getElementById('recEmpty').style.display='block'; recWrap.innerHTML=''; return; }
    document.getElementById('recEmpty').style.display='none';
    recWrap.innerHTML=eligible.map(s=>`
    <div class="snap-row">
      <div class="snap-avatar ${s.trigger_type}"><i class="fa-solid ${typeIcon(s.trigger_type)}"></i></div>
      <div class="flex-grow-1 min-w-0">
        <div class="snap-name" title="${esc(s.snapshot_name)}">
          ${s.trigger_type==='pre_restore'?'<i class="fa-solid fa-shield-halved text-warning me-1" title="Undo Backup"></i>':''}
          ${esc(s.snapshot_name)}
        </div>
        <div class="snap-meta">
          <span class="snap-meta-item"><i class="fa-solid fa-calendar-alt fa-xs"></i>${fmtD(s.created_at)} (${timeAgo(s.created_at)})</span>
          ${s.notes?`<span class="snap-meta-item" style="color:var(--text-secondary);background:rgba(0,0,0,.03);padding:2px 8px;border-radius:4px" title="${esc(s.notes)}"><i class="fa-solid fa-note-sticky fa-xs"></i>${esc(s.notes)}</span>`:''}
        </div>
      </div>
      <div class="d-flex align-items-center gap-3 flex-shrink-0">
        <span class="small fw-bold text-muted" title="Total Products / Total Items"><i class="fa-solid fa-boxes-stacked fa-xs me-1"></i>${fmtN(s.total_products)} <span class="fw-normal mx-1 text-light-muted">|</span> ${fmtN(s.total_stock_qty)}</span>
        <span class="snap-type ${s.trigger_type} me-2">${typeLabel(s.trigger_type)}</span>
        <button class="btn btn-warning fw-bold flex-shrink-0" onclick="openRestore(${s.id})" style="border-radius:8px;min-width:120px">
          <i class="fa-solid fa-rotate-left me-1"></i>Restore
        </button>
      </div>
    </div>`).join('');
}

async function loadSnaps(){
    const d=await GET('list');
    if(!d.success){ showToast('Failed to load snapshots: '+(d.error||'unknown'),'danger'); return; }
    snapList=d.snapshots||[];
    renderSnaps(snapList);
    populateCmpSelects(snapList);
}

let snapSearchTimer = null;
document.getElementById('snapSearch').addEventListener('input', () => {
    clearTimeout(snapSearchTimer);
    snapSearchTimer = setTimeout(() => renderSnaps(snapList), 300);
});

let recSearchTimer = null;
document.getElementById('recSearch').addEventListener('input', () => {
    clearTimeout(recSearchTimer);
    recSearchTimer = setTimeout(() => renderSnaps(snapList), 300);
});
document.getElementById('snapTypeFilter').addEventListener('change',()=>renderSnaps(snapList));
document.getElementById('btnRefreshSnaps').addEventListener('click',()=>{loadStats();loadSnaps();});

/* ── Compare dropdowns ────────────────────────────────────────────── */
function populateCmpSelects(list){
    const optHtml=cur=>'<option value="">— Select —</option>'+
        list.map(s=>`<option value="${s.id}" ${s.id==cur?'selected':''}>${esc(s.snapshot_name)} — ${fmtD(s.created_at)}</option>`).join('');
    const a=document.getElementById('cmpA'); const b=document.getElementById('cmpB');
    a.innerHTML=optHtml(a.value); b.innerHTML=optHtml(b.value);
}

/* ════════════════════════════════════════════════════════════════════
   CREATE SNAPSHOT
════════════════════════════════════════════════════════════════════ */
document.getElementById('btnOpenCreate').addEventListener('click',()=>{
    document.getElementById('cName').value=''; document.getElementById('cNotes').value='';
    document.getElementById('cName').classList.remove('is-invalid');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mCreate')).show();
    setTimeout(()=>document.getElementById('cName').focus(),400);
});
document.getElementById('btnDoCreate').addEventListener('click',async()=>{
    const name=document.getElementById('cName').value.trim();
    if(!name){ document.getElementById('cName').classList.add('is-invalid'); return; }
    const btn=document.getElementById('btnDoCreate'),spin=document.getElementById('cSpin'),icon=document.getElementById('cIcon');
    btn.disabled=true; spin.classList.remove('d-none'); icon.classList.add('d-none');
    const d=await POST('create',{name,notes:document.getElementById('cNotes').value.trim()});
    btn.disabled=false; spin.classList.add('d-none'); icon.classList.remove('d-none');
    if(d.success){
        bootstrap.Modal.getInstance(document.getElementById('mCreate')).hide();
        showToast(d.message,'success'); loadStats(); loadSnaps(); loadAuditLogs();
    } else { showToast('Error: '+(d.error||'unknown'),'danger'); }
});



/* ════════════════════════════════════════════════════════════════════
   RESTORE — 5 stages
════════════════════════════════════════════════════════════════════ */
function rsSetStage(n){
    let maxStage = window.AppConfig && window.AppConfig.allow_partial_restores === false && window.AppConfig.shopee_auto_sync === false ? 4 : 5;
    let actualN = n;
    
    // Skip options stage if both settings are disabled
    if (maxStage === 4) {
        if (n === 2) actualN = 3;
        else if (n === 3) actualN = 4;
        else if (n === 4) actualN = 5;
    }

    [1,2,3,4,5].forEach(i=>{
        document.getElementById(`rsS${i}`).classList.toggle('on',i===actualN);
        const dot=document.getElementById(`rsD${i}`);
        dot.classList.remove('s-on','s-done');
        if(i<actualN) dot.classList.add('s-done');
        if(i===actualN) dot.classList.add('s-on');
        if(i<5){ const ln=document.getElementById(`rsL${i}`); ln.classList.toggle('s-done',i<actualN); }
    });
    
    document.getElementById('rsBtn1').classList.toggle('d-none', n !== 1);
    document.getElementById('rsBtn2B').classList.toggle('d-none', n !== 2);
    document.getElementById('rsBtn2').classList.toggle('d-none', n !== 2);
    document.getElementById('rsBtn3B').classList.toggle('d-none', n !== 3);
    document.getElementById('rsBtn3').classList.toggle('d-none', n !== 3);
    document.getElementById('rsBtn4C').classList.toggle('d-none', n !== 4);
    
    document.getElementById('rsCancelBtn').classList.toggle('d-none', n === 5);
    document.getElementById('rsX').classList.toggle('d-none', n === 5);
}

document.getElementById('optRestoreShopee').addEventListener('change', function() {
    if (!this.checked) {
        document.getElementById('optShopeeSync').checked = false;
        document.getElementById('optShopeeSync').disabled = true;
    } else {
        document.getElementById('optShopeeSync').disabled = false;
    }
});

async function openRestore(id){
    rsId=id;
    const snap=snapList.find(s=>s.id==id);
    document.getElementById('rsName').textContent  = snap?snap.snapshot_name:'—';
    document.getElementById('rsDate').textContent  = snap?fmtD(snap.created_at):'—';
    document.getElementById('rsCount').innerHTML = `${fmtN(snap.total_products)}<br><span style="font-size:1.1rem;color:var(--text-secondary)">${fmtN(snap.total_stock_qty)} total physical items</span>`;
    document.getElementById('rsName2').textContent = snap?snap.snapshot_name:'—';
    
    // Apply Settings constraints
    const conf = window.AppConfig || {};
    if (conf.allow_partial_restores) {
        document.getElementById('optPartialDiv').classList.remove('d-none');
        document.getElementById('optRestorePos').checked = true;
        document.getElementById('optRestoreShopee').checked = true;
    } else {
        document.getElementById('optPartialDiv').classList.add('d-none');
        document.getElementById('optRestorePos').checked = true;
        document.getElementById('optRestoreShopee').checked = true;
    }

    if (conf.shopee_auto_sync) {
        document.getElementById('optShopeeSyncDiv').classList.remove('d-none');
        document.getElementById('optShopeeSync').checked = false;
        document.getElementById('optShopeeSync').disabled = false;
    } else {
        document.getElementById('optShopeeSyncDiv').classList.add('d-none');
        document.getElementById('optShopeeSync').checked = false;
    }
    
    document.getElementById('rsPwd').value=''; document.getElementById('rsPwdErr').classList.add('d-none');
    document.getElementById('rsInput').value=''; document.getElementById('rsInput').classList.remove('ok','bad');
    document.getElementById('rsBtn4C').disabled=true;
    
    document.getElementById('rsRunning').classList.remove('d-none');
    document.getElementById('rsResult').classList.add('d-none');
    
    rsSetStage(1);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mRestore')).show();
}

document.getElementById('rsBtn1').addEventListener('click',() => rsSetStage(2));
document.getElementById('rsBtn2B').addEventListener('click',() => rsSetStage(1));
document.getElementById('rsBtn2').addEventListener('click',() => { rsSetStage(3); setTimeout(()=>document.getElementById('rsPwd').focus(),300); });
document.getElementById('rsBtn3B').addEventListener('click',() => rsSetStage(2));

// New Password Verification logic (Stage 3)
document.getElementById('rsBtn3').addEventListener('click',async()=>{
    const pwd=document.getElementById('rsPwd').value;
    if(!pwd){ document.getElementById('rsPwd').focus(); return; }
    const btn=document.getElementById('rsBtn3'),spin=document.getElementById('rsPwdSpin');
    btn.disabled=true; spin.classList.remove('d-none');
    const d=await POST('verify_admin_password',{password:pwd});
    btn.disabled=false; spin.classList.add('d-none');
    if(d.success){
        document.getElementById('rsPwdErr').classList.add('d-none');
        rsSetStage(4); setTimeout(()=>document.getElementById('rsInput').focus(),300);
    } else {
        document.getElementById('rsPwdErr').classList.remove('d-none');
        document.getElementById('rsPwd').value=''; document.getElementById('rsPwd').focus();
    }
});

document.getElementById('rsInput').addEventListener('input',function(){
    const ok=this.value==='RESTORE';
    this.classList.toggle('ok',ok); this.classList.toggle('bad',this.value.length>0&&!ok);
    document.getElementById('rsBtn4C').disabled=!ok;
});

// Confirm Restore logic (Stage 4 -> 5)
document.getElementById('rsBtn4C').addEventListener('click',async()=>{
    rsSetStage(5);
    document.getElementById('rsRunning').classList.remove('d-none');
    document.getElementById('rsResult').classList.add('d-none');
    
    const payload = {
        snapshot_id: rsId,
        confirmation: 'RESTORE',
        restore_pos: document.getElementById('optRestorePos').checked,
        restore_shopee: document.getElementById('optRestoreShopee').checked,
        shopee_sync: document.getElementById('optShopeeSync').checked
    };
    
    const d=await POST('restore', payload);
    document.getElementById('rsRunning').classList.add('d-none');
    document.getElementById('rsResult').classList.remove('d-none');
    const log=document.getElementById('rsLog'); log.innerHTML='';
    if(d.success){
        logLine(log,'✓ Pre-Restore Backup created: '+(d.pre_restore_snap_name ? d.pre_restore_snap_name : '(Skipped: Undo Action)'),'ok');
        logLine(log,'✓ Stock restored — '+fmtN(d.products_restored)+' products / '+fmtN(d.qty_restored)+' items updated.','ok');
        if (payload.shopee_sync) {
            if (d.shopee_queued) {
                logLine(log,'✓ Shopee Sync — '+fmtN(d.pushed_to_shopee)+' allocations queued for background sync.','ok');
            } else {
                logLine(log,'✓ Shopee Sync — '+fmtN(d.pushed_to_shopee)+' allocations pushed to Shopee API.','ok');
            }
        }
        logLine(log,'✓ Audit log recorded.','ok');
        logLine(log,'✓ Restore complete!','ok');
        document.getElementById('rsX').classList.remove('d-none');
        loadStats(); loadSnaps(); loadAuditLogs();
    } else {
        logLine(log,'✗ Restore failed: '+(d.error||'unknown'),'err');
        document.getElementById('rsX').classList.remove('d-none');
    }
});

/* ════════════════════════════════════════════════════════════════════
   COMPARISON
════════════════════════════════════════════════════════════════════ */
let cmpDiffData = [];
let cmpPage = 1;
const CMP_PER_PAGE = 50;

function renderCmpPage() {
    const start = (cmpPage - 1) * CMP_PER_PAGE;
    const end = Math.min(start + CMP_PER_PAGE, cmpDiffData.length);
    const pageData = cmpDiffData.slice(start, end);
    
    const dN=n=>n==null?'<span class="text-muted">—</span>':n;
    const dDelta=n=>!n?`<span class="d-zero">0</span>`:n>0?`<span class="d-pos">+${n}</span>`:`<span class="d-neg">${n}</span>`;
    
    document.getElementById('cmpBody').innerHTML = pageData.map(r=>`
    <tr class="diff-${r.change_type}">
      <td class="ps-4"><code class="small">${esc(r.sku||'—')}</code></td>
      <td style="max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${esc(r.product_name)}">${esc(r.product_name||'—')}</td>
      <td class="text-center" style="background:#f8fafc">${dN(r.a_pos)}</td><td class="text-center" style="background:#f8fafc">${dN(r.b_pos)}</td>
      <td class="text-center" style="background:#f8fafc;font-weight:600">${dDelta(r.pos_diff)}</td>
      <td class="text-center" style="background:#fdf2f8;border-left:1px solid #e2e8f0">${dN(r.a_shopee)}</td><td class="text-center" style="background:#fdf2f8">${dN(r.b_shopee)}</td>
      <td class="text-center" style="background:#fdf2f8;font-weight:600">${dDelta(r.shopee_diff)}</td>
      <td class="text-center pe-4"><span class="chip chip-${r.change_type}">${r.change_type.toUpperCase()}</span></td>
    </tr>`).join('');
    
    document.getElementById('cmpPageInfo').textContent = `Showing ${start + 1} to ${end} of ${cmpDiffData.length} differences`;
    
    const btnPrev = document.getElementById('cmpBtnPrev');
    const btnNext = document.getElementById('cmpBtnNext');
    btnPrev.disabled = cmpPage === 1;
    btnNext.disabled = end >= cmpDiffData.length;
    
    if (cmpDiffData.length > CMP_PER_PAGE) {
        document.getElementById('cmpPaginationWrap').classList.remove('d-none');
        document.getElementById('cmpPaginationWrap').classList.add('d-flex');
    } else {
        document.getElementById('cmpPaginationWrap').classList.add('d-none');
        document.getElementById('cmpPaginationWrap').classList.remove('d-flex');
    }
}

document.getElementById('cmpBtnPrev')?.addEventListener('click', () => { if(cmpPage > 1) { cmpPage--; renderCmpPage(); } });
document.getElementById('cmpBtnNext')?.addEventListener('click', () => { if((cmpPage * CMP_PER_PAGE) < cmpDiffData.length) { cmpPage++; renderCmpPage(); } });

document.getElementById('btnCompare').addEventListener('click',async()=>{
    const a=document.getElementById('cmpA').value, b=document.getElementById('cmpB').value;
    if(!a||!b){ showToast('Please select both snapshots.','warning'); return; }
    if(a===b){ showToast('Please choose two different snapshots.','warning'); return; }
    const btn=document.getElementById('btnCompare');
    btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1" role="status"></span>Comparing…';
    const d=await apiFetch('GET','compare',{a,b});
    btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-code-compare me-1"></i>Compare';
    if(!d.success){ showToast('Compare error: '+(d.error||'unknown'),'danger'); return; }

    // Snapshot header cards
    document.getElementById('cmpSnapCards').classList.remove('d-none');
    document.getElementById('cmpAName').textContent=d.snapshot_a.snapshot_name;
    document.getElementById('cmpADate').textContent=fmtD(d.snapshot_a.created_at);
    document.getElementById('cmpBName').textContent=d.snapshot_b.snapshot_name;
    document.getElementById('cmpBDate').textContent=fmtD(d.snapshot_b.created_at);

    // Summary pills
    const bar=document.getElementById('cmpSummary'); bar.classList.remove('d-none');
    const items=[['C',d.summary.changed,'changed'],['A',d.summary.added,'added'],['R',d.summary.removed,'removed'],['S',d.summary.unchanged,'same']];
    items.forEach(([k,v])=>{
        document.getElementById('cmpNum'+k).textContent=fmtN(v);
        document.getElementById('cmpPill'+k).classList.toggle('d-none',v===0);
    });

    // Export link
    const exp=document.getElementById('btnExportCsv');
    exp.href=`${API}?action=export_comparison&a=${a}&b=${b}`;
    exp.classList.remove('d-none');

    if(!d.diff.length){
        document.getElementById('cmpTableWrap').classList.add('d-none');
        document.getElementById('cmpEmpty').classList.remove('d-none');
        document.getElementById('cmpEmpty').innerHTML=`
          <i class="fa-solid fa-check-circle brs-empty-icon d-block mx-auto" style="color:var(--brs-emerald)"></i>
          <h6>Snapshots Are Identical</h6>
          <p>No stock differences found — all quantities are the same.</p>`; return;
    }
    document.getElementById('cmpEmpty').classList.add('d-none');
    document.getElementById('cmpTableWrap').classList.remove('d-none');
    
    cmpDiffData = d.diff;
    cmpPage = 1;
    renderCmpPage();
});

/* ════════════════════════════════════════════════════════════════════
   AUDIT LOGS
════════════════════════════════════════════════════════════════════ */
const auditChip={
    CREATE_SNAPSHOT:'chip-create',RESTORE:'chip-restore',EMERGENCY_RESTORE:'chip-emergency',
    DELETE_SNAPSHOT:'chip-delete',AUTO_SNAPSHOT:'chip-auto',SHOPEE_RESYNC:'chip-resync'
};
const auditLabel={
    CREATE_SNAPSHOT:'Create Snapshot',RESTORE:'Restore',EMERGENCY_RESTORE:'Emergency Restore',
    DELETE_SNAPSHOT:'Delete Snapshot',AUTO_SNAPSHOT:'Auto Snapshot',SHOPEE_RESYNC:'Shopee Resync'
};

async function loadAuditLogs(){
    const filter=document.getElementById('auditFilter').value;
    const dateFilter=document.getElementById('auditDateFilter').value;
    const params={limit:PER_PG,offset:auditPg*PER_PG};
    if(filter) params.action_type=filter;
    if(dateFilter) params.date=dateFilter;
    const d=await apiFetch('GET','audit_logs',params);
    const tbody=document.getElementById('auditBody');
    if(!d.success){
        tbody.innerHTML=`<tr><td colspan="6" class="text-center py-4 text-danger">
          <i class="fa-solid fa-triangle-exclamation me-1"></i>${esc(d.error||'Failed to load audit logs')}
        </td></tr>`;
        document.getElementById('auditMeta').textContent=''; return;
    }
    if(!d.logs||!d.logs.length){
        tbody.innerHTML=`<tr><td colspan="6"><div class="brs-empty py-4">
          <i class="fa-solid fa-list-check brs-empty-icon d-block mx-auto"></i>
          <h6>No Audit Records${filter?' for this action':''}</h6>
          <p>Audit entries appear here when you create snapshots, restore, or delete.</p>
        </div></td></tr>`;
        document.getElementById('auditFooter').textContent='';
        document.getElementById('auditMeta').textContent=filter?'0 records':'';
        document.getElementById('btnAuditPrev').disabled=true;
        document.getElementById('btnAuditNext').disabled=true; return;
    }
    tbody.innerHTML=d.logs.map(l=>`
    <tr class="audit-row-${l.action_type}">
      <td class="ps-4 text-nowrap small" style="color:var(--text-secondary)">${fmtD(l.created_at)}<br><span style="font-size:.68rem">${timeAgo(l.created_at)}</span></td>
      <td><span class="chip ${auditChip[l.action_type]||'chip-create'}">${auditLabel[l.action_type]||l.action_type}</span></td>
      <td class="fw-semibold small">${esc(l.user_name||'System')}</td>
      <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.8rem" title="${esc(l.snapshot_name)}">${esc(l.snapshot_name||'—')}</td>
      <td class="text-center"><span class="badge bg-secondary-subtle text-secondary" style="font-size:0.75rem">${fmtN(l.products_affected)} Prods<br>${fmtN(l.total_stock_qty)} Items</span></td>
      <td class="pe-4 small text-muted" style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${esc(l.notes)}">${esc(l.notes||'—')}</td>
    </tr>`).join('');
    const from=auditPg*PER_PG+1, to=Math.min(auditPg*PER_PG+d.logs.length,d.total);
    document.getElementById('auditFooter').textContent=`Showing ${from}–${to} of ${fmtN(d.total)} records`;
    document.getElementById('auditMeta').textContent=`${fmtN(d.total)} total records`;
    document.getElementById('btnAuditPrev').disabled=auditPg===0;
    document.getElementById('btnAuditNext').disabled=to>=d.total;
}
document.getElementById('auditFilter').addEventListener('change',()=>{ auditPg=0; loadAuditLogs(); });
document.getElementById('auditDateFilter').addEventListener('change',()=>{ auditPg=0; loadAuditLogs(); });
document.getElementById('btnAuditRefresh').addEventListener('click',()=>{ auditPg=0; loadAuditLogs(); });
document.getElementById('btnAuditPrev').addEventListener('click',()=>{ auditPg--; loadAuditLogs(); });
document.getElementById('btnAuditNext').addEventListener('click',()=>{ auditPg++; loadAuditLogs(); });

/* ════════════════════════════════════════════════════════════════════
   SETTINGS
════════════════════════════════════════════════════════════════════ */
function setTglActive(groupId,val){
    document.querySelectorAll(`#${groupId} .tgl-btn`).forEach(b=>{
        const active=b.dataset.v===val;
        b.classList.toggle('active',active);
    });
}
document.querySelectorAll('#freqGroup .tgl-btn').forEach(b=>b.addEventListener('click',function(){
    document.getElementById('setFreq').value=this.dataset.v;
    setTglActive('freqGroup',this.dataset.v);
}));
document.querySelectorAll('#retGroup .tgl-btn').forEach(b=>b.addEventListener('click',function(){
    document.getElementById('setRet').value=this.dataset.v;
    setTglActive('retGroup',this.dataset.v);
}));
document.querySelectorAll('#threshGroup .tgl-btn').forEach(b=>b.addEventListener('click',function(){
    document.getElementById('setThresh').value=this.dataset.v;
    setTglActive('threshGroup',this.dataset.v);
}));
async function loadSettings(){
    const d=await GET('settings_get');
    if(!d.success) return;
    const s=d.settings||{};
    document.getElementById('setEnabled').checked=s.auto_snapshot_enabled==='1';
    const freq=s.auto_snapshot_frequency||'daily';
    const ret =s.auto_snapshot_retention||'30';
    const thresh=s.auto_snapshot_threshold||'0';
    document.getElementById('setFreq').value=freq;
    document.getElementById('setRet').value=ret;
    document.getElementById('setThresh').value=thresh;
    setTglActive('freqGroup',freq);
    setTglActive('retGroup',ret);
    setTglActive('threshGroup',thresh);

    document.getElementById('setPartial').checked=s.allow_partial_restores==='1';
    document.getElementById('setShopeeSync').checked=s.shopee_auto_sync==='1';
    window.AppConfig = window.AppConfig || {};
    window.AppConfig.allow_partial_restores = s.allow_partial_restores==='1';
    window.AppConfig.shopee_auto_sync = s.shopee_auto_sync==='1';
}
document.getElementById('btnSaveSet').addEventListener('click',async()=>{
    const btn=document.getElementById('btnSaveSet');
    const btn1=document.getElementById('btnSaveSet1');
    btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
    if(btn1){ btn1.disabled=true; btn1.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Saving…'; }
    const payload = {
        auto_snapshot_enabled:document.getElementById('setEnabled').checked?'1':'0',
        auto_snapshot_frequency:document.getElementById('setFreq').value,
        auto_snapshot_retention:document.getElementById('setRet').value,
        auto_snapshot_threshold:document.getElementById('setThresh').value,
        allow_partial_restores:document.getElementById('setPartial').checked?'1':'0',
        shopee_auto_sync:document.getElementById('setShopeeSync').checked?'1':'0'
    };
    const d=await POST('settings_save', payload);
    btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-save me-1"></i>Save Configuration';
    if(btn1){ btn1.disabled=false; btn1.innerHTML='<i class="fa-solid fa-save me-2"></i>Save Configuration'; }
    if(d.success){
        window.AppConfig = window.AppConfig || {};
        window.AppConfig.allow_partial_restores = payload.allow_partial_restores==='1';
        window.AppConfig.shopee_auto_sync = payload.shopee_auto_sync==='1';
        document.querySelectorAll('.set-saved').forEach(el=>{
            el.classList.remove('d-none'); setTimeout(()=>el.classList.add('d-none'),2500);
        });
    } else { showToast('Save failed: '+(d.error||'unknown'),'danger'); }
});

/* ════════════════════════════════════════════════════════════════════
   TAB-AWARE LAZY LOADING
════════════════════════════════════════════════════════════════════ */
let auditLoaded=false;
document.querySelectorAll('.brs-tabs a[data-bs-toggle="tab"]').forEach(tab=>{
    tab.addEventListener('shown.bs.tab',e=>{
        const t=e.target.getAttribute('href');
        if(t==='#t4'&&!auditLoaded){ loadAuditLogs(); auditLoaded=true; }
        else if(t==='#t4'){ loadAuditLogs(); }
        if(t==='#t5') loadSettings();
    });
});

/* ════════════════════════════════════════════════════════════════════
   SNAPSHOT DETAILS MODAL
════════════════════════════════════════════════════════════════════ */
let detId = 0;
let detPg = 0;
let detTimer = null;

async function loadDetails() {
    const q = document.getElementById('detSearch').value.trim();
    const tbody = document.getElementById('detBody');
    
    if (tbody.children.length === 0 || tbody.innerHTML.includes('brs-empty')) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-5"><div class="spinner-border text-muted spinner-border-sm me-2"></div><span class="small fw-semibold text-muted">Searching products...</span></td></tr>';
    } else {
        tbody.style.opacity = '0.4';
        tbody.style.pointerEvents = 'none';
        tbody.style.transition = 'opacity 0.2s';
    }
    
    const d = await GET('detail', { id: detId, limit: 100, offset: detPg * 100, search: q });
    
    tbody.style.opacity = '1';
    tbody.style.pointerEvents = 'auto';

    if (!d.success) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4">${esc(d.error||'Failed to load details')}</td></tr>`;
        return;
    }
    document.getElementById('detName').textContent = d.snapshot.snapshot_name;
    document.getElementById('detCount').textContent = fmtN(d.total) + ' products matched';
    
    if (!d.items.length) {
        tbody.innerHTML = `<tr><td colspan="4"><div class="brs-empty py-5"><i class="fa-solid fa-box-open brs-empty-icon mx-auto d-block"></i><h6>No products found</h6><p>Try searching another SKU or product name.</p></div></td></tr>`;
        document.getElementById('detFoot').textContent = '';
        return;
    }
    tbody.innerHTML = d.items.map(i => `
        <tr>
            <td class="ps-4 py-3 align-middle">
               <div class="d-flex align-items-start gap-2" style="max-width:420px">
                   ${i.brand_name ? `<span class="badge mt-1" style="background:var(--brs-indigo);color:#fff;font-size:.7rem;padding:4px 8px;letter-spacing:.03em;border-radius:4px;box-shadow:0 2px 4px rgba(99,102,241,.2)">${esc(i.brand_name)}</span>` : ''}
                   <div class="fw-bold text-wrap" style="color:var(--text-primary);font-size:.95rem;line-height:1.4" title="${esc(i.product_name)}">${esc(i.product_name)}</div>
               </div>
               <div class="small mt-2 fw-semibold" style="color:#64748b;font-family:monospace;letter-spacing:.05em"><i class="fa-solid fa-barcode me-1" style="color:#cbd5e1"></i>${esc(i.sku||'NO-SKU')}</div>
            </td>
            <td class="text-center align-middle"><span class="badge bg-secondary-subtle text-secondary px-3 py-2 rounded-pill" style="font-size:.85rem">${fmtN(i.total_stock)}</span></td>
            <td class="text-center align-middle"><span class="badge px-3 py-2 rounded-pill" style="background:rgba(99,102,241,.1);color:var(--brs-indigo);font-size:.85rem">${fmtN(i.shopee_allocated)}</span></td>
            <td class="text-center pe-4 align-middle"><span class="badge px-3 py-2 rounded-pill" style="background:rgba(16,185,129,.1);color:var(--brs-emerald);font-size:.85rem">${fmtN(i.current_pos_stock)}</span></td>
        </tr>
    `).join('');
    
    const from = detPg * 100 + 1, to = Math.min((detPg + 1) * 100, d.total);
    document.getElementById('detFoot').textContent = `Showing ${from}-${to} of ${fmtN(d.total)}`;
    document.getElementById('detPrev').disabled = detPg === 0;
    document.getElementById('detNext').disabled = to >= d.total;
}
function openDetail(id) {
    detId = id;
    detPg = 0;
    document.getElementById('detSearch').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mDetail')).show();
    loadDetails();
}

// Progressive Debounced Search
document.getElementById('detSearch').addEventListener('input', (e) => {
    clearTimeout(detTimer);
    detTimer = setTimeout(() => {
        detPg = 0;
        loadDetails();
    }, 400); // 400ms delay after typing stops
});

document.getElementById('detPrev').addEventListener('click', () => { detPg--; loadDetails(); });
document.getElementById('detNext').addEventListener('click', () => { detPg++; loadDetails(); });

/* ════════════════════════════════════════════════════════════════════
   INIT
════════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded',()=>{
    loadStats();
    loadSnaps();
    loadSettings();
});
</script>

<?php require_once '../../includes/footer.php'; ?>
