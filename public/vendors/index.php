<!DOCTYPE html>
<html lang="ka">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title data-i18n="pageTitle">ვენდორების ფასების შედარება</title>
<style>
:root{
  --bg:#f4f5f7; --surface:#ffffff; --surface-2:#eef0f3; --fg:#1a1a34; --muted:#6b6f80; --border:#dde1e8;
  --navy:#1a1a34; --navy-ink:#ffffff; --lime:#c2ff00; --lime-ink:#1a1a34;
  --inc-bg:#fde2e2; --inc-fg:#8a1f1f; --dec-bg:#e1f5e3; --dec-fg:#1f6b2b;
  --add-bg:#e3ecfd; --add-fg:#1f3f8a; --rem-bg:#eef0f3; --rem-fg:#5a5f6e;
}
@media (prefers-color-scheme: dark){
  :root:not([data-theme="light"]){
    --bg:#13131f; --surface:#1d1d2e; --surface-2:#242438; --fg:#e8e9f3; --muted:#9a9db0; --border:#34354a;
    --navy:#242438; --navy-ink:#f1f2fb;
    --inc-bg:#3a1f1f; --inc-fg:#ff9b9b; --dec-bg:#1f3a24; --dec-fg:#8fe3a0;
    --add-bg:#1f2b4a; --add-fg:#9db8ff; --rem-bg:#2a2a3d; --rem-fg:#aeb0c4;
  }
}
:root[data-theme="dark"]{
  --bg:#13131f; --surface:#1d1d2e; --surface-2:#242438; --fg:#e8e9f3; --muted:#9a9db0; --border:#34354a;
  --navy:#242438; --navy-ink:#f1f2fb;
  --inc-bg:#3a1f1f; --inc-fg:#ff9b9b; --dec-bg:#1f3a24; --dec-fg:#8fe3a0;
  --add-bg:#1f2b4a; --add-fg:#9db8ff; --rem-bg:#2a2a3d; --rem-fg:#aeb0c4;
}
*{box-sizing:border-box;}
body{margin:0; font-family:'Segoe UI',system-ui,-apple-system,sans-serif; background:var(--bg); color:var(--fg);}
.app{display:flex; gap:20px; max-width:1300px; margin:0 auto; padding:20px; min-height:80vh;}
.sidebar{width:260px; flex-shrink:0; background:var(--navy); color:var(--navy-ink); border-radius:14px; padding:16px; align-self:flex-start;}
.sidebar h1{font-size:16px; margin:0 0 4px; color:var(--lime);}
.sidebar .sub{font-size:11px; opacity:.7; margin:0 0 14px;}
.top-right-controls{position:fixed; top:14px; right:14px; z-index:100; display:flex; align-items:center; gap:6px; background:var(--navy); color:var(--navy-ink); padding:6px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.18);}
.icon-btn{background:rgba(255,255,255,.08); border:none; color:inherit; width:28px; height:28px; border-radius:7px; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0;}
.icon-btn svg{width:15px; height:15px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round;}
.lang-switch{display:flex; background:rgba(255,255,255,.08); border-radius:7px; padding:2px; gap:2px;}
.lang-switch button{background:none; border:none; color:inherit; opacity:.65; font-size:11px; font-weight:600; padding:4px 7px; border-radius:5px; cursor:pointer;}
.lang-switch button[aria-pressed="true"]{background:var(--lime); color:var(--lime-ink); opacity:1;}
.add-vendor{display:flex; gap:6px; margin-bottom:14px;}
.add-vendor input{flex:1; min-width:0; padding:7px 8px; border-radius:6px; border:1px solid #3a3a5a; background:rgba(255,255,255,.08); color:inherit; font-size:13px;}
.add-vendor button{background:var(--lime); color:var(--lime-ink); border:none; border-radius:6px; padding:7px 10px; font-weight:600; cursor:pointer; font-size:13px;}
.vendor-list{list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:3px; max-height:60vh; overflow-y:auto;}
.vendor-list li{padding:9px 10px; border-radius:8px; cursor:pointer; font-size:13px;}
.vendor-list li:hover{background:rgba(255,255,255,.08);}
.vendor-list li.active{background:var(--lime); color:var(--lime-ink); font-weight:600;}
.vendor-list .meta{display:block; font-size:10px; opacity:.7; margin-top:1px;}
.main{flex:1; min-width:0;}
.card{background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:18px; margin-bottom:16px;}
.muted{color:var(--muted); font-size:13px;}
.empty-state{text-align:center; margin-top:60px; color:var(--muted);}
.vendor-header{display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; flex-wrap:wrap;}
.vendor-header h2{margin:0; font-size:20px;}
.danger-link{background:none; border:none; color:#c43b3b; cursor:pointer; font-size:12px; text-decoration:underline; padding:0;}
.tabs{display:flex; gap:4px; border-bottom:2px solid var(--border); margin-bottom:16px; flex-wrap:wrap;}
.tab-btn{background:none; border:none; padding:9px 14px; font-size:13px; cursor:pointer; color:var(--muted); border-bottom:2px solid transparent; margin-bottom:-2px;}
.tab-btn.active{color:var(--fg); border-bottom-color:var(--lime); font-weight:600;}
.tab-panel{display:none;}
.tab-panel.active{display:block;}
.upload-box{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
.upload-box input[type="text"]{padding:8px; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--fg); min-width:200px; font-size:13px;}
input[type="file"]{color:var(--fg); font-size:13px;}
button.primary{background:var(--navy); color:var(--navy-ink); border:none; padding:9px 15px; border-radius:7px; cursor:pointer; font-size:13px; font-weight:600;}
button.primary:disabled{opacity:.5; cursor:not-allowed;}
button.ghost{background:var(--surface-2); color:var(--fg); border:1px solid var(--border); padding:7px 12px; border-radius:7px; cursor:pointer; font-size:12px;}
.status-line{margin:10px 0; font-size:13px; color:var(--muted);}
.status-line.error{color:#c43b3b;}
.status-line.ok{color:var(--dec-fg);}
.sheet-card{background:var(--surface-2); border:1px solid var(--border); border-radius:10px; padding:14px; margin:12px 0;}
.sheet-card-header{display:flex; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap; font-size:13px;}
.sheet-card-header .sheet-name{font-weight:600;}
.sheet-card-header .row-count{color:var(--muted); font-size:11px;}
.mapping-row{display:flex; gap:14px; flex-wrap:wrap; align-items:center; margin-bottom:8px;}
.mapping-row label{display:flex; flex-direction:column; font-size:11px; color:var(--muted); gap:3px;}
.mapping-row select{padding:6px; border-radius:6px; border:1px solid var(--border); background:var(--surface); color:var(--fg); min-width:200px; font-size:12px; max-width:280px;}
.preview-wrap{overflow-x:auto; margin-top:8px;}
.preview-table{border-collapse:collapse; font-size:11px; min-width:100%;}
.preview-table td{border:1px solid var(--border); padding:3px 6px; white-space:nowrap;}
.preview-table td.hdr{background:var(--lime); color:var(--lime-ink);}
.copy-mapping-btn{font-size:11px; padding:5px 9px;}
.import-bar{display:flex; align-items:center; gap:12px; margin-top:10px; flex-wrap:wrap;}
.checkbox-row{display:flex; align-items:center; gap:6px; font-size:13px;}
.compare-controls{display:flex; gap:14px; align-items:end; margin-bottom:16px; flex-wrap:wrap;}
.compare-controls label{display:flex; flex-direction:column; gap:4px; font-size:11px; color:var(--muted);}
.compare-controls select{padding:7px; border-radius:6px; border:1px solid var(--border); background:var(--surface); color:var(--fg); min-width:200px; font-size:13px;}
.summary-cards{display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;}
.summary-card{padding:10px 14px; border-radius:10px; min-width:100px; font-size:12px; border:1px solid var(--border);}
.summary-card .num{font-size:20px; font-weight:700; display:block; font-variant-numeric:tabular-nums;}
.table-controls{display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap;}
.table-controls input,.table-controls select{padding:7px; border-radius:6px; border:1px solid var(--border); background:var(--surface); color:var(--fg); font-size:13px;}
.table-controls input{min-width:220px;}
.table-scroll{overflow-x:auto;}
.compare-table{width:100%; border-collapse:collapse; font-size:12px; min-width:680px;}
.compare-table th{background:var(--navy); color:var(--navy-ink); padding:7px 9px; text-align:left; position:sticky; top:0; cursor:pointer; white-space:nowrap;}
.compare-table td{padding:6px 9px; border-bottom:1px solid var(--border); font-variant-numeric:tabular-nums;}
.row-increased{background:var(--inc-bg); color:var(--inc-fg);}
.row-decreased{background:var(--dec-bg); color:var(--dec-fg);}
.row-added,.row-price_added{background:var(--add-bg); color:var(--add-fg);}
.row-removed,.row-price_removed{background:var(--rem-bg); color:var(--rem-fg);}
.row-unchanged{background:var(--surface); color:var(--fg);}
.history-item{display:flex; justify-content:space-between; align-items:center; background:var(--surface-2); border:1px solid var(--border); border-radius:8px; padding:11px 14px; margin-bottom:7px; font-size:13px; flex-wrap:wrap; gap:8px;}
.history-item.open{border-color:var(--lime); box-shadow:0 0 0 1px var(--lime) inset;}
.history-item-main{cursor:pointer; flex:1; min-width:0;}
.history-item .meta{font-size:11px; color:var(--muted);}
.small-danger{background:none; border:none; color:#c43b3b; cursor:pointer; font-size:11px; text-decoration:underline; padding:0;}
.banner{background:var(--surface-2); border:1px solid var(--border); border-radius:10px; padding:12px 16px; font-size:13px; margin-bottom:16px;}
@media (max-width:760px){
  .app{flex-direction:column;}
  .sidebar{width:100%;}
  .vendor-list{max-height:none; flex-direction:row; overflow-x:auto; flex-wrap:nowrap;}
  .vendor-list li{white-space:nowrap;}
  .top-right-controls{top:10px; right:10px;}
}
</style>
<script>
(function(){
  try {
    var theme = localStorage.getItem('vendorToolTheme');
    if (theme === 'light' || theme === 'dark') document.documentElement.setAttribute('data-theme', theme);
  } catch (e) {}
})();
</script>
</head>
<body>

<div class="top-right-controls">
  <div id="langSwitch" class="lang-switch">
    <button data-lang="ka" aria-pressed="true">ქართ</button>
    <button data-lang="en" aria-pressed="false">EN</button>
  </div>
  <button id="themeToggle" class="icon-btn" title="თემის შეცვლა"><svg id="themeToggleIcon" viewBox="0 0 24 24"></svg></button>
</div>

<div class="app">
  <aside class="sidebar">
    <h1 data-i18n="sidebarTitle">ვენდორების ფასები</h1>
    <p class="sub" data-i18n="sidebarSub">ძველი ↔ ახალი ფასების შედარება</p>
    <div class="add-vendor">
      <input id="newVendorName" type="text" data-i18n-placeholder="newVendorPlaceholder" placeholder="ახალი ვენდორი" />
      <button id="addVendorBtn" data-i18n="addVendorBtn">+ დამატება</button>
    </div>
    <ul id="vendorList" class="vendor-list"></ul>
  </aside>

  <main class="main">
    <div id="emptyState" class="card empty-state">
      <p data-i18n="emptyState">აირჩიეთ ვენდორი მარცხნივ, ან დაამატეთ ახალი.</p>
    </div>

    <div id="vendorPanel" hidden>
      <div class="vendor-header">
        <h2 id="vendorTitle"></h2>
        <button id="deleteVendorBtn" class="danger-link" data-i18n="deleteVendorBtn">ვენდორის წაშლა</button>
      </div>

      <nav class="tabs">
        <button class="tab-btn active" data-tab="history" data-i18n="tabHistory">ისტორია</button>
        <button class="tab-btn" data-tab="upload" data-i18n="tabUpload">ფასების ატვირთვა</button>
        <button class="tab-btn" data-tab="compare" data-i18n="tabCompare">შედარება</button>
        <button class="tab-btn" data-tab="siteprice" data-i18n="tabSitePrice">საიტის ფასთან შედარება</button>
      </nav>

      <section id="tab-upload" class="tab-panel">
        <div class="card">
          <div class="upload-box">
            <input id="fileInput" type="file" accept=".xlsx,.xlsm" />
            <input id="snapshotLabel" type="text" data-i18n-placeholder="snapshotLabelPlaceholder" placeholder="ჩამონათვალის სახელი (მაგ: 2026-10-27)" />
            <button id="uploadBtn" class="primary" data-i18n="uploadBtn">ატვირთვა და გაანალიზება</button>
          </div>
          <div id="uploadStatus" class="status-line"></div>
        </div>
        <div id="mappingArea"></div>
      </section>

      <section id="tab-compare" class="tab-panel">
        <div class="card">
          <div class="compare-controls">
            <label><span data-i18n="fromSnapshotLabel">ძველი ჩამონათვალი</span> <select id="fromSelect"></select></label>
            <label><span data-i18n="toSnapshotLabel">ახალი ჩამონათვალი</span> <select id="toSelect"></select></label>
            <button id="compareBtn" class="primary" data-i18n="compareBtn">შედარება</button>
            <button id="exportBtn" class="ghost" hidden data-i18n="exportBtn">Excel-ში ექსპორტი</button>
          </div>
          <div id="compareSummary" class="summary-cards"></div>
          <div class="table-controls" id="tableControls" hidden>
            <input id="searchInput" type="text" data-i18n-placeholder="searchPlaceholder" placeholder="ძებნა დასახელებით ან კოდით..." />
            <select id="statusFilter">
              <option value="" data-i18n="statusAll">ყველა სტატუსი</option>
              <option value="increased" data-i18n="status_increased">ფასი გაიზარდა</option>
              <option value="decreased" data-i18n="status_decreased">ფასი დაეცა</option>
              <option value="added" data-i18n="status_added">ახალი პოზიცია</option>
              <option value="removed" data-i18n="status_removed">მოხსნილი პოზიცია</option>
              <option value="unchanged" data-i18n="status_unchanged">უცვლელი</option>
            </select>
            <select id="sheetFilter"><option value="" data-i18n="categoryAll">ყველა კატეგორია</option></select>
          </div>
          <div class="table-scroll"><div id="compareTableWrap"></div></div>
        </div>
      </section>

      <section id="tab-siteprice" class="tab-panel">
        <div class="card">
          <div class="compare-controls">
            <label><span data-i18n="siteCompareSnapshotLabel">ჩამონათვალი</span> <select id="siteSnapshotSelect"></select></label>
            <button id="siteCompareBtn" class="primary" data-i18n="compareBtn">შედარება</button>
            <button id="siteExportBtn" class="ghost" hidden data-i18n="exportBtn">Excel-ში ექსპორტი</button>
          </div>
          <div id="siteCompareSummary" class="summary-cards"></div>
          <div class="table-controls" id="siteTableControls" hidden>
            <input id="siteSearchInput" type="text" data-i18n-placeholder="searchPlaceholder" placeholder="ძებნა დასახელებით ან კოდით..." />
            <select id="siteStatusFilter">
              <option value="" data-i18n="statusAll">ყველა სტატუსი</option>
              <option value="match" data-i18n="status_site_match">ემთხვევა</option>
              <option value="diff" data-i18n="status_site_diff">განსხვავდება</option>
              <option value="notfound" data-i18n="status_site_notfound">ვერ მოიძებნა საიტზე</option>
            </select>
          </div>
          <div class="table-scroll"><div id="siteCompareTableWrap"></div></div>
        </div>
      </section>

      <section id="tab-history" class="tab-panel active">
        <div class="card"><div id="historyList"></div></div>
        <div id="historyViewCard" class="card" hidden>
          <div class="vendor-header">
            <h2 id="historyViewTitle" style="font-size:16px;"></h2>
            <button id="historyViewClose" class="ghost">×</button>
          </div>
          <div class="table-controls">
            <input id="historyViewSearch" type="text" data-i18n-placeholder="searchPlaceholder" placeholder="ძებნა დასახელებით ან კოდით..." />
          </div>
          <div class="table-scroll"><div id="historyViewTableWrap"></div></div>
        </div>
      </section>
    </div>
  </main>
</div>

<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="app.js?v=<?= @filemtime(__DIR__ . '/app.js') ?: time() ?>"></script>
</body>
</html>
