#!/usr/bin/env python3
"""
Chatea con tu Empresa — Agente Python
Uso: python agent.py [--config config.json]
"""

import asyncio
import hashlib
import json
import os
import sys
import argparse
from pathlib import Path

try:
    import websockets
except ImportError:
    print("[ERROR] Instala las dependencias: pip install -r requirements.txt")
    sys.exit(1)

from document_sync import DocumentSyncManager

VERSION = "1.0.1"
# Alineado con LARGE_READ_CAP en el SaaS (lecturas masivas / CSV).
AGENT_READ_CAP = 100_000

# ─── Config ───────────────────────────────────────────────────────────────────

parser = argparse.ArgumentParser(description="Chatea con tu Empresa — Agente Python")
parser.add_argument("--config", default="config.json", help="Ruta al archivo de configuración")
args = parser.parse_args()

config_path = Path(args.config)
if not config_path.exists():
    print(f"[ERROR] Archivo de configuración no encontrado: {config_path}")
    print("Copia config.example.json a config.json y edítalo.")
    sys.exit(1)

with open(config_path, "r") as f:
    config = json.load(f)

SAAS_URL = config.get("saas_url", "").rstrip("/")
TOKEN = config.get("token", "")
DB_TYPE = config.get("db_type", "mysql")
DB_HOST = config.get("db_host", "localhost")
DB_PORT = config.get("db_port", 3306)
DB_NAME = config.get("db_name", "")
DB_USER = config.get("db_user", "")
DB_PASS = config.get("db_pass", "")
DOCUMENT_SYNC_POLL_MIN = float(config.get("document_sync_poll_minutes", 5) or 5)

if not SAAS_URL or not TOKEN:
    print("[ERROR] saas_url y token son requeridos en config.json")
    sys.exit(1)

# ─── Database ─────────────────────────────────────────────────────────────────

def get_db_connection():
    if DB_TYPE == "mysql":
        try:
            import mysql.connector
            return mysql.connector.connect(
                host=DB_HOST, port=DB_PORT or 3306,
                database=DB_NAME, user=DB_USER, password=DB_PASS,
                charset="utf8mb4"
            )
        except ImportError:
            raise ImportError("mysql-connector-python no instalado. Ejecuta: pip install mysql-connector-python")

    if DB_TYPE == "postgres":
        try:
            import psycopg2
            return psycopg2.connect(
                host=DB_HOST, port=DB_PORT or 5432,
                dbname=DB_NAME, user=DB_USER, password=DB_PASS
            )
        except ImportError:
            raise ImportError("psycopg2-binary no instalado. Ejecuta: pip install psycopg2-binary")

    if DB_TYPE == "sqlite":
        import sqlite3
        return sqlite3.connect(DB_NAME)

    raise ValueError(f"Tipo de BD no soportado: {DB_TYPE}")

def execute_query(sql: str) -> list:
    conn = get_db_connection()
    try:
        if DB_TYPE == "mysql":
            cursor = conn.cursor(dictionary=True)
            cursor.execute(sql)
            rows = cursor.fetchall()
            cursor.close()
            conn.close()
            return rows
        if DB_TYPE == "postgres":
            import psycopg2.extras
            cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
            cursor.execute(sql)
            rows = [dict(r) for r in cursor.fetchall()]
            cursor.close()
            conn.close()
            return rows
        if DB_TYPE == "sqlite":
            import sqlite3
            conn.row_factory = sqlite3.Row
            cursor = conn.cursor()
            cursor.execute(sql)
            rows = [dict(r) for r in cursor.fetchall()]
            conn.close()
            return rows
    except Exception as e:
        try:
            conn.close()
        except Exception:
            pass
        raise e

