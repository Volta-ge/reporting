# Copy to config.sh (gitignored) and fill in the passwords. Read-only DB users are enough.
NEWDB_HOST='replica.ctywygswaaft.eu-central-1.rds.amazonaws.com'
NEWDB_USER='readonly_widgera'
NEWDB_PWD=''
NEWDB_NAME='VoltaStoreDB'
OLDDB_HOST='myvolta.info'
OLDDB_USER='myvolta8_analysis'
OLDDB_PWD=''
OLDDB_NAME='myvolta8_voltadb'
# mysql CLI; on Windows, MySQL Workbench 8.0 CE bundles one at this path
MYSQL_BIN='/c/Program Files/MySQL/MySQL Workbench 8.0 CE/mysql.exe'
