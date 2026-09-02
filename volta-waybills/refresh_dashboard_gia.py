# -*- coding: utf-8 -*-
"""
Copy of refresh_dashboard.py that sources the CRM side from VoltaStoreDB
("Volta Database Gia's" in MySQL Workbench — the new Volta database
replacing myvolta.info) instead of myvolta.info. Everything else — RS.ge
waybill/invoice fetching, the reconciliation algorithm, geography
classification, logistics status — is untouched, reused directly from
refresh_dashboard.py.

Field mapping (verified against a known ground-truth case, see
volta_voltastoredb_schema.md memory):
  Instalment_ID -> orders.my_volta_installment_id
  PID           -> customers.id_number, via orders.customer_id when present
                    (76% of orders), else via orders.customer_email =
                    customers.email (guest orders, resolves ~30% of those)
  Order_Date    -> orders.crm_order_date
  Full_Cost     -> orders.grand_total
  Manager_Name  -> crm_users (via orders.crm_sales_manager_id)
  Product_Name  -> order_items.name (concatenated if an order has >1 item —
                    the old DB was always exactly one product per row, the
                    new schema allows more)

Real, unavoidable data gap: ~17% of orders (mostly guest checkouts with no
matching customer record at all) have no discoverable PID anywhere in this
schema. Per user's explicit decision (2026-09-01): these are EXCLUDED from
the CRM sales pulled here entirely, not just left unmatched — they never
enter reconciliation. A one-off list of them was exported separately
(export script not kept; re-derive from this same query's HAVING clause,
inverted, if asked again).
"""
import sys
from pathlib import Path

import pymysql

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

# Reuse everything except CRM fetching from the myvolta.info pipeline.
from refresh_dashboard import (
    START, fetch_waybills, build_waybill_rows, fetch_invoices, build_invoice_rows,
    build_reconciliation, build_geo_summary, load_logistics_status,
)
import json
from datetime import datetime

from config import GIA_DB_HOST, GIA_DB_PORT, GIA_DB_USER, GIA_DB_PASS, GIA_DB_NAME


def fetch_crm_sales_gia():
    conn = pymysql.connect(
        host=GIA_DB_HOST, port=GIA_DB_PORT, user=GIA_DB_USER, password=GIA_DB_PASS,
        database=GIA_DB_NAME, connect_timeout=15,
    )
    cur = conn.cursor(pymysql.cursors.DictCursor)
    cur.execute("""
        SELECT
            o.my_volta_installment_id AS Instalment_ID,
            ANY_VALUE(COALESCE(c1.id_number, c2.id_number)) AS PID,
            ANY_VALUE(COALESCE(
                NULLIF(TRIM(CONCAT(c1.first_name, ' ', c1.last_name)), ''),
                NULLIF(TRIM(CONCAT(c2.first_name, ' ', c2.last_name)), ''),
                CONCAT(o.customer_first_name, ' ', o.customer_last_name)
            )) AS FullName,
            ANY_VALUE(o.crm_order_date) AS Order_Date,
            ANY_VALUE(o.grand_total) AS Full_Cost,
            ANY_VALUE(NULLIF(TRIM(CONCAT(cu.first_name, ' ', cu.last_name)), '')) AS Manager_Name,
            GROUP_CONCAT(DISTINCT oi.name SEPARATOR '; ') AS Product_Name
        FROM orders o
        LEFT JOIN customers c1 ON o.customer_id = c1.id
        LEFT JOIN customers c2 ON o.customer_id IS NULL AND o.customer_email = c2.email
        LEFT JOIN crm_users cu ON o.crm_sales_manager_id = cu.id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE o.crm_order_status = 5
          AND o.crm_order_date >= %s
          AND o.my_volta_installment_id IS NOT NULL
        GROUP BY o.id
        HAVING PID IS NOT NULL AND PID <> ''
    """, (START,))
    rows = cur.fetchall()
    conn.close()
    # Instalment_ID needs to be an int downstream (case-id display, joins) —
    # it comes back as a string from my_volta_installment_id (varchar).
    for r in rows:
        r["Instalment_ID"] = int(r["Instalment_ID"])
        r["Manager_Name"] = r["Manager_Name"] or "—"
        r["Product_Name"] = r["Product_Name"] or "—"
    return rows


def main():
    print(f"[{datetime.now()}] fetching waybills from RS.ge...", file=sys.stderr)
    raw_wb = fetch_waybills()
    wb_rows, skipped = build_waybill_rows(raw_wb)
    print(f"waybills: fetched {len(raw_wb)}, kept {len(wb_rows)}, skipped {skipped} unnumbered", file=sys.stderr)

    print(f"[{datetime.now()}] fetching invoices from RS.ge...", file=sys.stderr)
    raw_inv = fetch_invoices()
    inv_rows = build_invoice_rows(raw_inv)
    print(f"invoices: fetched {len(inv_rows)}", file=sys.stderr)

    print(f"[{datetime.now()}] fetching CRM sales from VoltaStoreDB (Gia's)...", file=sys.stderr)
    crm_rows = fetch_crm_sales_gia()
    print(f"CRM sales (crm_order_status=5, PID resolvable): {len(crm_rows)}", file=sys.stderr)

    logistics_status = load_logistics_status()
    print(f"logistics status snapshot: {len(logistics_status)} case_ids", file=sys.stderr)
    recon = build_reconciliation(crm_rows, raw_wb, logistics_status)
    s = recon["summary"]
    print(f"reconciliation (person-level): matched {s['matchedPeople']}, "
          f"missing waybill {s['missingWbPeople']} people ({s['riskAmountWb']:.0f} GEL), "
          f"missing sale {s['missingSalePeople']} people ({s['riskAmountSale']:.0f} GEL)", file=sys.stderr)

    geo = build_geo_summary(wb_rows)
    print(f"geography: {geo['tbilisiTotal']} თბილისი, {geo['regionsTotal']} რეგიონები, "
          f"{geo['noAddressCount']} მისამართის გარეშე, {geo['otherCount']} დაუზუსტებელი "
          f"(სულ {geo['total']})", file=sys.stderr)

    wb_json = json.dumps(wb_rows, ensure_ascii=False, separators=(",", ":"))
    inv_json = json.dumps(inv_rows, ensure_ascii=False, separators=(",", ":"))
    recon_json = json.dumps(recon, ensure_ascii=False, separators=(",", ":"), default=str)
    geo_json = json.dumps(geo, ensure_ascii=False, separators=(",", ":"))

    template = (HERE / "template.html").read_text(encoding="utf-8")
    out_html = (template
                .replace("__DATA_WB__", wb_json)
                .replace("__DATA_INV__", inv_json)
                .replace("__DATA_RECON__", recon_json)
                .replace("__DATA_GEO__", geo_json)
                .replace("ვოლტას სავაჭრო რეესტრები", "ვოლტას სავაჭრო რეესტრები (Gia's DB)")
                .replace("Volta Trade Registers", "Volta Trade Registers (Gia's DB)"))
    out_path = HERE / "waybill_dashboard_gia.html"
    out_path.write_text(out_html, encoding="utf-8")
    print("wrote", out_path, f"({len(out_html)} bytes)", file=sys.stderr)


if __name__ == "__main__":
    main()