def extract_schema() -> list:
    conn = get_db_connection()
    schema = []
    try:
        if DB_TYPE == "mysql":
            cursor = conn.cursor(dictionary=True)
            cursor.execute("""
                SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE as full_type,
                       COLUMN_COMMENT as column_comment,
                       IF(COLUMN_KEY='PRI',1,0) as is_primary,
                       IF(EXTRA LIKE '%%auto_increment%%',1,0) as is_identity,
                       IF(IS_NULLABLE='YES',1,0) as is_nullable
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s
                ORDER BY TABLE_NAME, ORDINAL_POSITION
            """, (DB_NAME,))
            schema = cursor.fetchall()
            cursor.close()
            conn.close()
        elif DB_TYPE == "postgres":
            import psycopg2.extras
            cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
            cursor.execute("""
                SELECT c.table_name AS "TABLE_NAME", c.column_name AS "COLUMN_NAME",
                       c.data_type AS "DATA_TYPE", c.data_type AS full_type, '' AS column_comment,
                       CASE WHEN pk.column_name IS NOT NULL THEN 1 ELSE 0 END AS is_primary,
                       CASE WHEN c.column_default LIKE 'nextval%%' THEN 1 ELSE 0 END AS is_identity,
                       CASE WHEN c.is_nullable='YES' THEN 1 ELSE 0 END AS is_nullable
                FROM information_schema.columns c
                LEFT JOIN (
                  SELECT ku.column_name, ku.table_name
                  FROM information_schema.table_constraints tc
                  JOIN information_schema.key_column_usage ku ON tc.constraint_name = ku.constraint_name
                  WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public'
                ) pk ON pk.column_name = c.column_name AND pk.table_name = c.table_name
                WHERE c.table_schema = 'public'
                ORDER BY c.table_name, c.ordinal_position
            """)
            schema = [dict(r) for r in cursor.fetchall()]
            cursor.close()
            conn.close()
        elif DB_TYPE == "sqlite":
            import sqlite3
            conn.row_factory = sqlite3.Row
            cursor = conn.cursor()
            cursor.execute("SELECT name FROM sqlite_master WHERE type='table'")
            tables = [r["name"] for r in cursor.fetchall()]
            for tname in tables:
                cursor.execute(f'PRAGMA table_info("{tname}")')
                for col in cursor.fetchall():
                    schema.append({
                        "TABLE_NAME": tname, "COLUMN_NAME": col["name"],
                        "DATA_TYPE": col["type"], "full_type": col["type"],
                        "column_comment": "", "is_primary": col["pk"],
                        "is_identity": 0, "is_nullable": 0 if col["notnull"] else 1,
                    })
            conn.close()
    except Exception as e:
        try:
            conn.close()
        except Exception:
            pass
        raise e
    return schema

def is_read_only_sql(sql: str) -> bool:
    import re
    sql = re.sub(r';+\s*$', '', str(sql or '')).strip()
    if not sql:
        return False
    parts = [p.strip() for p in sql.split(';') if p.strip()]
    if len(parts) > 1:
        return False
    if re.search(r'\b(DROP|DELETE|UPDATE|INSERT|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|RENAME|REPLACE|MERGE|CALL|EXEC|EXECUTE)\b', sql, re.IGNORECASE):
        return False
    return bool(re.match(r'^(SELECT|WITH)\b', sql, re.IGNORECASE))

def compute_schema_hash(schema: list) -> str:
    return hashlib.sha256(json.dumps(schema, ensure_ascii=False).encode()).hexdigest()

# ─── Bootstrap ────────────────────────────────────────────────────────────────

