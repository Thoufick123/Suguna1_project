<?php
// arduino.php - Arduino Push Button Counter Page
$pageTitle = 'Arduino Button Counter - FabriqPro';
require_once __DIR__ . '/db.php';
$pdo = Database::getConnection();
$workersStmt = $pdo->query("SELECT id, unique_id, name, unit, role, total_pieces FROM workers ORDER BY name ASC");
$allWorkers = $workersStmt->fetchAll();
$unitsStmt = $pdo->query("SELECT * FROM production_units WHERE COALESCE(NULLIF(status, ''), 'Active') != 'Disabled' ORDER BY id ASC");
$productionUnits = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($productionUnits)) {
    $productionUnits = [
        ['unit_name' => 'Unit 1', 'color' => 'cyan'],
        ['unit_name' => 'Unit 2', 'color' => 'pink'],
    ];
}
$catStmt = $pdo->query("SELECT id, category_name, subcategory_name, sub_subcategory_name FROM garment_categories WHERE status = 'Active' ORDER BY category_name ASC, subcategory_name ASC, sub_subcategory_name ASC");
$garmentCategories = [];
while ($catRow = $catStmt->fetch()) {
    $c = $catRow['category_name'];
    $s = $catRow['subcategory_name'];
    $g = $catRow['sub_subcategory_name'] ?: $s;
    $garmentCategories[$c . ' › ' . $s][] = [
        'id' => $catRow['id'],
        'name' => $g
    ];
}
include __DIR__ . '/includes/header.php';
?>
<style>
.arduino-hero{display:grid;grid-template-columns:1fr 420px;gap:2rem;align-items:start}
@media(max-width:1024px){.arduino-hero{grid-template-columns:1fr}}
@media(max-width:768px){
  .config-panel,.counter-panel{padding:1.15rem 0.95rem !important}
  .counter-display{font-size:clamp(3.5rem, 16vw, 6.5rem) !important}
  .arduino-hero{gap:1rem !important}
  .instructions-box{padding:1rem !important}
}
.config-panel,.counter-panel,.activity-log{background:var(--card-bg);border:1px solid var(--border-color);border-radius:16px}
.config-panel{padding:2rem}.config-panel h2{font-size:1.1rem;font-weight:700;margin-bottom:1.5rem;display:flex;align-items:center;gap:.6rem}
.counter-panel{padding:2rem;text-align:center;position:relative;overflow:hidden}
.counter-panel::before{content:'';position:absolute;top:-60px;left:-60px;width:220px;height:220px;background:radial-gradient(circle,rgba(6,182,212,.15) 0%,transparent 70%);pointer-events:none}
.counter-panel::after{content:'';position:absolute;bottom:-60px;right:-60px;width:220px;height:220px;background:radial-gradient(circle,rgba(139,92,246,.12) 0%,transparent 70%);pointer-events:none}
.counter-label{font-size:.82rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);margin-bottom:.75rem}
.counter-display{font-size:7rem;font-weight:800;font-family:'Space Grotesk',sans-serif;line-height:1;background:linear-gradient(135deg,#06b6d4,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;transition:transform .15s cubic-bezier(.68,-.55,.27,1.55);display:block;margin:.5rem 0;position:relative;z-index:1}
.counter-display.bump{transform:scale(1.18)}
.counter-session-label{font-size:.78rem;color:var(--text-muted);margin-top:.25rem}
.worker-badge-counter{display:inline-flex;align-items:center;gap:.6rem;background:rgba(6,182,212,.08);border:1px solid rgba(6,182,212,.25);border-radius:50px;padding:.45rem 1.1rem;font-size:.88rem;font-weight:600;color:var(--accent-cyan);margin:1rem 0 .5rem}
.status-row{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:1.25rem;font-size:.82rem;font-weight:500;color:var(--text-muted)}
.status-dot{width:10px;height:10px;border-radius:50%;background:#ef4444;flex-shrink:0}
.status-dot.listening{background:#10b981;animation:pg 1.4s ease-in-out infinite}
.status-dot.waiting{background:#f59e0b;animation:pa 1.4s ease-in-out infinite}
@keyframes pg{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.5)}50%{box-shadow:0 0 0 6px rgba(16,185,129,0)}}
@keyframes pa{0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.5)}50%{box-shadow:0 0 0 6px rgba(245,158,11,0)}}
.manual-btn{margin-top:1.5rem;width:100%;padding:1rem;font-size:1rem;font-weight:700;border-radius:12px;background:linear-gradient(135deg,#06b6d4,#8b5cf6);color:#fff;border:none;cursor:pointer;transition:transform .1s,box-shadow .2s;box-shadow:0 4px 20px rgba(6,182,212,.35);display:flex;align-items:center;justify-content:center;gap:.6rem}
.manual-btn:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(6,182,212,.45)}
.manual-btn:active{transform:scale(.97)}
.manual-btn:disabled{opacity:.45;cursor:not-allowed;transform:none}
.activity-log{margin-top:2rem;overflow:hidden}
.activity-log-header{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between}
.activity-log-header h3{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.5rem}
.activity-log-body{max-height:300px;overflow-y:auto;padding:.5rem 0}
.log-entry{display:flex;align-items:center;gap:1rem;padding:.6rem 1.5rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.83rem;animation:sil .3s ease}
.log-entry:last-child{border-bottom:none}
@keyframes sil{from{opacity:0;transform:translateX(-10px)}to{opacity:1;transform:translateX(0)}}
.log-entry .log-icon{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;flex-shrink:0}
.log-entry.log-press .log-icon{background:rgba(16,185,129,.15);color:#10b981}
.log-entry.log-error .log-icon{background:rgba(239,68,68,.15);color:#ef4444}
.log-entry.log-info .log-icon{background:rgba(6,182,212,.15);color:#06b6d4}
.log-time{color:var(--text-muted);font-size:.76rem;min-width:72px}.log-msg{flex:1}
.instructions-box{background:rgba(6,182,212,.05);border:1px solid rgba(6,182,212,.2);border-radius:12px;padding:1.25rem 1.5rem;margin-top:1.5rem;font-size:.85rem}
.instructions-box h4{font-size:.9rem;font-weight:700;color:var(--accent-cyan);margin-bottom:.75rem;display:flex;align-items:center;gap:.5rem}
.instructions-box ol{margin:0;padding-left:1.25rem;color:var(--text-muted);line-height:1.9}
.instructions-box ol li strong{color:var(--text-primary)}
.info-stats-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:1.25rem}
.info-stat-card{background:rgba(255,255,255,.03);border:1px solid var(--border-color);border-radius:10px;padding:.75rem 1rem;text-align:center}
.info-stat-card .val{font-size:1.5rem;font-weight:800;font-family:'Space Grotesk',monospace;color:var(--accent-cyan)}
.info-stat-card .lbl{font-size:.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-top:.2rem}
.not-configured-overlay{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.75rem;padding:3rem 1.5rem;text-align:center;color:var(--text-muted)}
.not-configured-overlay i{font-size:3rem;color:var(--border-color)}
.not-configured-overlay p{font-size:.9rem;max-width:220px}
</style>

<div class="page-header">
    <div class="page-header-title">
        <h1><i class="fa-solid fa-microchip text-cyan"></i> Arduino Button Counter</h1>
        <p>Connect your Arduino push button to increment piece counts in real-time via USB serial.</p>
    </div>
    <div class="page-header-actions">
        <a href="daily_output.php" class="btn btn-outline"><i class="fa-solid fa-shirt"></i> Today's Clothes</a>
        <a href="leaderboard.php" class="btn btn-primary"><i class="fa-solid fa-trophy"></i> Leaderboard</a>
    </div>
</div>

<div class="arduino-hero">
<div>
  <div class="config-panel">
    <h2><i class="fa-solid fa-sliders text-cyan"></i> Session Configuration</h2>
    <div class="form-grid">
      <div class="col-12">
        <div class="form-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.45rem;flex-wrap:wrap;gap:0.5rem;">
            <label class="form-label" for="arduinoWorkerSelect" style="margin-bottom:0;"><i class="fa-solid fa-user-check"></i> Select Worker <span class="required">*</span></label>
            <div class="unit-filter-bar">
              <span style="font-size:0.72rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Unit:</span>
              <button type="button" class="unit-filter-btn ard-unit-btn active" data-unit="all">All</button>
              <?php foreach ($productionUnits as $pu): ?>
                <button type="button" class="unit-filter-btn ard-unit-btn" data-unit="<?= htmlspecialchars($pu['unit_name']) ?>" data-color="<?= htmlspecialchars($pu['color'] ?? 'cyan') ?>">
                  <?= htmlspecialchars($pu['unit_name']) ?>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
          <select id="arduinoWorkerSelect" class="form-control" style="font-weight:600;">
            <option value="">-- Choose Worker --</option>
            <?php foreach ($allWorkers as $w): ?>
            <option value="<?= $w['id'] ?>" data-name="<?= htmlspecialchars($w['name']) ?>" data-unit="<?= htmlspecialchars($w['unit']) ?>" data-role="<?= htmlspecialchars($w['role']) ?>" data-pieces="<?= (int)$w['total_pieces'] ?>" data-unique="<?= htmlspecialchars($w['unique_id']) ?>">
              <?= htmlspecialchars($w['unique_id']) ?> &mdash; <?= htmlspecialchars($w['name']) ?> (<?= htmlspecialchars($w['unit']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="col-12">
        <div class="form-group">
          <label class="form-label" for="arduinoMaterialSelect"><i class="fa-solid fa-layer-group"></i> Material / Fabric <span class="required">*</span></label>
          <select id="arduinoMaterialSelect" class="form-control" style="font-weight:600;">
            <?php if (empty($garmentCategories)): ?>
              <option value="">-- No Categories Added Yet (Only Admin Added Categories Will Appear) --</option>
            <?php else: ?>
              <option value="">-- Choose Material / Garment --</option>
              <?php foreach ($garmentCategories as $groupLabel => $items): ?>
                <optgroup label="<?= htmlspecialchars($groupLabel) ?>">
                  <?php foreach ($items as $item): ?>
                    <option value="<?= htmlspecialchars($item['name']) ?>"><?= htmlspecialchars($item['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
      </div>
      <div class="col-6">
        <div class="form-group">
          <label class="form-label" for="arduinoShift"><i class="fa-solid fa-clock"></i> Shift</label>
          <select id="arduinoShift" class="form-control">
            <option>Morning Shift</option><option selected>Day Shift</option><option>Evening Shift</option><option>Night Shift</option><option>Overtime</option>
          </select>
        </div>
      </div>
      <div class="col-6">
        <div class="form-group">
          <label class="form-label" for="arduinoDate"><i class="fa-solid fa-calendar-day"></i> Date</label>
          <input type="date" id="arduinoDate" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="col-12">
        <div class="form-group">
          <label class="form-label" for="incrementStep"><i class="fa-solid fa-arrow-up-1-9"></i> Pieces per Button Press</label>
          <div style="display:flex;gap:.5rem;align-items:center;">
            <input type="number" id="incrementStep" class="form-control" min="1" max="100" value="1" style="max-width:120px;font-size:1.1rem;font-weight:700;text-align:center;">
            <div style="display:flex;gap:.4rem;">
              <button type="button" class="btn btn-outline btn-sm step-preset" data-val="1">+1</button>
              <button type="button" class="btn btn-outline btn-sm step-preset" data-val="5">+5</button>
              <button type="button" class="btn btn-outline btn-sm step-preset" data-val="10">+10</button>
            </div>
          </div>
        </div>
      </div>
      <div class="col-12" style="margin-top:.5rem;">
        <button type="button" id="startSessionBtn" class="btn btn-success" style="width:100%;font-size:1rem;padding:.85rem;font-weight:700;"><i class="fa-solid fa-circle-play"></i> Start Arduino Session</button>
        <button type="button" id="stopSessionBtn" class="btn btn-outline" style="width:100%;font-size:1rem;padding:.85rem;font-weight:700;display:none;margin-top:.6rem;border-color:#ef4444;color:#ef4444;"><i class="fa-solid fa-circle-stop"></i> Stop Session</button>
      </div>
    </div>
    <div class="instructions-box">
      <h4><i class="fa-brands fa-usb"></i> Setup Instructions</h4>
      <ol>
        <li><strong>Upload</strong> <code>arduino_sketch.ino</code> to your Arduino Uno</li>
        <li><strong>Wire:</strong> Pin 2 &rarr; Push Button &rarr; GND (uses INPUT_PULLUP)</li>
        <li><strong>Run:</strong> <code>python arduino_bridge.py</code> on this PC</li>
        <li><strong>Select</strong> worker &amp; material above, click <strong>Start</strong></li>
        <li>Each button press = <strong>+1 piece</strong> saved instantly to database</li>
      </ol>
    </div>
  </div>
</div>

<div>
  <div class="counter-panel">
    <div class="counter-label">Session Piece Count</div>
    <span class="counter-display" id="sessionCounter">0</span>
    <div class="counter-session-label">pieces this session</div>
    <div id="workerBadge" class="worker-badge-counter" style="display:none;"><i class="fa-solid fa-circle-dot"></i><span id="workerBadgeText">Worker</span></div>
    <div id="notConfiguredMsg" class="not-configured-overlay"><i class="fa-solid fa-circle-question"></i><p>Configure worker &amp; material, then start a session</p></div>
    <div class="status-row" id="statusRow" style="display:none;"><div class="status-dot" id="statusDot"></div><span id="statusText">Listening...</span></div>
    <button class="manual-btn" id="manualIncBtn" disabled><i class="fa-solid fa-hand-pointer"></i> Manual +1 (Test / No Arduino)</button>
    <div class="info-stats-row" id="statsRow" style="display:none;">
      <div class="info-stat-card"><div class="val" id="totalDbPieces">0</div><div class="lbl">Total DB Pieces</div></div>
      <div class="info-stat-card"><div class="val" id="todayDbPieces">0</div><div class="lbl">Today's Pieces</div></div>
    </div>
  </div>
  <div class="activity-log" id="activityLog" style="display:none;">
    <div class="activity-log-header">
      <h3><i class="fa-solid fa-list-ul text-cyan"></i> Button Press Log</h3>
      <button type="button" class="btn btn-outline btn-sm" id="clearLogBtn"><i class="fa-solid fa-trash"></i> Clear</button>
    </div>
    <div class="activity-log-body" id="logBody"></div>
  </div>
</div>
</div>

<script>
(function(){
'use strict';
var S={active:false,count:0,total:0,today:0,timer:null,wid:null,wname:'',wuid:'',mat:'',shift:'',date:'',step:1};
function $(i){return document.getElementById(i);}
var wSel=$('arduinoWorkerSelect'),mSel=$('arduinoMaterialSelect'),sSel=$('arduinoShift'),dIn=$('arduinoDate'),stIn=$('incrementStep');
var startBtn=$('startSessionBtn'),stopBtn=$('stopSessionBtn'),manBtn=$('manualIncBtn');
var cEl=$('sessionCounter'),wBadge=$('workerBadge'),wBText=$('workerBadgeText'),ncMsg=$('notConfiguredMsg');
var sRow=$('statusRow'),sDot=$('statusDot'),sText=$('statusText'),stRow=$('statsRow');
var aLog=$('activityLog'),lBody=$('logBody'),tEl=$('totalDbPieces'),tdEl=$('todayDbPieces'),clrBtn=$('clearLogBtn');

document.querySelectorAll('.step-preset').forEach(function(b){
  b.addEventListener('click',function(){stIn.value=b.dataset.val;document.querySelectorAll('.step-preset').forEach(function(x){x.classList.remove('btn-primary');});b.classList.add('btn-primary');});
});

var ardUnitBtns=document.querySelectorAll('.ard-unit-btn');
if(ardUnitBtns.length&&wSel){
  var allArdOpts=Array.from(wSel.options).map(function(o){
    return {val:o.value,txt:o.text,name:o.dataset.name,unit:o.dataset.unit,role:o.dataset.role,pieces:o.dataset.pieces,unique:o.dataset.unique};
  });
  ardUnitBtns.forEach(function(btn){
    btn.addEventListener('click',function(){
      var unit=btn.dataset.unit;
      ardUnitBtns.forEach(function(b){b.classList.remove('active','active-cyan','active-pink','active-emerald');});
      btn.classList.add('active');
      if(btn.dataset.color)btn.classList.add('active-'+btn.dataset.color);
      var curVal=wSel.value;
      wSel.innerHTML='';
      var filtered=allArdOpts.filter(function(o){return !o.val||unit==='all'||!unit||o.unit===unit;});
      var found=false;
      filtered.forEach(function(o){
        var opt=document.createElement('option');
        opt.value=o.val;opt.text=o.txt;
        if(o.name)opt.dataset.name=o.name;
        if(o.unit)opt.dataset.unit=o.unit;
        if(o.role)opt.dataset.role=o.role;
        if(o.pieces)opt.dataset.pieces=o.pieces;
        if(o.unique)opt.dataset.unique=o.unique;
        if(o.val&&o.val===curVal){opt.selected=true;found=true;}
        wSel.appendChild(opt);
      });
      if(!found)wSel.selectedIndex=0;
    });
  });
}

startBtn.addEventListener('click',function(){
  if(!wSel.value){toast('Please select a worker.','error');return;}
  if(!mSel.value){toast('Please select a material.','error');return;}
  var opt=wSel.options[wSel.selectedIndex];
  S.wid=wSel.value;S.wname=opt.dataset.name;S.wuid=opt.dataset.unique;
  S.mat=mSel.value;S.shift=sSel.value;S.date=dIn.value;S.step=Math.max(1,parseInt(stIn.value)||1);
  S.count=0;S.active=true;cEl.textContent='0';
  ncMsg.style.display='none';wBadge.style.display='inline-flex';wBText.textContent=S.wuid+' \u2022 '+S.wname;
  sRow.style.display='flex';stRow.style.display='grid';aLog.style.display='block';
  startBtn.style.display='none';stopBtn.style.display='block';manBtn.disabled=false;
  [wSel,mSel,sSel,dIn,stIn].forEach(function(e){e.disabled=true;});
  setStatus('listening','Listening on bridge \u2022 '+S.wname+' \u2022 '+S.mat);
  S.timer=setInterval(poll,500);
  addLog('info','Session started \u2014 '+S.wname+' / '+S.mat+' / '+S.shift);
  toast('Session started! Press the Arduino button to count.','success');
});

stopBtn.addEventListener('click',function(){
  S.active=false;clearInterval(S.timer);S.timer=null;
  startBtn.style.display='block';stopBtn.style.display='none';manBtn.disabled=true;
  [wSel,mSel,sSel,dIn,stIn].forEach(function(e){e.disabled=false;});
  setStatus('off','Session stopped. Total: '+S.count+' pcs');
  addLog('info','Session ended \u2014 '+S.count+' pieces logged');
  toast('Session ended. '+S.count+' pieces logged.','success');
});

manBtn.addEventListener('click',function(){if(S.active)inc(S.step,'Manual click');});

function poll(){
  if(!S.active)return;
  fetch('arduino_bridge.php?action=check',{cache:'no-store'}).then(function(r){
    if(!r.ok)return;
    return r.json();
  }).then(function(d){
    if(d&&d.presses>0){
      var n=d.presses;
      (function loop(i){if(i>=n)return;inc(S.step,'Arduino button');setTimeout(function(){loop(i+1);},80);})(0);
    }
  }).catch(function(){});
}

function inc(count,src){
  if(!S.active)return;
  setStatus('waiting','Saving to database...');
  var body=JSON.stringify({action:'log_pieces',worker_id:S.wid,material_name:S.mat,piece_count:count,work_date:S.date,shift:S.shift,mode:'add',notes:'Arduino button'});
  fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:body}).then(function(r){return r.json();}).then(function(d){
    if(d.success){
      S.count+=count;S.total=d.new_total_pieces||0;S.today=d.today_pieces||0;
      cEl.textContent=S.count;bump();
      tEl.textContent=S.total;tdEl.textContent=S.today;
      addLog('press','+'+count+' piece'+(count>1?'s':'')+' \u2022 Today: '+S.today+' pcs',src);
      setStatus('listening','Listening \u2022 '+S.wname+' \u2022 '+S.mat);
    }else{addLog('error','Save failed: '+d.message,src);setStatus('listening','Error \u2014 retrying...');}
  }).catch(function(e){addLog('error','Network error: '+e.message,src);setStatus('listening','Network error');});
}

function bump(){cEl.classList.add('bump');setTimeout(function(){cEl.classList.remove('bump');},200);}
function setStatus(t,m){sText.textContent=m;sDot.className='status-dot';if(t==='listening')sDot.classList.add('listening');else if(t==='waiting')sDot.classList.add('waiting');}
function addLog(type,msg,src){
  var e=document.createElement('div');e.className='log-entry log-'+type;
  var ic={press:'fa-plus',error:'fa-triangle-exclamation',info:'fa-circle-info'};
  var n=new Date(),t=n.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
  e.innerHTML='<div class="log-icon"><i class="fa-solid '+(ic[type]||'fa-circle')+'"></i></div><span class="log-time">'+t+'</span><span class="log-msg">'+msg+(src?' <span style="color:var(--text-muted);font-size:.74rem;">('+src+')</span>':'')+'</span>';
  lBody.insertBefore(e,lBody.firstChild);
  while(lBody.children.length>50)lBody.removeChild(lBody.lastChild);
}
clrBtn.addEventListener('click',function(){lBody.innerHTML='';});
function toast(msg,type){
  var t=document.createElement('div');
  t.style.cssText='position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;background:'+(type==='success'?'#10b981':type==='error'?'#ef4444':'#06b6d4')+';color:#fff;padding:.75rem 1.25rem;border-radius:10px;font-size:.88rem;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.3);max-width:340px;';
  t.textContent=msg;document.body.appendChild(t);setTimeout(function(){t.remove();},3500);
}
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
