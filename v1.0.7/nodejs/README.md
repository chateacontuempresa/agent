# Agente Node.js — Chatea con tu Empresa

Agente WebSocket completo para servidores con Node.js 18+. Ideal para VPS, Railway, Render, Heroku, o cualquier servidor Linux/Mac/Windows.

## Requisitos

- Node.js 18 o superior
- npm 9+
- Acceso a tu base de datos (MySQL, PostgreSQL, SQLite, o SQL Server)

## Bases de datos soportadas

| BD | Paquete | Driver |
|---|---|---|
| MySQL / MariaDB | `mysql2` | incluido |
| PostgreSQL | `pg` | incluido |
| SQL Server | `mssql` | incluido |
| SQLite | `better-sqlite3` | incluido |

## Instalación

```bash
# 1. Instalar dependencias
npm install

# 2. Copiar y editar configuración
cp config.example.json config.json
nano config.json

# 3. Ejecutar el agente
npm start
```

## Configuración (`config.json`)

```json
{
  "saas_url": "https://app.chateacontuempresa.com",
  "token": "cce_agent_XXXXXXXXXXXXXXXXXX",
  "db_type": "mysql",
  "db_host": "localhost",
  "db_port": 3306,
  "db_name": "mi_base_de_datos",
  "db_user": "usuario",
  "db_pass": "contraseña"
}
```

Valores de `db_type`: `mysql`, `postgres`, `mssql`, `sqlite`

## Ejecutar como servicio (PM2)

```bash
# Instalar PM2
npm install -g pm2

# Iniciar el agente
pm2 start agent.js --name cce-agent

# Guardar y auto-arrancar
pm2 save
pm2 startup
```

## Variables de entorno (alternativa)

Puedes usar variables de entorno en lugar de `config.json`:

```bash
export CCE_SAAS_URL="https://app.chateacontuempresa.com"
export CCE_TOKEN="cce_agent_xxx"
export CCE_DB_TYPE="mysql"
# etc.
node agent.js
```