async def bootstrap():
    import urllib.request
    payload = json.dumps({
        "token": TOKEN,
        "platform": sys.platform,
        "appVersion": VERSION,
        "dbType": DB_TYPE,
        "databaseName": DB_NAME,
    }).encode()
    req = urllib.request.Request(
        f"{SAAS_URL}/api/agent/bootstrap",
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST"
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.load(resp)

# ─── Main Agent ───────────────────────────────────────────────────────────────

def _sync_log(level: str, msg: str) -> None:
    tag = {"error": "[ERROR]", "warn": "[WARN]", "success": "[Sync]", "info": "[Sync]"}.get(level, "[Sync]")
    print(f"{tag} {msg}")


doc_sync = DocumentSyncManager(_sync_log)


async def run_agent():
    print(f"\n================================================")
    print(f"  Chatea con tu Empresa — Agente Python v{VERSION}")
    print(f"================================================\n")

    # Bootstrap
    print("[CCE Agent] Obteniendo configuración del gateway...")
    boot = await bootstrap()
    ws_url = boot["gateway"]["wsUrl"]
    installation_id = boot["installation"]["id"]
    print(f"[CCE Agent] Gateway: {ws_url}")
    print(f"[CCE Agent] Installation: {installation_id}")

    # Extract schema
    print("[CCE Agent] Extrayendo esquema de la base de datos...")
    schema = extract_schema()
    schema_hash = compute_schema_hash(schema)
    print(f"[CCE Agent] Esquema: {len(schema)} columnas, hash: {schema_hash[:12]}...")

    async def connect():
        while True:
            try:
                print(f"[CCE Agent] Conectando a {ws_url}...")
                async with websockets.connect(ws_url, ping_interval=None) as ws:
                    heartbeat_interval = 30
                    heartbeat_task = None

                    # Auth
                    await ws.send(json.dumps({
                        "type": "agent.auth",
                        "token": TOKEN,
                        "installationId": installation_id,
                        "platform": sys.platform,
                        "appVersion": VERSION,
                        "dbType": DB_TYPE,
                        "databaseName": DB_NAME,
                        "schemaHash": schema_hash,
                    }))

                    async def heartbeat_loop():
                        while True:
                            await asyncio.sleep(heartbeat_interval)
                            try:
                                await ws.send(json.dumps({
                                    "type": "agent.heartbeat",
                                    "platform": sys.platform,
                                    "appVersion": VERSION,
                                    "dbType": DB_TYPE,
                                    "databaseName": DB_NAME,
                                }))
                            except Exception:
                                break

                    async for raw in ws:
                        msg = json.loads(raw)

                        if doc_sync.dispatch(msg):
                            continue

                        if msg["type"] == "agent.auth.ok":
                            heartbeat_interval = msg.get("heartbeatIntervalMs", 30000) / 1000
                            print("[CCE Agent] Autenticado correctamente.")
                            doc_sync.set_socket(ws, asyncio.get_running_loop())
                            await ws.send(json.dumps({
                                "type": "agent.schema.sync",
                                "installationId": installation_id,
                                "dbType": DB_TYPE,
                                "databaseName": DB_NAME,
                                "schemaHash": schema_hash,
                                "schema": schema,
                            }))
                            heartbeat_task = asyncio.create_task(heartbeat_loop())
                            continue

                        if msg["type"] == "agent.schema.sync.ok":
                            print(f"[CCE Agent] Esquema sincronizado: {msg.get('schemaHash','')[:12]}...")
                            doc_sync.start_mappings_poll(max(60.0, DOCUMENT_SYNC_POLL_MIN * 60.0))
                            continue

                        if msg["type"] in ("agent.sync.mappings", "agent.sync.mappings.update"):
                            doc_sync.apply_mappings(msg.get("mappings") or [])
                            continue

                        if msg["type"] == "agent.heartbeat.ok":
                            print(".", end="", flush=True)
                            continue

                        if msg["type"] == "agent.query.execute":
                            query_id = msg["queryId"]
                            sql = msg["sql"]
                            print(f"\n[CCE Agent] Query [{query_id}]: {sql[:80]}...")

                            if not is_read_only_sql(sql):
                                await ws.send(json.dumps({
                                    "type": "agent.query.result",
                                    "queryId": query_id,
                                    "success": False,
                                    "error": "Solo se permiten consultas SELECT.",
                                }))
                                continue

                            import re
                            final_sql = sql
                            if not re.search(r'\bLIMIT\b', sql, re.IGNORECASE) and not re.search(r'\bCOUNT\s*\(', sql, re.IGNORECASE):
                                final_sql = sql + f" LIMIT {AGENT_READ_CAP}"

                            try:
                                loop = asyncio.get_event_loop()
                                rows = await loop.run_in_executor(None, execute_query, final_sql)
                                # Convert non-serializable types
                                clean_rows = json.loads(json.dumps(rows, default=str))
                                print(f"[CCE Agent] {len(clean_rows)} filas devueltas.")
                                await ws.send(json.dumps({
                                    "type": "agent.query.result",
                                    "queryId": query_id,
                                    "success": True,
                                    "rows": clean_rows,
                                }))
                            except Exception as e:
                                print(f"[CCE Agent] Error: {e}")
                                await ws.send(json.dumps({
                                    "type": "agent.query.result",
                                    "queryId": query_id,
                                    "success": False,
                                    "error": str(e),
                                }))

            except Exception as e:
                doc_sync.clear_socket()
                print(f"\n[CCE Agent] Desconectado: {e}. Reconectando en 5s...")
                await asyncio.sleep(5)

    await connect()

if __name__ == "__main__":
    asyncio.run(run_agent())
