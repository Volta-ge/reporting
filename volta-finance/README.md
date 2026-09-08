# Volta_Finance — ფინანსური ანგარიშგება ორისის ბაზიდან

**საჯარო ბმული (GitHub Pages):** https://volta-ge.github.io/reporting/finance.html
Artifact (იგივე გვერდი claude.ai-ზე): https://claude.ai/code/artifact/d96a30f0-dfbf-45d9-9c8e-9c0c8477d56a
ამ საქაღალდეში `Volta_Finance.html` იგივე გვერდია (ბრაუზერში პირდაპირ იხსნება, ინტერნეტი მხოლოდ შრიფტებისთვის სჭირდება).

**ჩანართები:** მიმოხილვა · ბალანსი · მოგება-ზარალი · ბრუნვითი უწყისი · ფულადი ნაკადები · დებიტორები/კრედიტორები · ხარჯების ანალიზი.
ფილტრი: წელი + თვე (ან მთელი წელი), ენა ქარ/EN.

## განახლება ახალი ორისის ასლით

1. ორისის ასლი `VOLTA_ASLI_YYYY-MM-DD_....RAR` დადე მონაცემების საქაღალდეში: `D:\all\volta\Volta_Accounting\`
   (ეს საქაღალდე git-ში არ არის: RAR-ები, `extract/`, `oris.sqlite`, `dash_data.json` იქ რჩება).
2. გაუშვი (~6 წუთი):

       python tools\refresh.py

   ავტომატურად იღებს ყველაზე ახალ RAR-ს: unrar → `tools/export_sqlite.py` (TPS → SQLite) → `tools/build_data.py`
   (თვიური აგრეგატები, `dash_data.json`) → `tools/build_dashboard.py` (`dashboard_template.html` + JSON → `Volta_Finance.html`).
   წყაროს თარიღი ფაილის სახელიდან იწერება `source.json`-ში.
3. `git add volta-finance && git commit && git push` (perf/stream-dashboard) და Artifact-ის ხელახლა გამოქვეყნება იმავე ბმულზე (Claude-ს უთხარი „განაახლე“).
4. GitHub Pages-ის ასლი (`docs/finance.html` main ბრენჩზე) ყოველ დილით ავტომატურად განახლდება commit-ის შემდეგ
   (`C:/Users/Lenovo/Desktop/Volta_Waybills/build_docs_waybills.py`, სქედულერის ტასკი); მაშინვე გინდა — ეს სკრიპტი ხელით გაუშვი.

სხვა მონაცემთა საქაღალდე: `set VOLTA_FIN_DATA=...` გარემოს ცვლადით.

## მოთხოვნები
Python 3.12, `pip install construct==2.5.3 six` (ახალი construct არ მუშაობს tpsread-თან), WinRAR (`C:\Program Files\WinRAR\UnRAR.exe`).

## რა არის ორისის ბაზაში და როგორ ვკითხულობთ
- Clarion TopSpeed `.TPS` ფაილები; მკითხველი `tools/tpsread/` (github galler-alexander/tpsread, ერთი პატჩით) + `tools/oris.py`
  (ქართული ტექსტი ბაიტებში 0xC0.. ძველი ანბანური რიგით, არქაული ასოების ჩათვლით; Clarion თარიღები).
- `WIRING.TPS` = გატარებების ჟურნალი, `ACC_NAME.TPS` = ანგარიშთა გეგმა, `ACC_YEAR.TPS` = წლის საწყისი ნაშთები, `Rate.tps` = კურსები.

## საბუღალტრო წესები, რითაც ორისს თეთრამდე ვემთხვევით
- ორისი მოგება-ზარალს ყოველთვიურად ხურავს 5 3 30-ზე. ეს გატარებები გამორიცხულია; 5 3 30-ის ნაშთი = მისი სხვა გატარებები + 6–9 კლასების დაგროვილი ნაშთი.
- სავალუტო გატარებები (MONEY ვალუტაშია, CURS=0) ლარში გადადის Rate ცხრილის იმ დღის კურსით.
- B კლასი (ბალანსგარეშე დოკუმენტები) გამორიცხულია.
- ცნობილი განსხვავება ორისთან: 1,155 ₾ ერთ 2023 წლის სავალუტო ოპერაციაზე (1 2 ↔ 1 6), სხვა კურსით იყო ჩაწერილი.
