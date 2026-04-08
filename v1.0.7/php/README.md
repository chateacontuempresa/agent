# Agente PHP — Hosting Compartido

Archivo PHP único que actúa como agente de base de datos para hosting compartido (cPanel, Plesk, etc.). Incluye interfaz web para configuración y cron job para ejecución automática.

## Requisitos

- PHP 7.4 o superior
- Extensiones: `pdo`, `pdo_mysql` (y/o `pdo_pgsql`, `pdo_sqlite`)
- Extensión `curl` habilitada
- Acceso a cron jobs (cPanel Cron Jobs)

## Instalación

1. Sube `agent.php` a tu hosting (ej: `https://tudominio.com/cce-agent/agent.php`)
2. Abre el archivo en el navegador
3. Completa el formulario de configuración:
   - **URL del SaaS**: `https://app.chateacontuempresa.com` (o tu dominio)
   - **Token del agente**: `cce_agent_xxxxx` (obtenido del panel admin)
   - **Tipo de BD**: MySQL, PostgreSQL o SQLite
   - **Credenciales de BD**: host, puerto, nombre, usuario, contraseña
4. Haz clic en "Guardar y Conectar"
5. Configura el cron job en cPanel:

```
* * * * * php /home/tuusuario/public_html/cce-agent/agent.php run 2>/dev/null
```

O con URL (si no tienes acceso CLI):
```
* * * * * curl -s "https://tudominio.com/cce-agent/agent.php?action=run&secret=TU_CRON_SECRET" > /dev/null
```

## Cómo funciona

```
cPanel Cron (cada minuto)
       │
       ▼
  agent.php (modo run)
       │
       ├─ POST /api/http-agent/auth    → Autenticación con gateway vía SaaS
       ├─ POST /api/http-agent/schema  → Sincronización del esquema (si cambió)
       ├─ GET  /api/http-agent/poll    → Espera hasta 55s por una consulta
       │        (long-poll)
       ├─ Ejecuta SQL en MySQL/PG/SQLite local
       └─ POST /api/http-agent/result  → Devuelve resultado al gateway
```

## Seguridad

- El archivo de configuración (`agent-config.json`) se crea junto al `agent.php`
- Protege el directorio con `.htaccess` si es necesario:
  ```apache
  # .htaccess
  <Files "agent-config.json">
    Deny from all
  </Files>
  ```
- El Cron Secret evita que terceros disparen el agente vía URL
