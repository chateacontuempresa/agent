# Agente Python — Chatea con tu Empresa

Agente WebSocket para servidores con Python 3.9+. Ideal para VPS, servidores Linux, macOS, o cualquier entorno con Python.

## Requisitos

- Python 3.9 o superior
- pip

## Bases de datos soportadas

| BD | Paquete pip |
|---|---|
| MySQL / MariaDB | `mysql-connector-python` |
| PostgreSQL | `psycopg2-binary` |
| SQLite | Incluido en Python |

## Instalación

```bash
# 1. Instalar dependencias base
pip install websockets

# 2. Instalar driver de tu BD
pip install mysql-connector-python   # Para MySQL
pip install psycopg2-binary          # Para PostgreSQL
# SQLite no requiere instalación adicional

# 3. Copiar y editar configuración
cp config.example.json config.json
nano config.json

# 4. Ejecutar el agente
python agent.py
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

Valores de `db_type`: `mysql`, `postgres`, `sqlite`

## Ejecutar como servicio (systemd)

```ini
# /etc/systemd/system/cce-agent.service
[Unit]
Description=Chatea con tu Empresa Agent
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/cce-agent
ExecStart=/usr/bin/python3 /opt/cce-agent/agent.py --config /opt/cce-agent/config.json
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable cce-agent
systemctl start cce-agent
systemctl status cce-agent
```
