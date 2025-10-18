<?php
require_once 'includes/header.php';
checkRole(['admin','manager']); // who can adjust

function col_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table,$col]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

// ----------------- Branch resolve (admins can choose; managers fixed)
$branch_id = 0;
if ($user['role_name'] === 'admin') {
  if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])) {
    $branch_id = (int)$_GET['branch_id'];
  } elseif (!empty($user['branch_id'])) {
    $branch_id = (int)$user['branch_id'];
  }
} else {
  $branch_id = (int)($user['branch_id'] ?? 0);
}

// ----------------- Load branches (for admin dropdown)
$branches = [];
$branch_name = '';
try {
  $stmt = $pdo->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name");
  $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($branches as $b) if ((int)$b['id']===$branch_id) $branch_name = $b['name'];
} catch (Throwable $e) {}
if (!$branch_name && !empty($user['branch_name'])) $branch_name = $user['branch_name'];

// ----------------- Preload some products+batches (optional; search will also use AJAX)
$products = [];
if ($branch_id > 0) {
  $has_p_size   = col_exists($pdo,'products','size');
  $has_cat_fk   = col_exists($pdo,'products','category_id');
  $has_brand_fk = col_exists($pdo,'products','brand_id');
  $has_p_bar    = col_exists($pdo,'products','barcode');

  $has_sb_sell  = col_exists($pdo,'stock_batches','selling_price');
  $has_p_sell   = col_exists($pdo,'products','selling_price');
  $has_p_price  = col_exists($pdo,'products','price');

  if ($has_sb_sell)      $sell_expr = "sb.selling_price AS selling_price";
  elseif ($has_p_sell)   $sell_expr = "p.selling_price AS selling_price";
  elseif ($has_p_price)  $sell_expr = "p.price AS selling_price";
  else                   $sell_expr = "0.00 AS selling_price";

  $cat_join = $has_cat_fk ? "LEFT JOIN categories c ON c.id = p.category_id" : "";
  $br_join  = $has_brand_fk ? "LEFT JOIN brands b ON b.id = p.brand_id" : "";

  $sel_bar  = $has_p_bar ? "COALESCE(p.barcode,'') AS barcode" : "'' AS barcode";
  $sel_size = $has_p_size ? "p.size AS product_size"           : "NULL AS product_size";
  $sel_cat  = $has_cat_fk ? "c.name AS cat_name"               : "NULL AS cat_name";
  $sel_br   = $has_brand_fk ? "b.name AS brand_name"           : "NULL AS brand_name";

  $sql = "
    SELECT
      p.id AS product_id, p.name AS product_name, {$sel_bar},
      {$sel_size}, {$sel_cat}, {$sel_br},
      sb.id AS batch_id, COALESCE(sb.batch_no,'') AS batch_no, sb.expiry_date,
      sb.quantity, sb.cost_price, {$sell_expr}
    FROM stock_batches sb
    JOIN products p ON p.id = sb.product_id
    {$cat_join}
    {$br_join}
    WHERE sb.branch_id = :bid
    ORDER BY p.name, sb.expiry_date, sb.id
    LIMIT 400
  ";
  $st = $pdo->prepare($sql);
  $st->execute(['bid' => $branch_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $r) {
    $pid = (int)$r['product_id'];
    if (!isset($products[$pid])) {
      $products[$pid] = [
        'id'       => $pid,
        'name'     => $r['product_name'],
        'barcode'  => $r['barcode'] ?? '',
        'size'     => $r['product_size'],
        'category' => $r['cat_name'],
        'brand'    => $r['brand_name'],
        'batches'  => []
      ];
    }
    $products[$pid]['batches'][] = [
      'id'       => (int)$r['batch_id'],
      'batch_no' => $r['batch_no'] ?: 'N/A',
      'expiry'   => $r['expiry_date'] ?: null,
      'qty'      => (float)$r['quantity'],
      'cost'     => (float)$r['cost_price'],
      'price'    => isset($r['selling_price']) ? (float)$r['selling_price'] : 0.00
    ];
  }
}
$products_json = json_encode(array_values($products), JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Stock Adjustment</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f7fb}
    .card-box{background:#fff;border:1px solid #eaeaea;border-radius:10px;box-shadow:0 3px 10px rgba(0,0,0,.05);padding:18px;margin:18px 0}
    .search-results{position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;max-height:320px;overflow:auto;z-index:1000;display:none}
    .search-item{padding:.5rem .75rem;cursor:pointer}
    .search-item:hover,.search-item.active{background:#f2f5ff}
    .muted{color:#6c757d}
    .table input[type="number"]{max-width:110px}
    .small-muted{font-size:.9rem;color:#6c757d}
    .ledger-pill{font-size:.75rem;padding:.2rem .45rem;border-radius:999px}
    .pill-red{background:#fde7e9;color:#b42318;border:1px solid #f1c0c4}
    .pill-green{background:#e6f5ea;color:#146c2e;border:1px solid #b8e0c1}
  </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>

<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mt-3">
    <h3 class="mb-0">Stock Adjustment</h3>
    <div>
      <?php if ($user['role_name']==='admin'): ?>
        <form method="get" class="d-inline-flex align-items-center gap-2">
          <select class="form-select form-select-sm" name="branch_id" onchange="this.form.submit()">
            <?php foreach ($branches as $b): ?>
              <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id']===$branch_id?'selected':'') ?>>
                <?= htmlspecialchars($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php else: ?>
        <span class="badge text-bg-secondary"><?= htmlspecialchars($branch_name) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <div class="card-box">
    <div class="row g-3">
      <div class="col-lg-5">
        <label class="form-label">Search Product (name / barcode)</label>
        <div class="position-relative">
          <input type="text" class="form-control" id="searchInput" placeholder="Type the first letter to search…" autocomplete="off" <?= $branch_id? '' : 'disabled' ?>>
          <div id="searchResults" class="search-results"></div>
        </div>
        <div class="small-muted mt-2">
          <?= $branch_id? 'Type a letter → results for this branch.' : 'Select a branch to enable search.' ?>
        </div>
      </div>
      <div class="col-lg-7">
        <label class="form-label">Header Reason / Note (optional)</label>
        <div class="input-group">
          <select class="form-select" id="headerReason">
            <option value="">— Select reason (optional) —</option>
            <option value="Split">Split</option>
            <option value="Damage">Damage</option>
            <option value="Expired">Expired</option>
            <option value="Theft">Theft</option>
            <option value="Count Correction">Count Correction</option>
            <option value="Found / Surplus">Found / Surplus</option>
            <option value="Other">Other</option>
          </select>
          <input class="form-control" id="headerNote" placeholder="Short note (optional)">
        </div>
      </div>
    </div>
  </div>

  <div class="card-box">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="mb-0">Adjustment Lines</h5>
      <button class="btn btn-sm btn-outline-danger" id="clearAll" type="button">Clear</button>
    </div>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead class="table-light">
          <tr>
            <th style="min-width:220px">Product</th>
            <th>Batch</th>
            <th class="text-end">Current</th>
            <th class="text-end">Adjust (±)</th>
            <th class="text-end">New Qty</th>
            <th class="text-end">Cost</th>
            <th class="text-end">Value Impact</th>
            <th style="min-width:160px">Reason</th>
            <th style="min-width:140px">Note</th>
            <th style="min-width:190px">Ledger (Inventory / Equity)</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="linesBody">
          <tr><td colspan="11" class="text-center text-muted">No lines added</td></tr>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="7" class="text-end">Total Value Impact:</th>
            <th class="text-end" id="totalImpact" colspan="1">Rs. 0.00</th>
            <th colspan="3"></th>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="text-end">
      <button class="btn btn-primary" id="postAdjustment" <?= ($branch_id>0?'':'disabled') ?>>
        Post Adjustment
      </button>
    </div>
  </div>
</div>

<!-- Batch picker modal -->
<div class="modal fade" id="batchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Select Batch</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div id="batchList"></div>
    </div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const products = <?= $products_json ?: '[]' ?>;
const BRANCH_ID = <?= (int)$branch_id ?>;

const byId = id => document.getElementById(id);
const fmt  = n => 'Rs. ' + (Number(n)||0).toFixed(2);

let lines = []; // line objects

function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}
function ledgerLabel(delta){
  const d = Number(delta)||0;
  if (d < 0) { return '<span class="ledger-pill pill-red">Inv: −</span> <span class="ledger-pill pill-red">Drawings (−)</span>'; }
  if (d > 0) { return '<span class="ledger-pill pill-green">Inv: +</span> <span class="ledger-pill pill-green">Opening Equity (+)</span>'; }
  return '<span class="ledger-pill">—</span>';
}

function renderLines(){
  const tbody = byId('linesBody');
  if (!lines.length){
    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">No lines added</td></tr>';
    byId('totalImpact').textContent = fmt(0);
    return;
  }
  tbody.innerHTML = lines.map((ln, i) => `
    <tr>
      <td>${escapeHtml(ln.product_name)}</td>
      <td>${escapeHtml(ln.batch_no)}${ln.expiry? ' <span class="text-muted">('+escapeHtml(ln.expiry)+')</span>' : ''}</td>
      <td class="text-end">${(ln.before_qty).toFixed(2)}</td>
      <td class="text-end"><input type="number" step="0.01" class="form-control form-control-sm delta" data-i="${i}" value="${ln.delta}"></td>
      <td class="text-end">${(ln.new_qty).toFixed(2)}</td>
      <td class="text-end">${(ln.cost).toFixed(2)}</td>
      <td class="text-end">${(ln.value).toFixed(2)}</td>
      <td>
        <select class="form-select form-select-sm reason" data-i="${i}">
          <option value="">—</option>
          <option${ln.reason==='Split'?' selected':''}>Split</option>
          <option${ln.reason==='Damage'?' selected':''}>Damage</option>
          <option${ln.reason==='Expired'?' selected':''}>Expired</option>
          <option${ln.reason==='Theft'?' selected':''}>Theft</option>
          <option${ln.reason==='Count Correction'?' selected':''}>Count Correction</option>
          <option${ln.reason==='Found / Surplus'?' selected':''}>Found / Surplus</option>
          <option${ln.reason==='Other'?' selected':''}>Other</option>
        </select>
      </td>
      <td><input class="form-control form-control-sm note" data-i="${i}" value="${ln.note||''}"></td>
      <td class="text-start">${ledgerLabel(ln.delta)}</td>
      <td class="text-center"><button class="btn btn-sm btn-outline-danger rm" data-i="${i}">&times;</button></td>
    </tr>
  `).join('');

  const rows = tbody.querySelectorAll('tr');
  tbody.querySelectorAll('.delta').forEach(inp=>{
    inp.addEventListener('input', ()=>{
      const i = +inp.dataset.i, v = parseFloat(inp.value)||0;
      lines[i].delta   = v;
      lines[i].new_qty = (parseFloat(lines[i].before_qty)||0) + v;
      lines[i].value   = parseFloat((v * lines[i].cost).toFixed(2));
      rows[i].cells[4].textContent = lines[i].new_qty.toFixed(2);
      rows[i].cells[6].textContent = lines[i].value.toFixed(2);
      rows[i].cells[9].innerHTML   = ledgerLabel(lines[i].delta);
      const total = lines.reduce((s,ln)=> s + (parseFloat(ln.value)||0), 0);
      byId('totalImpact').textContent = fmt(total);
    });
  });
  tbody.querySelectorAll('.reason').forEach(sel=> sel.addEventListener('change', ()=>{ lines[+sel.dataset.i].reason = sel.value; }));
  tbody.querySelectorAll('.note').forEach(inp=> inp.addEventListener('input', ()=>{ lines[+inp.dataset.i].note = inp.value; }));
  tbody.querySelectorAll('.rm').forEach(btn=> btn.addEventListener('click', ()=>{ lines.splice(+btn.dataset.i,1); renderLines(); }));

  const total = lines.reduce((s,ln)=> s + (parseFloat(ln.value)||0), 0);
  byId('totalImpact').textContent = fmt(total);
}

// ---------- live search: client + server fallback ----------
const searchBox = byId('searchInput');
const resultsEl = byId('searchResults');
let activeIndex = -1;
let lastQuery = '';

function totalQty(p){ return (p.batches||[]).reduce((s,b)=> s + (parseFloat(b.qty)||0), 0); }
function earliestExpiry(p){
  const exps = (p.batches||[]).map(b=>b.expiry).filter(Boolean).sort();
  return exps.length ? exps[0] : '';
}
function localSearch(q){
  const s = q.trim().toLowerCase();
  if (!s) return [];
  const exact = products.find(p => String(p.barcode||'').toLowerCase() === s);
  if (exact) return [exact];
  const pref = products.filter(p => String(p.name||'').toLowerCase().startsWith(s));
  const sub  = products.filter(p =>
    String(p.name||'').toLowerCase().includes(s) ||
    String(p.barcode||'').toLowerCase().includes(s)
  );
  const seen = new Set(); const out = [];
  for (const arr of [pref, sub]) {
    for (const p of arr) { if (seen.has(p.id)) continue; seen.add(p.id); out.push(p); if (out.length>=100) break; }
    if (out.length>=100) break;
  }
  return out;
}
function mergeUnique(primary, secondary){
  const seen = new Set(primary.map(p=>p.id));
  const out = primary.slice();
  for (const p of secondary){ if (!seen.has(p.id)) { seen.add(p.id); out.push(p); } }
  return out;
}
function renderResults(list){
  if (!list.length){ resultsEl.style.display='none'; resultsEl.innerHTML=''; activeIndex=-1; return; }
  resultsEl.innerHTML = list.map((p, i) => {
    const qty = totalQty(p);
    const exp = earliestExpiry(p);
    return `
      <div class="search-item${i===activeIndex?' active':''}" data-id="${p.id}">
        <div><strong>${escapeHtml(p.name)}</strong> ${p.size? '<span class="muted">('+escapeHtml(p.size)+')</span>':''}</div>
        <div class="muted">
          ${p.barcode? 'Barcode: '+escapeHtml(p.barcode)+' • ' : ''}
          ${p.category? escapeHtml(p.category)+' • ' : ''}${p.brand? escapeHtml(p.brand)+' • ' : ''}
          Stock: ${(Number(qty)||0).toFixed(2)} ${exp? ' • Earliest Exp: '+escapeHtml(exp):''}
        </div>
      </div>
    `;
  }).join('');
  resultsEl.style.display='block';
  resultsEl.querySelectorAll('.search-item').forEach(div=>{
    div.addEventListener('click', ()=>{
      const pid = +div.dataset.id;
      resultsEl.style.display='none';
      searchBox.value = '';
      const p = list.find(x=>x.id===pid) || products.find(x=>x.id===pid);
      if (p) openBatchPicker(p);
    });
  });
}

async function remoteSearch(q){
  if (!BRANCH_ID) return [];
  const url = 'ajax_search_products_for_adjust.php?q=' + encodeURIComponent(q) + '&branch_id=' + BRANCH_ID;
  const resp = await fetch(url, {headers: {'Accept':'application/json'}});
  if (!resp.ok) return [];
  const js = await resp.json();
  if (!Array.isArray(js)) return [];
  return js;
}

let debounceTimer=null;
if (BRANCH_ID){
  searchBox.addEventListener('input', ()=>{
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(async ()=>{
      const q = searchBox.value;
      lastQuery = q;
      // start with local hits (if any were preloaded)
      let hits = localSearch(q);
      renderResults(hits);
      // add remote results (server authoritative, branch-scoped)
      if (q.trim()){
        try {
          const remote = await remoteSearch(q);
          if (lastQuery === q){
            hits = mergeUnique(hits, remote);
            renderResults(hits);
          }
        } catch(e){}
      }
    }, 120);
  });
}
searchBox?.addEventListener('keydown', (e)=>{
  const items = Array.from(resultsEl.querySelectorAll('.search-item'));
  if (!items.length) return;
  if (e.key === 'ArrowDown'){ activeIndex = Math.min(items.length-1, activeIndex+1); e.preventDefault(); }
  else if (e.key === 'ArrowUp'){ activeIndex = Math.max(0, activeIndex-1); e.preventDefault(); }
  else if (e.key === 'Enter'){ if (activeIndex>=0){ items[activeIndex].click(); e.preventDefault(); } }
  else if (e.key === 'Escape'){ resultsEl.style.display='none'; activeIndex = -1; }
  items.forEach((el,i)=> el.classList.toggle('active', i===activeIndex));
});
document.addEventListener('click', (e)=>{
  if (!e.target.closest('.position-relative')) resultsEl.style.display = 'none';
});

// ----------------------- Batch picker + lines -----------------------
function openBatchPicker(product){
  const list = product.batches || [];
  if (!list.length) { alert('No batches for this product in this branch.'); return; }
  const wrap = byId('batchList');
  wrap.innerHTML = list.map(b => `
    <div class="border rounded p-2 mb-2 d-flex justify-content-between align-items-center">
      <div>
        <div><strong>${escapeHtml(product.name)}</strong> ${product.size? '<span class="muted">('+escapeHtml(product.size)+')</span>':''}</div>
        <div class="muted">Batch: ${escapeHtml(b.batch_no || 'N/A')} ${b.expiry? ' | Exp: '+escapeHtml(b.expiry) : ''}</div>
        <div class="muted">Current: ${(Number(b.qty)||0).toFixed(2)} | Cost: ${(Number(b.cost)||0).toFixed(2)}</div>
      </div>
      <button class="btn btn-sm btn-primary pick" data-p="${product.id}" data-b="${b.id}">Select</button>
    </div>
  `).join('');
  wrap.querySelectorAll('.pick').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const pid = +btn.dataset.p;
      const bid = +btn.dataset.b;
      const p = product.id===pid ? product : (products.find(x=>x.id===pid) || product);
      const b = (p?.batches||[]).find(x=>x.id===bid);
      if (!p || !b) return;

      lines.push({
        product_id: pid,
        product_name: p.name,
        batch_id: bid,
        batch_no: b.batch_no || 'N/A',
        expiry: b.expiry || '',
        before_qty: Number(b.qty)||0,
        delta: 0,
        new_qty: Number(b.qty)||0,
        cost: Number(b.cost)||0,
        value: 0,
        reason: '',
        note: ''
      });
      renderLines();
      bootstrap.Modal.getInstance(byId('batchModal')).hide();
    });
  });
  new bootstrap.Modal(byId('batchModal')).show();
}

byId('clearAll').addEventListener('click', ()=>{
  if (!lines.length) return;
  if (!confirm('Clear all lines?')) return;
  lines = [];
  renderLines();
});

// ----------------------- Post Adjustment -----------------------
byId('postAdjustment').addEventListener('click', ()=>{
  if (!BRANCH_ID) { alert('Select a branch first.'); return; }
  if (!lines.length) { alert('Add at least one line.'); return; }
  for (const ln of lines){
    const after = (parseFloat(ln.before_qty)||0) + (parseFloat(ln.delta)||0);
    if (after < -0.00001) { alert('Negative stock would result for '+ln.product_name+' / '+ln.batch_no); return; }
  }
  const payload = {
    branch_id: BRANCH_ID,
    header_reason: byId('headerReason').value || null,
    header_note: byId('headerNote').value || null,
    lines: lines.map(ln => ({
      product_id: ln.product_id,
      batch_id: ln.batch_id,
      delta_qty: parseFloat(ln.delta)||0,
      line_reason: ln.reason || null,
      line_note: ln.note || null
    }))
  };
  fetch('ajax_save_adjustment.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
  .then(r=>r.json())
  .then(j=>{
    if (j && j.status==='success'){
      alert('Adjustment saved. Ref #'+j.adjustment_id);
      lines = []; renderLines();
    } else { alert('Error: '+ (j?.message || 'Failed to save adjustment')); }
  })
  .catch(err=> alert('Network error: '+err));
});

renderLines();
</script>
</body>
</html>
