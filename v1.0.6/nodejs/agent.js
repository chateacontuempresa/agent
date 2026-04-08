#!/usr/bin/env node
/**
 * Chatea con tu Empresa — Agente Node.js
 *
 * Uso: node agent.js [--config config.json]
 *
 * Instalar dependencias: npm install
 * Configurar: cp config.example.json config.json && nano config.json
 */

import { createHash } from 'crypto';
import { readFileSync, existsSync } from 'fs';
import WebSocket from 'ws';
import mysql from 'mysql2/promise';
import pg from 'pg';
import mssql from 'mssql';
import Database from 'better-sqlite3';
import { fileURLToPath } from 'url';
import path from 'path';

const { Client: PgClient } = pg;

const VERSION = '1.0.0';
const __dirname = path.dirname(fileURLToPath(import.meta.url));

// ─── Config ──────────────────────────────────────────────────────────────────

const configArg = process.argv.find(a => a.startsWith('--config='))?.slice(9)
  || (process.argv.indexOf('--config') !== -1 ? process.argv[process.argv.indexOf('--config') + 1] : null)
  || 'config.json';

const configPath = path.resolve(__dirname, configArg);

if (!existsSync(configPath)) {
  console.error(`[ERROR] Archivo de configuración no encontrado: ${configPath}`);
  console.error('Copia config.example.json a config.json y edítalo.');
  process.exit(1);
}

const config = JSON.parse(readFileSync(configPath, 'utf8'));

const {
  saas_url,
  token,
  db_type = 'mysql',
  db_host = 'localhost',
  db_port,
  db_name,
  db_user,
  db_pass = '',
} = config;

if (!saas_url || !token) {
  console.error('[ERROR] saas_url y token son requeridos en config.json');
  process.exit(1);
}

// ─── Database ─────────────────────────────────────────────────────────────────

async function getDbConnection() {
  if (db_type === 'mysql') {
    return mysql.createConnection({
      host: db_host,
      port: db_port || 3306,
      database: db_name,
      user: db_user,
      password: db_pass,
      charset: 'utf8mb4',
    });
  }
  if (db_type === 'postgres') {
    const client = new PgClient({
      host: db_host,
      port: db_port || 5432,
      database: db_name,
      user: db_user,
      password: db_pass,
    });
    await client.connect();
    return client;
  }
  if (db_type === 'mssql') {
    await mssql.connect({
      server: db_host,
      port: db_port || 1433,
      database: db_name,
      user: db_user,
      password: db_pass,
      options: { encrypt: false, trustServerCertificate: true },
    });
    return mssql;
  }
  if (db_type === 'sqlite') {
    return new Database(db_name);
  }
  throw new Error(`Tipo de BD no soportado: ${db_type}`);
}

async function executeQuery(sql) {
  const conn = await getDbConnection();
  try {
    if (db_type === 'mysql') {
      const [rows] = await conn.execute(sql);
      await conn.end();
      return Array.isArray(rows) ? rows : [];
    }
    if (db_type === 'postgres') {
      const result = await conn.query(sql);
      await conn.end();
      return result.rows;
    }
    if (db_type === 'mssql') {
      const result = await conn.request().query(sql);
      await mssql.close();
      return result.recordset;
    }
    if (db_type === 'sqlite') {
      const stmt = conn.prepare(sql);
      const rows = stmt.all();
      conn.close();
      return rows;
    }
  } catch (err) {
    try {
      if (db_type === 'mysql') await conn.end();
      if (db_type === 'postgres') await conn.end();
      if (db_type === 'mssql') await mssql.close();
      if (db_type === 'sqlite') conn.close();
    } catch {}
    throw err;
  }
}

