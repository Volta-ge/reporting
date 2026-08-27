# Template for config.py — copy this file to config.py and fill in real values.
# config.py is gitignored and must never be committed.

# RS.ge WayBillService + ntosservice (invoices) — su format is "{service-user}:{TIN}",
# NOT the portal login username. A dedicated RS.ge "service user" must be created in
# the RS.ge cabinet under "ქვე-მომხმარებლის უფლებების მართვა" first.
SU = "SERVICE_USER:TIN"
SP = "SERVICE_USER_PASSWORD"
INVOICE_USER_ID = 0  # returned by the RS.ge auth/chek call for this service user
INVOICE_UN_ID = 0    # returned by the RS.ge auth/chek call for this service user

# Volta's MySQL DB, read-only analysis user.
DB_HOST = "myvolta.info"
DB_PORT = 3306
DB_USER = "your_readonly_user"
DB_PASS = "your_password"
DB_NAME = "myvolta8_voltadb"
