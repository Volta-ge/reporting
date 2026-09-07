# Writes daily_mail_xlsx.json (from build_daily_mail_xlsx.js) to an .xlsx via Excel COM, applying the
# Volta_Analytics spreadsheet palette per row class. Excel evaluates the formulas itself on save.
param([string]$JsonPath, [string]$OutPath)
$json = Get-Content -Path $JsonPath -Encoding UTF8 -Raw | ConvertFrom-Json

function Bgr([string]$hex) { $r=[Convert]::ToInt32($hex.Substring(1,2),16); $g=[Convert]::ToInt32($hex.Substring(3,2),16); $b=[Convert]::ToInt32($hex.Substring(5,2),16); return $r + ($g * 256) + ($b * 65536) }
$fills = @{
  'title'          = @{ bg = (Bgr '#9dc3e6'); ink = (Bgr '#10253a'); bold = $true }
  'colhead'        = @{ bg = (Bgr '#ddebf7'); ink = (Bgr '#111111'); bold = $true }
  'section'        = @{ bg = (Bgr '#ffd966'); ink = (Bgr '#111111'); bold = $true }
  'section-strong' = @{ bg = (Bgr '#ffc000'); ink = (Bgr '#111111'); bold = $true }
  'peach'          = @{ bg = (Bgr '#fce4d6'); ink = (Bgr '#111111'); bold = $false }
  'peach-strong'   = @{ bg = (Bgr '#f4b183'); ink = (Bgr '#111111'); bold = $false }
  'green'          = @{ bg = (Bgr '#e2efda'); ink = (Bgr '#111111'); bold = $false }
  'plain'          = @{ bg = (Bgr '#ffffff'); ink = (Bgr '#111111'); bold = $false }
  'footer'         = @{ bg = (Bgr '#ffffff'); ink = (Bgr '#6b6b6b'); bold = $false }
}
$noteBg = Bgr '#fff2cc'
$borderColor = Bgr '#c9c9c9'

$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false
$wb = $excel.Workbooks.Add()
while ($wb.Worksheets.Count -gt 1) { $wb.Worksheets.Item($wb.Worksheets.Count).Delete() }

$first = $true
foreach ($sheet in $json.sheets) {
  if ($first) { $ws = $wb.Worksheets.Item(1); $first = $false } else { $ws = $wb.Worksheets.Add([Type]::Missing, $wb.Worksheets.Item($wb.Worksheets.Count)) }
  $ws.Name = $sheet.name
  $maxCols = 1
  foreach ($row in $sheet.rows) { if ($row.cells.Count -gt $maxCols) { $maxCols = $row.cells.Count } }
  $r = 0
  foreach ($row in $sheet.rows) {
    $r++
    $style = $fills[$row.cls]
    $rowRange = $ws.Range($ws.Cells.Item($r, 1), $ws.Cells.Item($r, $maxCols))
    $rowRange.Interior.Color = $style.bg
    $rowRange.Font.Color = $style.ink
    $rowRange.Font.Bold = $style.bold
    $rowRange.Font.Name = 'Arial'
    $rowRange.Font.Size = 9
    $c = 0
    foreach ($cellDef in $row.cells) {
      $c++
      if ($null -eq $cellDef) { continue }
      $cell = $ws.Cells.Item($r, $c)
      if ($cellDef.PSObject.Properties.Name -contains 'f') {
        $cell.Formula = [string]$cellDef.f
      } else {
        $v = $cellDef.v
        if ($v -is [string]) { $cell.NumberFormat = '@'; $cell.Value2 = [string]$v } else { $cell.Value2 = [double]$v }
      }
      if ($cellDef.t -eq 'p') { $cell.NumberFormat = '0.0%' }
      elseif ($cellDef.t -eq 'n') { $cell.NumberFormat = '#,##0' }
      if ($cellDef.PSObject.Properties.Name -contains 'note' -and $cellDef.note) { $cell.Interior.Color = $noteBg; $cell.WrapText = $true; $cell.HorizontalAlignment = -4131 }
      if ($cellDef.PSObject.Properties.Name -contains 'dash' -and $cellDef.dash) { $cell.HorizontalAlignment = -4152; $cell.Font.Color = (Bgr '#6b6b6b') }
      if ($cellDef.t -ne 's') { $cell.HorizontalAlignment = -4152 }
    }
    if ($row.PSObject.Properties.Name -contains 'span' -and $row.span -gt 1) {
      $m = $ws.Range($ws.Cells.Item($r, 1), $ws.Cells.Item($r, $row.span)); $m.Merge() | Out-Null; $m.HorizontalAlignment = -4131
      if ($row.cls -eq 'footer') { $m.WrapText = $true; $ws.Rows.Item($r).RowHeight = 60 }
    }
    if ($row.PSObject.Properties.Name -contains 'pairSpans' -and $row.pairSpans) {
      for ($k = 2; $k -le $maxCols; $k += 2) { $m = $ws.Range($ws.Cells.Item($r, $k), $ws.Cells.Item($r, $k + 1)); $m.Merge() | Out-Null; $m.HorizontalAlignment = -4108 }
    }
  }
  $used = $ws.Range($ws.Cells.Item(1, 1), $ws.Cells.Item($r, $maxCols))
  $used.Borders.LineStyle = 1; $used.Borders.Weight = 2; $used.Borders.Color = $borderColor
  $i = 0
  foreach ($w in $sheet.widths) { $i++; $ws.Columns.Item($i).ColumnWidth = $w }
  $ws.Activate() | Out-Null
  if ($sheet.PSObject.Properties.Name -contains 'freezeCol') {
    $excel.ActiveWindow.FreezePanes = $false
    $excel.ActiveWindow.SplitColumn = 1; $excel.ActiveWindow.SplitRow = 4; $excel.ActiveWindow.FreezePanes = $true
  } else {
    $excel.ActiveWindow.FreezePanes = $false
    $excel.ActiveWindow.SplitColumn = 0; $excel.ActiveWindow.SplitRow = 3; $excel.ActiveWindow.FreezePanes = $true
  }
  $excel.ActiveWindow.DisplayGridlines = $false
}
$wb.Worksheets.Item(1).Activate() | Out-Null
$excel.Calculate()
$wb.SaveAs($OutPath, 51)
$wb.Close($false)
$excel.Quit()
[System.Runtime.Interopservices.Marshal]::ReleaseComObject($excel) | Out-Null
Write-Output "Saved: $OutPath"