async function extractSchema() {
  const conn = await getDbConnection();
  let schema = [];
  try {
    if (db_type === 'mysql') {
      const [rows] = await conn.execute(`
        SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE as full_type,
               COLUMN_COMMENT as column_comment,
               IF(COLUMN_KEY='PRI',1,0) as is_primary,
               IF(EXTRA LIKE '%auto_increment%',1,0) as is_identity,
               IF(IS_NULLABLE='YES',1,0) as is_nullable
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION
      `, [db_name]);
      schema = rows;
      await conn.end();
    } else if (db_type === 'postgres') {
      const result = await conn.query(`
        SELECT c.table_name AS "TABLE_NAME", c.column_name AS "COLUMN_NAME",
               c.data_type AS "DATA_TYPE", c.data_type AS full_type, '' AS column_comment,
               CASE WHEN pk.column_name IS NOT NULL THEN 1 ELSE 0 END AS is_primary,
               CASE WHEN c.column_default LIKE 'nextval%' THEN 1 ELSE 0 END AS is_identity,
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
      `);
      schema = result.rows;
      await conn.end();
    } else if (db_type === 'mssql') {
      const result = await conn.request().query(`
        SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, c.DATA_TYPE as full_type,
               '' as column_comment,
               CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END as is_primary,
               COLUMNPROPERTY(OBJECT_ID(c.TABLE_NAME), c.COLUMN_NAME, 'IsIdentity') as is_identity,
               CASE WHEN c.IS_NULLABLE='YES' THEN 1 ELSE 0 END as is_nullable
        FROM INFORMATION_SCHEMA.COLUMNS c
        LEFT JOIN (
          SELECT ku.COLUMN_NAME, ku.TABLE_NAME
          FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
          JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku ON tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
          WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
        ) pk ON pk.COLUMN_NAME = c.COLUMN_NAME AND pk.TABLE_NAME = c.TABLE_NAME
        ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
      `);
      schema = result.recordset;
      await mssql.close();
    } else if (db_type === 'sqlite') {
      const tables = conn.prepare("SELECT name FROM sqlite_master WHERE type='table'").all();
      for (const t of tables) {
        const cols = conn.prepare(`PRAGMA table_info("${t.name}")`).all();
        for (const col of cols) {
          schema.push({
            TABLE_NAME: t.name, COLUMN_NAME: col.name,
            DATA_TYPE: col.type, full_type: col.type, column_comment: '',
            is_primary: col.pk, is_identity: 0,
            is_nullable: col.notnull ? 0 : 1,
          });
        }
      }
      conn.close();
    }
  } catch (err) {
    try {
      if (db_type === 'mysql') await conn.end();
      if (db_type === 'postgres') await conn.end();
      if (db_type === 'sqlite') conn.close();
    } catch {}
    throw err;
  }
  return schema;
}

function isReadOnlySql(sql) {
  sql = String(sql || '').replace(/;+\s*$/, '').trim();
  if (!sql) return false;
  const parts = sql.split(';').map(p => p.trim()).filter(Boolean);
  if (parts.length > 1) return false;
  if (/\b(DROP|DELETE|UPDATE|INSERT|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|RENAME|REPLACE|MERGE|CALL|EXEC|EXECUTE)\b/i.test(sql)) return false;
  return /^(SELECT|WITH)\b/i.test(sql);
}

function computeSchemaHash(schema) {
  return createHash('sha256').update(JSON.stringify(schema)).digest('hex');
}

// ─── Bootstrap ────────────────────────────────────────────────────────────────

async function bootstrap() {
  console.log('[CCE Agent] Obteniendo configuración del gateway...');
  const res = await fetch(`${saas_url.replace(/\/$/, '')}/api/agent/bootstrap`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      token,
      platform: process.platform,
      appVersion: VERSION,
      dbType: db_type,
      databaseName: db_name,
    }),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(`Bootstrap falló (${res.status}): ${err.error || res.statusText}`);
  }
  return res.json();
}

// ─── Main Agent Loop ──────────────────────────────────────────────────────────

