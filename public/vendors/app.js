(function(){
  'use strict';

  var STATUS_LABELS = {
    increased:'ფასი გაიზარდა', decreased:'ფასი დაეცა', unchanged:'უცვლელი',
    added:'ახალი პოზიცია', removed:'მოხსნილი პოზიცია',
    price_added:'ფასი გაჩნდა', price_removed:'ფასი მოიხსნა'
  };
  var CODE_KEYWORDS = ['sku','code','კოდ','ბარკოდ','barcode','ean','item no','item. no','id','არტიკ'];
  var NAME_KEYWORDS = ['name','დასახელ','პროდუქ','product','model','მოდელ','brand','ბრენდ','nomenklatura','ნომენკლატურ'];
  var PRICE_KEYWORDS = ['price','ფას','drp','rrp','cost','სადილერო','სარეკომენდაციო','promo','special','selling','sale','wholesale','retail','main','sarekomendacio','dileri','vip'];

  var state = { vendors: [], currentVendorId: null, snapshots: [], upload: null, compare: null };
  var API = 'api.php';

  function $(id){ return document.getElementById(id); }
  function escapeHtml(s){
    return String(s===null||s===undefined?'':s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function colLetter(n){ var s=''; while(n>0){ var m=(n-1)%26; s=String.fromCharCode(65+m)+s; n=Math.floor((n-1)/26); } return s; }
  function matchesAny(text, kws){ var t=String(text).toLowerCase(); return kws.some(function(k){ return t.indexOf(k)!==-1; }); }
  function toNumberOrNull(raw){
    if(raw===null||raw===undefined) return null;
    if(typeof raw==='number') return isFinite(raw)?raw:null;
    var s=String(raw).trim(); if(s==='') return null;
    s=s.replace(/[,\s]/g,'').replace(/[^0-9.\-]/g,'');
    if(s===''||s==='-'||s==='.') return null;
    var n=parseFloat(s); return isFinite(n)?n:null;
  }
  function cellToPlain(v){
    if(v===null||v===undefined) return '';
    if(v instanceof Date) return v.toISOString().slice(0,10);
    return v;
  }

  // ---------- API helpers ----------

  function apiGet(params){
    return fetch(API + '?' + params).then(function(r){ return r.json().then(function(data){
      if(!r.ok) throw new Error(data.error || ('HTTP '+r.status));
      return data;
    }); });
  }
  function apiPost(body){
    return fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)}).then(function(r){
      return r.json().then(function(data){
        if(!r.ok) throw new Error(data.error || ('HTTP '+r.status));
        return data;
      });
    });
  }

  // ---------- parsing (SheetJS) ----------

  function parseWorkbookFile(file){
    return file.arrayBuffer().then(function(buf){
      var wb = XLSX.read(buf, {type:'array', cellDates:true});
      return wb.SheetNames.map(function(name){
        var ws = wb.Sheets[name];
        var rows = XLSX.utils.sheet_to_json(ws, {header:1, defval:'', raw:true});
        rows = rows.map(function(r){ return r.map(cellToPlain); });
        return { name: name, rows: rows, rowCount: rows.length };
      });
    });
  }

  function guessHeaderRowIndex(previewRows){
    var bestIdx=0, bestScore=-1, limit=Math.min(previewRows.length,10);
    for(var i=0;i<limit;i++){
      var row=previewRows[i], score=0;
      for(var j=0;j<row.length;j++){
        var t=String(row[j]).trim(); if(!t) continue;
        if(matchesAny(t,CODE_KEYWORDS)||matchesAny(t,NAME_KEYWORDS)||matchesAny(t,PRICE_KEYWORDS)) score+=2;
        else if(/^[A-Za-zა-ჰ]/.test(t) && t.length<40) score+=1;
      }
      if(score>bestScore){ bestScore=score; bestIdx=i; }
    }
    return bestIdx;
  }

  function guessColumnRoles(headerRow){
    var codeCol=null, nameCol=null, priceOptions=[], used={};
    headerRow.forEach(function(label, idx){
      var col=idx+1, t=String(label).trim(); if(!t) return;
      if(codeCol===null && matchesAny(t,CODE_KEYWORDS)){ codeCol=col; used[col]=1; }
    });
    headerRow.forEach(function(label, idx){
      var col=idx+1, t=String(label).trim(); if(!t||used[col]) return;
      if(nameCol===null && matchesAny(t,NAME_KEYWORDS)){ nameCol=col; used[col]=1; }
    });
    headerRow.forEach(function(label, idx){
      var col=idx+1, t=String(label).trim(); if(!t) return;
      if(matchesAny(t,PRICE_KEYWORDS)) priceOptions.push({col:col, label:t});
    });
    if(nameCol===null){
      headerRow.forEach(function(label, idx){
        var col=idx+1, t=String(label).trim();
        if(nameCol===null && t && !used[col] && !priceOptions.some(function(p){return p.col===col;})){ nameCol=col; used[col]=1; }
      });
    }
    var priceCol = priceOptions.length ? priceOptions[0].col : null;
    return { codeCol:codeCol, nameCol:nameCol, priceCol:priceCol, priceOptions:priceOptions };
  }

  function maxColsForSheet(sheet){
    var max=0; sheet.rows.slice(0,10).forEach(function(r){ if(r.length>max) max=r.length; });
    return Math.max(max,20);
  }

  function extractProducts(rawSheets, sheetMappings){
    var products=[], warnings=[];
    var byName={}; rawSheets.forEach(function(s){ byName[s.name]=s; });
    sheetMappings.filter(function(s){return s.include;}).forEach(function(cfg){
      var sheet = byName[cfg.name];
      if(!sheet) return;
      var extracted=0;
      for(var r=cfg.headerRow+1; r<sheet.rows.length; r++){
        var row=sheet.rows[r];
        var nameRaw = cfg.nameCol ? row[cfg.nameCol-1] : '';
        var codeRaw = cfg.codeCol ? row[cfg.codeCol-1] : '';
        var priceRaw = cfg.priceCol ? row[cfg.priceCol-1] : '';
        var name = String(nameRaw===undefined?'':nameRaw).trim();
        var code = String(codeRaw===undefined?'':codeRaw).trim();
        var price = toNumberOrNull(priceRaw);
        if(!name && !code) continue;
        if(price===null && !name) continue;
        products.push({ sheet: cfg.name, code: code||null, name: name||null, price: price });
        extracted++;
      }
      if(extracted===0) warnings.push('ფურცელმა "'+cfg.name+'" 0 მწკრივი დააბრუნა მოცემული რუკირებით.');
    });
    return { products: products, warnings: warnings };
  }

  // ---------- compare ----------

  function normalizeCode(c){ if(!c) return null; return String(c).trim().toUpperCase().replace(/\s+/g,''); }
  function normalizeName(n){ if(!n) return null; return String(n).trim().toLowerCase().replace(/\s+/g,' '); }
  function round2(n){ if(n===null||n===undefined||!isFinite(n)) return null; return Math.round(n*100)/100; }

  function compareSnapshots(oldProducts, newProducts){
    var byCode={}, byName={};
    oldProducts.forEach(function(p, idx){
      var nc=normalizeCode(p.code), nn=normalizeName(p.name);
      if(nc && !(nc in byCode)) byCode[nc]=idx;
      if(nn && !(nn in byName)) byName[nn]=idx;
    });
    var matchedOld={}, rows=[];
    newProducts.forEach(function(np){
      var nc=normalizeCode(np.code), nn=normalizeName(np.name);
      var oldIdx=null, matchedBy=null;
      if(nc!==null && (nc in byCode)){ oldIdx=byCode[nc]; matchedBy='code'; }
      else if(nn!==null && (nn in byName)){ oldIdx=byName[nn]; matchedBy='name'; }
      if(oldIdx!==null && !matchedOld[oldIdx]){
        matchedOld[oldIdx]=true;
        var op=oldProducts[oldIdx], oldPrice=op.price, newPrice=np.price;
        var status='unchanged', diff=null, pct=null;
        if(oldPrice===null && newPrice===null){ status='unchanged'; }
        else if(oldPrice===null){ status='price_added'; }
        else if(newPrice===null){ status='price_removed'; }
        else {
          diff=round2(newPrice-oldPrice);
          pct = oldPrice!==0 ? round2((newPrice-oldPrice)/oldPrice*100) : null;
          status = diff>0?'increased':(diff<0?'decreased':'unchanged');
        }
        rows.push({status:status, matchedBy:matchedBy, code:np.code||op.code||null, name:np.name||op.name||null,
          sheet:np.sheet||op.sheet||null, oldPrice:oldPrice, newPrice:newPrice, diff:diff, pct:pct});
      } else {
        rows.push({status:'added', matchedBy:null, code:np.code||null, name:np.name||null, sheet:np.sheet||null,
          oldPrice:null, newPrice:np.price, diff:null, pct:null});
      }
    });
    oldProducts.forEach(function(op, idx){
      if(matchedOld[idx]) return;
      rows.push({status:'removed', matchedBy:null, code:op.code||null, name:op.name||null, sheet:op.sheet||null,
        oldPrice:op.price, newPrice:null, diff:null, pct:null});
    });
    var summary={total:rows.length, increased:0, decreased:0, unchanged:0, added:0, removed:0, price_added:0, price_removed:0};
    rows.forEach(function(r){ summary[r.status]=(summary[r.status]||0)+1; });
    return {rows:rows, summary:summary};
  }

  // ---------- vendor list ----------

  function loadVendors(){
    return apiGet('action=vendors').then(function(data){ state.vendors = data.vendors; });
  }

  function renderVendorList(){
    var ul=$('vendorList'); ul.innerHTML='';
    state.vendors.forEach(function(v){
      var li=document.createElement('li');
      li.className = v.id===state.currentVendorId ? 'active' : '';
      var meta = v.snapshotCount ? (v.snapshotCount+' ჩამონათვალი · '+escapeHtml(v.lastSnapshotLabel||'')) : 'ჯერ არ აქვს ფასები';
      li.innerHTML = escapeHtml(v.name)+'<span class="meta">'+meta+'</span>';
      li.addEventListener('click', function(){ selectVendor(v.id); });
      ul.appendChild(li);
    });
  }

  $('addVendorBtn').addEventListener('click', function(){
    var input=$('newVendorName'); var name=input.value.trim();
    if(!name) return;
    apiPost({action:'create_vendor', name:name}).then(function(data){
      input.value='';
      return loadVendors().then(function(){ renderVendorList(); selectVendor(data.vendor.id); });
    }).catch(function(e){ alert(e.message); });
  });
  $('newVendorName').addEventListener('keydown', function(e){ if(e.key==='Enter') $('addVendorBtn').click(); });

  function selectVendor(id){
    if(!id) return;
    state.currentVendorId=id; state.upload=null; state.compare=null;
    $('emptyState').hidden=true; $('vendorPanel').hidden=false;
    $('mappingArea').innerHTML=''; $('uploadStatus').textContent='';
    renderVendorList();
    loadVendorDetail(id);
    switchTab('upload');
  }

  function loadVendorDetail(id){
    apiGet('action=vendor&id='+encodeURIComponent(id)).then(function(data){
      $('vendorTitle').textContent = data.vendor.name;
      state.snapshots = data.snapshots;
      populateCompareSelects();
      renderHistory();
    }).catch(function(e){ alert(e.message); });
  }

  $('deleteVendorBtn').addEventListener('click', function(){
    if(!state.currentVendorId) return;
    if(!confirm('წავშალო ეს ვენდორი და მისი ყველა ჩამონათვალი?')) return;
    apiPost({action:'delete_vendor', id:state.currentVendorId}).then(function(){
      state.currentVendorId=null;
      $('vendorPanel').hidden=true; $('emptyState').hidden=false;
      return loadVendors();
    }).then(renderVendorList).catch(function(e){ alert(e.message); });
  });

  // ---------- tabs ----------

  document.querySelectorAll('.tab-btn').forEach(function(btn){
    btn.addEventListener('click', function(){ switchTab(btn.dataset.tab); });
  });
  function switchTab(tab){
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.toggle('active', b.dataset.tab===tab); });
    document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.toggle('active', p.id==='tab-'+tab); });
  }

  // ---------- upload & mapping ----------

  $('uploadBtn').addEventListener('click', function(){
    var fileInput=$('fileInput'); var statusEl=$('uploadStatus');
    if(!fileInput.files.length){ statusEl.textContent='აირჩიეთ ფაილი'; statusEl.className='status-line error'; return; }
    var file=fileInput.files[0];
    statusEl.textContent='ვამუშავებ ფაილს... დიდი ფაილისთვის შეიძლება რამდენიმე წამი დასჭირდეს.';
    statusEl.className='status-line';
    parseWorkbookFile(file).then(function(rawSheets){
      var vendor = state.vendors.filter(function(v){return v.id===state.currentVendorId;})[0];
      var templates = (vendor && vendor.mappingTemplates) || {};
      var sheetInfos = rawSheets.map(function(s){
        var previewRows = s.rows.slice(0,10);
        var headerRowIdx = guessHeaderRowIndex(previewRows);
        var headerRow = previewRows[headerRowIdx] || [];
        var guess = guessColumnRoles(headerRow);
        var saved = templates[s.name];
        var effectiveHeaderRow = saved ? saved.headerRow : headerRowIdx;
        var hasData = s.rowCount > effectiveHeaderRow+1;
        var looksDuplicate = !saved && /worksheet/i.test(s.name);
        return {
          name:s.name, rowCount:s.rowCount, rows:s.rows,
          headerRow: effectiveHeaderRow,
          codeCol: saved ? saved.codeCol : guess.codeCol,
          nameCol: saved ? saved.nameCol : guess.nameCol,
          priceCol: saved ? saved.priceCol : guess.priceCol,
          priceOptions: guess.priceOptions,
          include: hasData && !looksDuplicate,
          fromTemplate: !!saved
        };
      });
      if(!sheetInfos.some(function(s){return s.include;})){
        sheetInfos.forEach(function(s){ s.include = s.rowCount > s.headerRow+1; });
      }
      state.upload = { originalName: file.name, sheets: sheetInfos };
      statusEl.textContent = 'ფაილი დამუშავდა: '+sheetInfos.length+' ფურცელი. გადახედეთ რუკირებას და დააჭირეთ "დადასტურება".';
      statusEl.className='status-line ok';
      renderMappingArea();
    }).catch(function(e){
      statusEl.textContent = 'შეცდომა ფაილის დამუშავებისას: '+e.message;
      statusEl.className='status-line error';
    });
  });

  function columnOptionsHtml(sheet, selectedCol){
    var headerRow = sheet.rows[sheet.headerRow] || [];
    var max = maxColsForSheet(sheet);
    var html = '<option value="">— არ არის —</option>';
    for(var c=1;c<=max;c++){
      var label=(headerRow[c-1]||'').toString().trim() || '(ცარიელი)';
      var sel = c===selectedCol ? 'selected' : '';
      html += '<option value="'+c+'" '+sel+'>'+colLetter(c)+': '+escapeHtml(label.slice(0,36))+'</option>';
    }
    return html;
  }
  function headerRowOptionsHtml(sheet){
    var html='';
    sheet.rows.slice(0,10).forEach(function(row, idx){
      var preview = row.filter(function(c){return String(c).trim();}).slice(0,4).join(' | ');
      var sel = idx===sheet.headerRow ? 'selected' : '';
      html += '<option value="'+idx+'" '+sel+'>მწკრივი '+(idx+1)+': '+escapeHtml(preview.slice(0,55))+'</option>';
    });
    return html;
  }
  function previewTableHtml(sheet){
    var max = Math.min(maxColsForSheet(sheet),14);
    var html = '<div class="preview-wrap"><table class="preview-table"><tbody>';
    sheet.rows.slice(0,10).forEach(function(row, idx){
      var cls = idx===sheet.headerRow ? 'hdr' : '';
      html += '<tr>';
      for(var c=0;c<max;c++){ html += '<td class="'+cls+'">'+escapeHtml((row[c]===undefined?'':row[c]).toString().slice(0,28))+'</td>'; }
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function renderMappingArea(){
    var area=$('mappingArea'); area.innerHTML='';
    if(!state.upload) return;
    state.upload.sheets.forEach(function(sheet, idx){
      var card=document.createElement('div'); card.className='sheet-card';
      card.innerHTML =
        '<div class="sheet-card-header">'+
          '<label class="checkbox-row"><input type="checkbox" data-role="include" '+(sheet.include?'checked':'')+'/> ჩავრთო</label>'+
          '<span class="sheet-name">'+escapeHtml(sheet.name)+'</span>'+
          '<span class="row-count">'+sheet.rowCount+' მწკრივი'+(sheet.fromTemplate?' · შენახული რუკირება':'')+'</span>'+
          '<button class="ghost copy-mapping-btn" data-role="copy">რუკირების კოპირება ყველაზე</button>'+
        '</div>'+
        '<div class="mapping-row">'+
          '<label>სათაურის მწკრივი <select data-role="headerRow">'+headerRowOptionsHtml(sheet)+'</select></label>'+
          '<label>კოდი / SKU <select data-role="codeCol">'+columnOptionsHtml(sheet, sheet.codeCol)+'</select></label>'+
          '<label>დასახელება <select data-role="nameCol">'+columnOptionsHtml(sheet, sheet.nameCol)+'</select></label>'+
          '<label>ფასი (შესადარებელი) <select data-role="priceCol">'+columnOptionsHtml(sheet, sheet.priceCol)+'</select></label>'+
        '</div>'+
        previewTableHtml(sheet);

      card.querySelector('[data-role="include"]').addEventListener('change', function(e){ sheet.include=e.target.checked; });
      card.querySelector('[data-role="headerRow"]').addEventListener('change', function(e){ sheet.headerRow=parseInt(e.target.value,10); renderMappingArea(); });
      card.querySelector('[data-role="codeCol"]').addEventListener('change', function(e){ sheet.codeCol=e.target.value?parseInt(e.target.value,10):null; });
      card.querySelector('[data-role="nameCol"]').addEventListener('change', function(e){ sheet.nameCol=e.target.value?parseInt(e.target.value,10):null; });
      card.querySelector('[data-role="priceCol"]').addEventListener('change', function(e){ sheet.priceCol=e.target.value?parseInt(e.target.value,10):null; });
      card.querySelector('[data-role="copy"]').addEventListener('click', function(){
        state.upload.sheets.forEach(function(other, j){
          if(j===idx) return;
          other.headerRow=sheet.headerRow; other.codeCol=sheet.codeCol; other.nameCol=sheet.nameCol; other.priceCol=sheet.priceCol;
        });
        renderMappingArea();
      });
      area.appendChild(card);
    });

    var bar=document.createElement('div'); bar.className='import-bar card';
    bar.innerHTML =
      '<label class="checkbox-row"><input type="checkbox" id="saveAsTemplate" checked/> რუკირების დამახსოვრება ამ ვენდორისთვის</label>'+
      '<button id="confirmImportBtn" class="primary">დადასტურება და შენახვა ჩამონათვალად</button>';
    area.appendChild(bar);
    $('confirmImportBtn').addEventListener('click', confirmImport);
  }

  function confirmImport(){
    var statusEl=$('uploadStatus');
    var label=$('snapshotLabel').value.trim();
    var saveAsTemplate=$('saveAsTemplate').checked;
    var sheets=state.upload.sheets;
    if(!sheets.some(function(s){return s.include;})){ statusEl.textContent='ერთი ფურცელი მაინც აირჩიეთ.'; statusEl.className='status-line error'; return; }
    if(sheets.some(function(s){return s.include && !s.priceCol;})){ statusEl.textContent='ყველა ჩართულ ფურცელს უნდა ჰქონდეს ფასის სვეტი.'; statusEl.className='status-line error'; return; }

    statusEl.textContent='ვიმპორტებ და ვინახავ...'; statusEl.className='status-line';
    var extracted = extractProducts(sheets, sheets);
    var products = extracted.products, warnings = extracted.warnings;
    var sheetsIncluded = sheets.filter(function(s){return s.include;}).map(function(s){return s.name;});

    var payload = { action:'import_snapshot', vendorId: state.currentVendorId, label: label, sheetsIncluded: sheetsIncluded, products: products };
    if(saveAsTemplate){
      var newTemplates={};
      sheets.filter(function(s){return s.include;}).forEach(function(s){
        newTemplates[s.name] = {headerRow:s.headerRow, codeCol:s.codeCol, nameCol:s.nameCol, priceCol:s.priceCol};
      });
      payload.mappingTemplates = newTemplates;
    }

    apiPost(payload).then(function(result){
      statusEl.textContent = 'შენახულია: "'+result.snapshot.label+'" — '+result.snapshot.productCount+' პროდუქტი.' + (warnings.length ? ' გაფრთხილება: '+warnings.join('; ') : '');
      statusEl.className='status-line ok';
      state.upload=null; $('mappingArea').innerHTML=''; $('fileInput').value=''; $('snapshotLabel').value='';
      return loadVendors().then(function(){ renderVendorList(); loadVendorDetail(state.currentVendorId); });
    }).catch(function(e){
      statusEl.textContent = 'შეცდომა შენახვისას: '+e.message;
      statusEl.className='status-line error';
    });
  }

  // ---------- compare ----------

  function populateCompareSelects(){
    var sorted = state.snapshots.slice().sort(function(a,b){ return b.createdAt.localeCompare(a.createdAt); });
    var fromSel=$('fromSelect'), toSel=$('toSelect');
    function opt(s){ return '<option value="'+s.id+'">'+escapeHtml(s.label)+' ('+s.productCount+' პროდუქტი)</option>'; }
    fromSel.innerHTML = sorted.map(opt).join('');
    toSel.innerHTML = sorted.map(opt).join('');
    if(sorted.length>=2){ toSel.value=sorted[0].id; fromSel.value=sorted[1].id; }
    $('compareSummary').innerHTML=''; $('compareTableWrap').innerHTML='';
    $('tableControls').hidden=true; $('exportBtn').hidden=true;
    state.compare=null;
  }

  $('compareBtn').addEventListener('click', function(){
    var from=$('fromSelect').value, to=$('toSelect').value;
    if(!from||!to) return alert('აირჩიეთ ორივე ჩამონათვალი');
    if(from===to) return alert('აირჩიეთ ორი განსხვავებული ჩამონათვალი');
    var fromMeta = state.snapshots.filter(function(s){return s.id===from;})[0];
    var toMeta = state.snapshots.filter(function(s){return s.id===to;})[0];
    var vid=state.currentVendorId;
    $('compareBtn').disabled=true;
    Promise.all([
      apiGet('action=snapshot_products&vendorId='+encodeURIComponent(vid)+'&snapshotId='+encodeURIComponent(from)),
      apiGet('action=snapshot_products&vendorId='+encodeURIComponent(vid)+'&snapshotId='+encodeURIComponent(to))
    ]).then(function(results){
      var result = compareSnapshots(results[0].products, results[1].products);
      state.compare = { rows: result.rows, summary: result.summary, from: fromMeta, to: toMeta };
      renderSummary(result.summary);
      populateSheetFilter(result.rows);
      renderCompareTable();
      $('tableControls').hidden=false; $('exportBtn').hidden=false;
    }).catch(function(e){ alert('შედარება ვერ მოხერხდა: '+e.message); })
      .then(function(){ $('compareBtn').disabled=false; });
  });

  $('exportBtn').addEventListener('click', function(){
    if(!state.compare) return;
    var rows = getFilteredSortedRows();
    var aoa = [['სტატუსი','კატეგორია','კოდი','დასახელება','ძველი ფასი','ახალი ფასი','სხვაობა','% ცვლილება']];
    rows.forEach(function(r){
      aoa.push([STATUS_LABELS[r.status]||r.status, r.sheet||'', r.code||'', r.name||'',
        r.oldPrice===null?'':r.oldPrice, r.newPrice===null?'':r.newPrice, r.diff===null?'':r.diff, r.pct===null?'':r.pct]);
    });
    var ws = XLSX.utils.aoa_to_sheet(aoa);
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'შედარება');
    var arr = XLSX.write(wb, {bookType:'xlsx', type:'array'});
    var blob = new Blob([arr], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
    var fname = 'comparison-'+(state.compare.from.label||'old')+'-vs-'+(state.compare.to.label||'new')+'.xlsx';
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = fname;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
  });

  function renderSummary(summary){
    var cards=[
      {key:'total', label:'სულ', cls:''},
      {key:'increased', label:'ფასი გაიზარდა', cls:'row-increased'},
      {key:'decreased', label:'ფასი დაეცა', cls:'row-decreased'},
      {key:'added', label:'ახალი', cls:'row-added'},
      {key:'removed', label:'მოხსნილი', cls:'row-removed'},
      {key:'unchanged', label:'უცვლელი', cls:''}
    ];
    $('compareSummary').innerHTML = cards.map(function(c){
      return '<div class="summary-card '+c.cls+'"><span class="num">'+(summary[c.key]||0)+'</span>'+c.label+'</div>';
    }).join('');
  }

  function populateSheetFilter(rows){
    var sheets = Array.from(new Set(rows.map(function(r){return r.sheet;}).filter(Boolean))).sort();
    $('sheetFilter').innerHTML = '<option value="">ყველა კატეგორია</option>' + sheets.map(function(s){
      return '<option value="'+escapeHtml(s)+'">'+escapeHtml(s)+'</option>';
    }).join('');
  }

  var sortState={key:'pct', dir:'desc'};
  function getFilteredSortedRows(){
    if(!state.compare) return [];
    var search=$('searchInput').value.trim().toLowerCase();
    var statusF=$('statusFilter').value, sheetF=$('sheetFilter').value;
    var rows = state.compare.rows.filter(function(r){
      if(statusF && r.status!==statusF) return false;
      if(sheetF && r.sheet!==sheetF) return false;
      if(search){ var hay=((r.name||'')+' '+(r.code||'')).toLowerCase(); if(hay.indexOf(search)===-1) return false; }
      return true;
    });
    var key=sortState.key;
    rows = rows.slice().sort(function(a,b){
      var av=a[key], bv=b[key];
      if(av===null||av===undefined) av=-Infinity;
      if(bv===null||bv===undefined) bv=-Infinity;
      if(typeof av==='string') return sortState.dir==='asc' ? av.localeCompare(bv) : bv.localeCompare(av);
      return sortState.dir==='asc' ? av-bv : bv-av;
    });
    return rows;
  }

  function renderCompareTable(){
    var wrap=$('compareTableWrap'); var rows=getFilteredSortedRows();
    function fmt(n){ return (n===null||n===undefined) ? '—' : Number(n).toLocaleString('ka-GE',{maximumFractionDigits:2}); }
    function fmtPct(n){ return (n===null||n===undefined) ? '—' : ((n>0?'+':'')+n.toFixed(1)+'%'); }
    var html = '<table class="compare-table"><thead><tr>'+
      '<th data-sort="status">სტატუსი</th><th data-sort="sheet">კატეგორია</th><th data-sort="code">კოდი</th>'+
      '<th data-sort="name">დასახელება</th><th data-sort="oldPrice">ძველი ფასი</th><th data-sort="newPrice">ახალი ფასი</th>'+
      '<th data-sort="diff">სხვაობა</th><th data-sort="pct">% ცვლილება</th></tr></thead><tbody>';
    rows.forEach(function(r){
      html += '<tr class="row-'+r.status+'"><td>'+(STATUS_LABELS[r.status]||r.status)+'</td><td>'+escapeHtml(r.sheet||'')+'</td>'+
        '<td>'+escapeHtml(r.code||'')+'</td><td>'+escapeHtml(r.name||'')+'</td><td>'+fmt(r.oldPrice)+'</td>'+
        '<td>'+fmt(r.newPrice)+'</td><td>'+fmt(r.diff)+'</td><td>'+fmtPct(r.pct)+'</td></tr>';
    });
    html += '</tbody></table><p class="muted" style="margin-top:8px;">'+rows.length+' მწკრივი ნაჩვენებია</p>';
    wrap.innerHTML = html;
    wrap.querySelectorAll('th[data-sort]').forEach(function(th){
      th.addEventListener('click', function(){
        var key=th.dataset.sort;
        if(sortState.key===key) sortState.dir = sortState.dir==='asc'?'desc':'asc';
        else { sortState.key=key; sortState.dir='desc'; }
        renderCompareTable();
      });
    });
  }

  ['searchInput','statusFilter','sheetFilter'].forEach(function(id){
    $(id).addEventListener('input', function(){ if(state.compare) renderCompareTable(); });
  });

  // ---------- history ----------

  function renderHistory(){
    var el=$('historyList');
    if(!state.snapshots.length){ el.innerHTML='<p class="muted">ჯერ არ არის ატვირთული ჩამონათვალი.</p>'; return; }
    var sorted = state.snapshots.slice().sort(function(a,b){ return b.createdAt.localeCompare(a.createdAt); });
    el.innerHTML = sorted.map(function(s){
      return '<div class="history-item"><div><strong>'+escapeHtml(s.label)+'</strong>'+
        '<div class="meta">'+new Date(s.createdAt).toLocaleString('ka-GE')+' · '+s.productCount+' პროდუქტი · '+(s.sheetsIncluded||[]).length+' ფურცელი</div></div>'+
        '<button class="small-danger" data-id="'+s.id+'">წაშლა</button></div>';
    }).join('');
    el.querySelectorAll('.small-danger').forEach(function(btn){
      btn.addEventListener('click', function(){
        if(!confirm('წავშალო ეს ჩამონათვალი?')) return;
        apiPost({action:'delete_snapshot', vendorId: state.currentVendorId, snapshotId: btn.dataset.id}).then(function(){
          return loadVendors().then(function(){ renderVendorList(); loadVendorDetail(state.currentVendorId); });
        }).catch(function(e){ alert(e.message); });
      });
    });
  }

  // ---------- init ----------

  loadVendors().then(renderVendorList).catch(function(e){
    $('emptyState').innerHTML = '<p>ვენდორების სია ვერ ჩაიტვირთა: '+escapeHtml(e.message)+'</p>';
  });
})();