async function startAgent() {
  let wsUrl;
  let installationId;
  let schemaHash;

  // Bootstrap
  try {
    const boot = await bootstrap();
    wsUrl = boot.gateway?.wsUrl;
    installationId = boot.installation?.id;
    console.log(`[CCE Agent] Gateway: ${wsUrl}`);
    console.log(`[CCE Agent] Installation: ${installationId}`);
  } catch (err) {
    console.error(`[CCE Agent] ${err.message}`);
    process.exit(1);
  }

  // Extract schema
  console.log('[CCE Agent] Extrayendo esquema de la base de datos...');
  let schema = [];
  try {
    schema = await extractSchema();
    schemaHash = computeSchemaHash(schema);
    console.log(`[CCE Agent] Esquema: ${schema.length} columnas, hash: ${schemaHash.slice(0, 12)}...`);
  } catch (err) {
    console.error(`[CCE Agent] Error extrayendo esquema: ${err.message}`);
  }

  // Connect WebSocket
  function connect() {
    console.log(`[CCE Agent] Conectando a ${wsUrl}...`);
    const ws = new WebSocket(wsUrl);
    let heartbeatTimer;
    let heartbeatIntervalMs = 30000;

    ws.on('open', () => {
      console.log('[CCE Agent] Conexión WebSocket establecida. Autenticando...');
      ws.send(JSON.stringify({
        type: 'agent.auth',
        token,
        installationId,
        platform: process.platform,
        appVersion: VERSION,
        dbType: db_type,
        databaseName: db_name,
        schemaHash,
      }));
    });

    ws.on('message', async (raw) => {
      let msg;
      try { msg = JSON.parse(raw.toString()); } catch { return; }

      if (msg.type === 'agent.auth.ok') {
        installationId = msg.installationId || installationId;
        heartbeatIntervalMs = msg.heartbeatIntervalMs || 30000;
        console.log('[CCE Agent] Autenticado correctamente.');

        // Sync schema
        ws.send(JSON.stringify({
          type: 'agent.schema.sync',
          installationId,
          dbType: db_type,
          databaseName: db_name,
          schemaHash,
          schema,
        }));

        // Start heartbeat
        heartbeatTimer = setInterval(() => {
          if (ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({
              type: 'agent.heartbeat',
              platform: process.platform,
              appVersion: VERSION,
              dbType: db_type,
              databaseName: db_name,
            }));
          }
        }, heartbeatIntervalMs);
        return;
      }

      if (msg.type === 'agent.schema.sync.ok') {
        console.log(`[CCE Agent] Esquema sincronizado: ${msg.schemaHash?.slice(0, 12)}...`);
        return;
      }

      if (msg.type === 'agent.heartbeat.ok') {
        process.stdout.write('.');
        return;
      }

      if (msg.type === 'agent.query.execute') {
        const { queryId, sql, timeoutMs = 30000 } = msg;
        console.log(`\n[CCE Agent] Query [${queryId}]: ${sql.slice(0, 80)}...`);

        if (!isReadOnlySql(sql)) {
          ws.send(JSON.stringify({ type: 'agent.query.result', queryId, success: false, error: 'Solo se permiten consultas SELECT.' }));
          return;
        }

        let finalSql = sql;
        if (!/\bLIMIT\b/i.test(sql) && !/\bCOUNT\s*\(/i.test(sql)) {
          finalSql = db_type === 'mssql' ? sql.replace(/^SELECT\b/i, 'SELECT TOP 500') : sql + ' LIMIT 500';
        }

        try {
          const rows = await Promise.race([
            executeQuery(finalSql),
            new Promise((_, reject) => setTimeout(() => reject(new Error('Query timeout')), timeoutMs)),
          ]);
          console.log(`[CCE Agent] ${rows.length} filas devueltas.`);
          ws.send(JSON.stringify({ type: 'agent.query.result', queryId, success: true, rows }));
        } catch (err) {
          console.error(`[CCE Agent] Error: ${err.message}`);
          ws.send(JSON.stringify({ type: 'agent.query.result', queryId, success: false, error: err.message }));
        }
        return;
      }
    });

    ws.on('close', (code, reason) => {
      clearInterval(heartbeatTimer);
      console.log(`\n[CCE Agent] Desconectado (${code}: ${reason}). Reconectando en 5s...`);
      setTimeout(connect, 5000);
    });

    ws.on('error', (err) => {
      console.error(`[CCE Agent] Error WebSocket: ${err.message}`);
    });
  }

  connect();
}

console.log(`\n================================================`);
console.log(`  Chatea con tu Empresa — Agente Node.js v${VERSION}`);
console.log(`================================================\n`);

startAgent().catch(err => {
  console.error('[CCE Agent] Error fatal:', err.message);
  process.exit(1);
});
